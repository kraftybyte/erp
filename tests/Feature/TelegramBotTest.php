<?php

namespace Tests\Feature;

use App\Livewire\TelegramConnection;
use App\Models\Advance;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Realisasi;
use App\Models\User;
use App\Services\RealisasiService;
use App\Services\Telegram\Draft;
use App\Services\Telegram\TelegramLinker;
use App\Settings\IntegrationSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Alur bot Telegram dari webhook sampai record ERP.
 *
 * API Telegram dan Gemini di-fake; database nyata dipakai lewat
 * DatabaseTransactions seperti tes lain di suite ini.
 */
class TelegramBotTest extends TestCase
{
    use DatabaseTransactions;

    private const SECRET = 'rahasia-tes-webhook';

    // Cukup untuk dikenali sebagai JPEG oleh finfo.
    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";

    private int $chatId = 990_000_001;

    private int $updateId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Di-fake, bukan dibaca dari database: isian Settings → Integrasi di
        // mesin lokal tidak boleh mengubah hasil tes.
        IntegrationSettings::fake([
            'gemini_api_key'          => 'gemini-tes',
            'gemini_model'            => 'gemini-3.5-flash-lite',
            'telegram_bot_token'      => '123456:TES',
            'telegram_webhook_secret' => self::SECRET,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // =================================================================
    // Keamanan & penghubungan akun
    // =================================================================

    public function test_webhook_menolak_secret_yang_salah(): void
    {
        $this->fakeApis();

        $this->postJson('/api/telegram/webhook', $this->textUpdate('expense 150rb grab'), [
            'X-Telegram-Bot-Api-Secret-Token' => 'tebakan',
        ])->assertForbidden();

        $this->postJson('/api/telegram/webhook', $this->textUpdate('expense 150rb grab'))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_link_start_menghubungkan_akun_dan_hanya_sekali_pakai(): void
    {
        $this->fakeApis();
        $user = $this->user(linked: false);

        $link  = app(TelegramLinker::class)->linkFor($user);
        $token = Str::after($link, 'start=');

        $this->assertStringStartsWith('https://t.me/erp_tes_bot?start=', $link);

        $this->send($this->textUpdate("/start {$token}"));

        $this->assertSame((string) $this->chatId, $user->fresh()->telegram_chat_id);
        $this->assertStringContainsString('Terhubung sebagai', $this->lastText());

        // Token yang sama dipakai chat lain: tidak boleh mengambil alih akun.
        $this->chatId = 990_000_002;
        $this->send($this->textUpdate("/start {$token}"));

        $this->assertSame('990000001', $user->fresh()->telegram_chat_id);
        $this->assertStringContainsString('kedaluwarsa', $this->lastText());
    }

    public function test_chat_yang_belum_terhubung_tidak_bisa_mengajukan(): void
    {
        $this->fakeApis(gemini: $this->aiResult(type: 'expense', amount: 150_000));
        $sebelum = Expense::count();

        $this->send($this->textUpdate('expense 150rb grab'));

        $this->assertStringContainsString('belum terhubung', $this->lastText());
        $this->assertNull(Draft::load($this->chatId));
        $this->assertSame($sebelum, Expense::count());
    }

    public function test_user_tanpa_izin_ditolak(): void
    {
        $this->fakeApis(gemini: $this->aiResult(type: 'expense', amount: 150_000));
        $this->user(permissions: []);

        $this->send($this->textUpdate('expense 150rb grab'));

        $this->assertStringContainsString('tidak punya izin', $this->lastText());
        $this->assertNull(Draft::load($this->chatId));
    }

    // =================================================================
    // Alur pengajuan
    // =================================================================

    public function test_pesan_dibaca_ai_lalu_dikirim_menjadi_expense_pending(): void
    {
        $user    = $this->user();
        $project = $this->project();

        $this->fakeApis(gemini: $this->aiResult(
            type: 'expense',
            amount: 150_000,
            description: 'Grab ke kantor klien',
            category: 'Transport',
            projectId: $project->id,
            date: today()->subDay()->toDateString(),
        ));

        $this->send($this->textUpdate('kemarin grab ke kantor klien 150rb'));

        $draft = Draft::load($this->chatId);
        $this->assertTrue($draft->isComplete());
        $this->assertStringContainsString('Rp 150.000', $this->lastText());
        $this->assertStringContainsString('PT Tes Telegram', $this->lastText());

        $this->send($this->tap("{$draft->id}:submit:"));

        $expense = Expense::where('user_id', $user->id)->latest('id')->first();
        $this->assertNotNull($expense);
        $this->assertSame('Pending', $expense->status);
        $this->assertEquals(150_000, $expense->amount);
        $this->assertSame('Grab ke kantor klien', $expense->keterangan_expense);
        $this->assertSame('Transport', $expense->category);
        $this->assertSame($project->id, $expense->project_id);
        $this->assertSame(today()->subDay()->toDateString(), $expense->submission_date->toDateString());

        $this->assertNull(Draft::load($this->chatId));
        $this->assertStringContainsString('terkirim', $this->lastText());
    }

    public function test_tanpa_ai_bot_menanyakan_yang_kurang_sampai_advance_terkirim(): void
    {
        $user    = $this->user();
        $project = $this->project();
        $this->fakeApis(gemini: null); // Gemini menolak, seperti saat saldo habis.

        $this->send($this->textUpdate('advance 2jt'));

        $draft = Draft::load($this->chatId);
        $this->assertSame('advance', $draft->type);
        $this->assertSame(2_000_000, $draft->amount);
        $this->assertSame('description', $draft->awaiting);

        $this->send($this->textUpdate('Beli properti shooting'));
        $this->assertSame('project', Draft::load($this->chatId)->awaiting);

        // Mengetik nama klien saat diminta project: satu yang cocok langsung terpilih.
        $this->send($this->textUpdate('Tes Telegram'));
        $this->assertSame($project->id, Draft::load($this->chatId)->projectId);
        $this->assertStringContainsString('kategori', $this->lastText());

        $this->send($this->tap("{$draft->id}:category:6")); // Project Support
        $this->assertStringContainsString('Draft Advance', $this->lastText());

        $this->send($this->tap("{$draft->id}:submit:"));

        $advance = Advance::where('user_id', $user->id)->latest('id')->first();
        $this->assertNotNull($advance);
        $this->assertSame(Advance::STATUS_PENDING, $advance->status);
        $this->assertEquals(2_000_000, $advance->amount);
        $this->assertSame('Beli properti shooting', $advance->keterangan_advance);
        $this->assertSame('Project Support', $advance->category);
        $this->assertSame($project->id, $advance->project_id);
    }

    public function test_hasil_ai_yang_tidak_valid_diabaikan(): void
    {
        $this->user();
        $this->fakeApis(gemini: $this->aiResult(
            type: 'expense',
            amount: -5,
            description: 'Makan siang tim',
            category: 'Makan',
            projectId: 999_999_999,
            date: today()->addDays(3)->toDateString(),
        ));

        $this->send($this->textUpdate('makan siang tim'));

        $draft = Draft::load($this->chatId);
        $this->assertNull($draft->amount);
        $this->assertNull($draft->projectId);
        $this->assertNull($draft->category);
        $this->assertSame(today()->toDateString(), $draft->date);
        $this->assertSame('amount', $draft->awaiting);
    }

    public function test_tombol_dari_draft_lama_tidak_membuat_pengajuan(): void
    {
        $user    = $this->user();
        $project = $this->project();
        $this->fakeApis(gemini: $this->aiResult(
            type: 'expense', amount: 150_000, description: 'Grab', category: 'Transport', projectId: $project->id,
        ));

        $this->send($this->textUpdate('expense 150rb grab'));
        $lama = Draft::load($this->chatId)->id;

        $this->send($this->textUpdate('expense 150rb grab lagi'));
        $this->assertNotSame($lama, Draft::load($this->chatId)->id);

        $this->send($this->tap("{$lama}:submit:"));

        $this->assertSame(0, Expense::where('user_id', $user->id)->count());
    }

    public function test_update_yang_dikirim_ulang_hanya_diproses_sekali(): void
    {
        $this->user();
        $this->fakeApis(gemini: $this->aiResult(type: 'expense', amount: 150_000));

        $update = $this->textUpdate('expense 150rb grab');
        $this->send($update);
        $this->send($update);

        $panggilanAi = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'generativelanguage'))
            ->count();

        $this->assertSame(1, $panggilanAi);
    }

    // =================================================================
    // Mengenali pesan tanpa AI
    // =================================================================

    public function test_contoh_pesan_langsung_jadi_ringkasan_tanpa_ai(): void
    {
        $this->user();
        $project = $this->project('PT Nestory Indonesia', 'Video Profile');
        $this->fakeApis(gemini: null);

        $this->send($this->textUpdate('expense 150rb grab ke kantor klien nestory'));

        $draft = Draft::load($this->chatId);
        $this->assertTrue($draft->isComplete(), 'Semua isian harus terbaca tanpa bertanya.');
        $this->assertSame('expense', $draft->type);
        $this->assertSame(150_000, $draft->amount);
        $this->assertSame('Transport', $draft->category);
        $this->assertSame($project->id, $draft->projectId);
        $this->assertStringContainsString('Draft Expense', $this->lastText());
    }

    public function test_salah_ketik_nama_klien_tetap_dikenali(): void
    {
        $this->user();
        $project = $this->project('PT Nestory Indonesia', 'Video Profile');
        $this->fakeApis(gemini: null);

        $this->send($this->textUpdate('bensin 50rb ke nestori'));

        $draft = Draft::load($this->chatId);
        $this->assertSame($project->id, $draft->projectId);
        $this->assertSame('expense', $draft->type, 'Tanpa kata advance, pesan bernominal dianggap expense.');
    }

    public function test_beberapa_project_yang_cocok_ditawarkan_sebagai_pilihan(): void
    {
        $this->user();
        $konten = $this->project('PT Zentrova Abadi', 'Konten Instagram');
        $video  = $this->project('PT Zentrova Abadi', 'Video Profile');
        $this->fakeApis(gemini: null);

        $this->send($this->textUpdate('grab 50rb ke zentrova'));

        $draft = Draft::load($this->chatId);
        $this->assertNull($draft->projectId);
        $this->assertEqualsCanonicalizing([$konten->id, $video->id], $draft->projectCandidates);
        $this->assertStringContainsString('Maksud kamu yang mana', $this->lastText());

        $this->send($this->tap("{$draft->id}:project:{$video->id}"));

        $draft = Draft::load($this->chatId);
        $this->assertSame($video->id, $draft->projectId);
        $this->assertSame([], $draft->projectCandidates);
        $this->assertStringContainsString('Draft Expense', $this->lastText());
    }

    public function test_project_yang_masih_berjalan_diutamakan(): void
    {
        $this->user();
        $this->project('PT Zentrova Abadi', 'Konten 2025', 'completed');
        $berjalan = $this->project('PT Zentrova Abadi', 'Konten 2026');
        $this->fakeApis(gemini: null);

        $this->send($this->textUpdate('grab 50rb ke zentrova'));

        $this->assertSame($berjalan->id, Draft::load($this->chatId)->projectId);
    }

    public function test_kata_umum_di_nama_project_tidak_dianggap_nama_klien(): void
    {
        $this->user();
        $this->project('Kantor Digital Media', 'Konten Video');
        $this->fakeApis(gemini: null);

        $this->send($this->textUpdate('grab 50rb ke kantor klien'));

        $draft = Draft::load($this->chatId);
        $this->assertNull($draft->projectId);
        $this->assertSame([], $draft->projectCandidates);
    }

    // =================================================================
    // Struk / invoice
    // =================================================================

    public function test_struk_dulu_lalu_keterangan_tersimpan_sebagai_lampiran_expense(): void
    {
        Storage::fake('public');
        $user    = $this->user();
        $project = $this->project('PT Nestory Indonesia', 'Video Profile');
        $this->fakeApis(gemini: null);

        $this->send($this->photoUpdate('struk-1'));

        $draft = Draft::load($this->chatId);
        $this->assertSame('details', $draft->awaiting);
        $this->assertSame('struk-1', $draft->attachment['file_id'], 'Ukuran foto terbesar yang dipakai.');
        $this->assertStringContainsString('Struk diterima', $this->lastText());

        $this->send($this->textUpdate('expense 150rb grab ke kantor klien nestory'));

        $draft = Draft::load($this->chatId);
        $this->assertTrue($draft->isComplete());
        $this->assertSame('struk-1', $draft->attachment['file_id']);
        $this->assertStringContainsString('foto struk', $this->lastText());

        $this->send($this->tap("{$draft->id}:submit:"));

        $expense = Expense::where('user_id', $user->id)->latest('id')->first();
        $this->assertNotNull($expense);
        $this->assertSame($project->id, $expense->project_id);
        $this->assertStringStartsWith('expense/invoice/', $expense->attachment);
        $this->assertStringEndsWith('.jpg', $expense->attachment);
        Storage::disk('public')->assertExists($expense->attachment);

        // Tombol upload invoice di ERP (yang mengisi item) hilang bila lampiran
        // sudah ada, jadi item pembelian harus ikut dibuat.
        $this->assertSame(1, $expense->itemExpenses()->count());
        $this->assertEquals(150_000, $expense->itemExpenses()->first()->price);
    }

    public function test_foto_dengan_caption_langsung_diproses(): void
    {
        Storage::fake('public');
        $this->user();
        $this->project('PT Nestory Indonesia', 'Video Profile');
        $this->fakeApis(gemini: null);

        $this->send($this->photoUpdate('struk-2', 'expense 150rb grab ke kantor klien nestory'));

        $draft = Draft::load($this->chatId);
        $this->assertTrue($draft->isComplete());
        $this->assertSame('struk-2', $draft->attachment['file_id']);
    }

    public function test_foto_susulan_dilampirkan_ke_draft_yang_ada(): void
    {
        $this->user();
        $this->project('PT Nestory Indonesia', 'Video Profile');
        $this->fakeApis(gemini: null);

        $this->send($this->textUpdate('expense 150rb grab ke kantor klien nestory'));
        $this->assertStringContainsString('Kirim foto struk sekarang', $this->lastText());
        $id = Draft::load($this->chatId)->id;

        $this->send($this->photoUpdate('struk-3'));

        $draft = Draft::load($this->chatId);
        $this->assertSame($id, $draft->id, 'Foto susulan tidak membuat draft baru.');
        $this->assertSame('struk-3', $draft->attachment['file_id']);
        $this->assertStringContainsString('foto struk', $this->lastText());
    }

    public function test_album_hanya_foto_pertama_yang_dipakai(): void
    {
        $this->user();
        $this->fakeApis(gemini: null);

        $this->send($this->photoUpdate('album-1', album: 'grup-9'));
        $this->send($this->photoUpdate('album-2', album: 'grup-9'));

        $this->assertSame('album-1', Draft::load($this->chatId)->attachment['file_id']);
    }

    public function test_format_atau_ukuran_file_yang_tidak_didukung_ditolak(): void
    {
        $this->user();
        $this->fakeApis(gemini: null);

        $this->send($this->documentUpdate('application/zip'));
        $this->assertStringContainsString('belum didukung', $this->lastText());

        $this->send($this->documentUpdate('application/pdf', 6 * 1024 * 1024));
        $this->assertStringContainsString('lebih dari 5 MB', $this->lastText());

        $this->assertNull(Draft::load($this->chatId));
    }

    public function test_isi_file_yang_bukan_gambar_tidak_disimpan(): void
    {
        Storage::fake('public');
        $user = $this->user();
        $this->project('PT Nestory Indonesia', 'Video Profile');
        $this->fakeApis(gemini: null, file: '<html><script>alert(1)</script></html>');

        $this->send($this->photoUpdate('palsu', 'expense 150rb grab ke kantor klien nestory'));
        $draft = Draft::load($this->chatId);

        $this->send($this->tap("{$draft->id}:submit:"));

        $this->assertStringContainsString('gagal diambil', $this->lastText());
        $this->assertSame(0, Expense::where('user_id', $user->id)->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertNotNull(Draft::load($this->chatId), 'Draft disimpan supaya user bisa kirim ulang fotonya.');
    }

    public function test_struk_ikut_dibaca_gemini_bila_aktif(): void
    {
        $this->user();
        $project = $this->project('PT Nestory Indonesia', 'Video Profile');
        $this->fakeApis(gemini: $this->aiResult(
            type: 'expense', amount: 87_500, description: 'Grab ke kantor Nestory', category: 'Transport', projectId: $project->id,
        ));

        $this->send($this->photoUpdate('struk-ai', 'grab ke kantor klien nestory'));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'generativelanguage')
            && data_get($request->data(), 'contents.0.parts.1.inlineData.mimeType') === 'image/jpeg'
            && data_get($request->data(), 'contents.0.parts.1.inlineData.data') === base64_encode(self::JPEG));

        $this->assertSame(87_500, Draft::load($this->chatId)->amount, 'Nominal dari struk dipakai bila pesan tidak menyebutnya.');
    }

    public function test_struk_advance_tersimpan_tanpa_item(): void
    {
        Storage::fake('public');
        $user = $this->user();
        $this->project('PT Nestory Indonesia', 'Video Profile');
        $this->fakeApis(gemini: null);

        $this->send($this->photoUpdate('penawaran', 'advance 2jt sewa kamera shooting nestory'));
        $draft = Draft::load($this->chatId);
        $this->assertTrue($draft->isComplete());

        $this->send($this->tap("{$draft->id}:submit:"));

        $advance = Advance::where('user_id', $user->id)->latest('id')->first();
        $this->assertStringStartsWith('advance/attachment/', $advance->attachment);
        Storage::disk('public')->assertExists($advance->attachment);
    }

    // =================================================================
    // PIC
    // =================================================================

    public function test_project_kembar_ditampilkan_dengan_pic(): void
    {
        $this->user();
        $this->project('PT Nestory Indonesia', 'Video Profile', pic: 'Budi Santoso');
        $this->project('PT Nestory Indonesia', 'Konten Instagram', pic: 'Sari Dewi');
        $this->fakeApis(gemini: null);

        $this->send($this->textUpdate('grab 50rb ke nestory'));

        $this->assertStringContainsString('PIC: Budi Santoso', $this->lastText());
        $this->assertStringContainsString('PIC: Sari Dewi', $this->lastText());
    }

    public function test_nama_pic_di_pesan_memilih_project_yang_tepat(): void
    {
        $this->user();
        $budi = $this->project('PT Nestory Indonesia', 'Video Profile', pic: 'Budi Santoso');
        $this->project('PT Nestory Indonesia', 'Konten Instagram', pic: 'Sari Dewi');
        $this->fakeApis(gemini: null);

        $this->send($this->textUpdate('grab 50rb ke nestory pak budi'));

        $this->assertSame($budi->id, Draft::load($this->chatId)->projectId);
    }

    // =================================================================
    // Realisasi advance
    // =================================================================

    public function test_realisasi_dengan_struk_masuk_sebagai_batch_advance(): void
    {
        Storage::fake('public');
        $user    = $this->user();
        $project = $this->project('PT Nestory Indonesia', 'Video Profile');
        $advance = $this->openAdvance($user, $project, 2_000_000);
        $this->fakeApis(gemini: null);

        $this->send($this->photoUpdate('struk-real', 'realisasi 350rb sewa lighting nestory'));

        $draft = Draft::load($this->chatId);
        $this->assertSame('realisasi', $draft->type);
        $this->assertSame($advance->id, $draft->advanceId);
        $this->assertTrue($draft->isComplete());
        $this->assertStringContainsString('Draft Realisasi', $this->lastText());
        $this->assertStringContainsString('Rp 1.650.000', $this->lastText(), 'Sisa setelah realisasi ditampilkan.');

        $this->send($this->tap("{$draft->id}:submit:"));

        $batch = Realisasi::where('advance_id', $advance->id)->with('items.files')->first();
        $this->assertNotNull($batch);
        $this->assertSame($user->id, $batch->uploaded_by, 'Tercatat atas nama pengirim, bukan user id 1.');
        $this->assertEquals(350_000, $batch->items->first()->price);

        $file = $batch->items->first()->files->first();
        $this->assertStringStartsWith("realisasi/{$advance->id}/", $file->filepath);
        Storage::disk('public')->assertExists($file->filepath);

        $this->assertSame(Advance::STATUS_REALIZED_USER, $advance->fresh()->status);
        $this->assertStringContainsString('Realisasi terkirim', $this->lastText());
        $this->assertStringContainsString('Sisa Rp 1.650.000', $this->lastText());
    }

    public function test_beberapa_advance_terbuka_ditawarkan_lengkap_dengan_pic(): void
    {
        $user  = $this->user();
        $video = $this->openAdvance($user, $this->project('PT Nestory Indonesia', 'Video Profile', pic: 'Budi Santoso'));
        $this->openAdvance($user, $this->project('PT Nestory Indonesia', 'Konten Instagram', pic: 'Sari Dewi'));
        $this->fakeApis(gemini: null);

        $this->send($this->textUpdate('realisasi 100rb parkir nestory'));

        $this->assertStringContainsString('advance yang mana', $this->lastText());
        $this->assertStringContainsString('PIC Budi Santoso', $this->lastText());
        $this->assertStringContainsString('PIC Sari Dewi', $this->lastText());

        $draft = Draft::load($this->chatId);
        $this->send($this->tap("{$draft->id}:adv:{$video->id}"));

        $this->assertSame($video->id, Draft::load($this->chatId)->advanceId);
        $this->assertStringContainsString('Draft Realisasi', $this->lastText());
    }

    public function test_expense_untuk_project_yang_punya_advance_terbuka_ditanyakan_dulu(): void
    {
        $user    = $this->user();
        $project = $this->project('PT Nestory Indonesia', 'Video Profile');
        $advance = $this->openAdvance($user, $project);
        $this->fakeApis(gemini: null);

        $this->send($this->textUpdate('grab 150rb ke kantor klien nestory'));

        $this->assertStringContainsString('dibayar dari advance tersebut', $this->lastText());
        $draft = Draft::load($this->chatId);

        // Pilih "expense baru": tidak ditanya lagi, tapi ringkasan tetap mengingatkan.
        $this->send($this->tap("{$draft->id}:type:expense"));
        $this->assertStringContainsString('Draft Expense', $this->lastText());
        $this->assertStringContainsString('masih punya advance terbuka', $this->lastText());

        // Berubah pikiran dari ringkasan: jadikan realisasi.
        $this->send($this->tap("{$draft->id}:type:realisasi"));
        $this->assertSame($advance->id, Draft::load($this->chatId)->advanceId);
        $this->assertStringContainsString('Draft Realisasi', $this->lastText());
    }

    public function test_expense_yang_ditulis_jelas_tidak_ditanya_tapi_diberi_peringatan(): void
    {
        $user    = $this->user();
        $project = $this->project('PT Nestory Indonesia', 'Video Profile');
        $this->openAdvance($user, $project);
        $this->fakeApis(gemini: null);

        $this->send($this->textUpdate('expense 150rb grab ke kantor klien nestory'));

        $this->assertStringContainsString('Draft Expense', $this->lastText());
        $this->assertStringContainsString('masih punya advance terbuka', $this->lastText());
    }

    public function test_realisasi_tanpa_advance_terbuka_dijelaskan(): void
    {
        $this->user();
        $this->fakeApis(gemini: null);

        $this->send($this->textUpdate('realisasi 100rb parkir'));

        $this->assertStringContainsString('tidak punya advance yang bisa direalisasi', $this->lastText());
        $this->assertNull(Draft::load($this->chatId));
    }

    public function test_advance_milik_orang_lain_tidak_bisa_direalisasi(): void
    {
        $user    = $this->user();
        $project = $this->project('PT Nestory Indonesia', 'Video Profile');
        $milikku = $this->openAdvance($user, $project);
        $orang   = $this->openAdvance($this->user(linked: false), $project);
        $this->fakeApis(gemini: null);

        $this->send($this->textUpdate('realisasi 100rb parkir nestory'));
        $draft = Draft::load($this->chatId);
        $this->assertSame($milikku->id, $draft->advanceId, 'Hanya advance milik sendiri yang dicocokkan.');

        // Tombol palsu yang menunjuk advance orang lain diabaikan.
        $this->send($this->tap("{$draft->id}:adv:{$orang->id}"));
        $this->assertNotSame($orang->id, Draft::load($this->chatId)->advanceId);
    }

    public function test_overrun_yang_belum_disetujui_menahan_realisasi_berikutnya(): void
    {
        $user    = $this->user();
        $project = $this->project('PT Nestory Indonesia', 'Video Profile');
        $advance = $this->openAdvance($user, $project, 1_000_000);

        app(RealisasiService::class)->createBatch(
            $advance,
            ['uploaded_by' => $user->id],
            [['name' => 'Sewa kamera', 'quantity' => 1, 'price' => 1_200_000]]
        );

        $this->fakeApis(gemini: null);
        $this->send($this->textUpdate('realisasi 50rb parkir nestory'));
        $draft = Draft::load($this->chatId);
        $this->send($this->tap("{$draft->id}:submit:"));

        $this->assertStringContainsString('menyetujui overrun', $this->lastText());
        $this->assertSame(1, Realisasi::where('advance_id', $advance->id)->count());
    }

    // =================================================================
    // Notifikasi status
    // =================================================================

    public function test_perubahan_status_dikirim_ke_telegram_pengaju(): void
    {
        $this->fakeApis();
        $user    = $this->user();
        $project = $this->project();

        $expense = Expense::create([
            'user_id'            => $user->id,
            'submission_date'    => today(),
            'project_id'         => $project->id,
            'category'           => 'Transport',
            'keterangan_expense' => 'Grab',
            'amount'             => 150_000,
            'status'             => 'Pending',
        ]);

        $expense->update(['status' => 'Disetujui']);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === (string) $this->chatId
            && str_contains($request['text'], 'Expense Disetujui'));
    }

    // =================================================================
    // Halaman profil
    // =================================================================

    public function test_profil_membuat_link_lalu_bisa_memutuskan(): void
    {
        $this->fakeApis();
        $user = $this->user(linked: false);
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($user)->get('/admin/my-profile')
            ->assertOk()
            ->assertSee('Hubungkan Telegram');

        $komponen = Livewire::actingAs($user)
            ->test(TelegramConnection::class)
            ->call('generateLink');

        $token = Str::after($komponen->get('linkUrl'), 'start=');
        $this->send($this->textUpdate("/start {$token}"));
        $this->assertNotNull($user->fresh()->telegram_chat_id);

        $komponen->call('disconnect')->assertSee('Belum terhubung');
        $this->assertNull($user->fresh()->telegram_chat_id);
    }

    // =================================================================
    // Helper
    // =================================================================

    private function fakeApis(?array $gemini = null, string $file = self::JPEG): void
    {
        Http::fake([
            'api.telegram.org/*/getMe'   => Http::response(['ok' => true, 'result' => ['username' => 'erp_tes_bot']]),
            'api.telegram.org/*/getFile' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/struk.jpg', 'file_size' => strlen($file)]]),
            'api.telegram.org/file/*'    => Http::response($file),
            'api.telegram.org/*'         => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
            'generativelanguage.googleapis.com/*' => $gemini
                ? Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($gemini)]]]]]])
                : Http::response(['error' => ['code' => 402, 'message' => 'Your prepayment credits are depleted.']], 402),
        ]);
    }

    private function aiResult(
        ?string $type = null,
        ?int $amount = null,
        ?string $description = null,
        ?string $category = null,
        ?int $projectId = null,
        ?string $date = null,
    ): array {
        return [
            'type'        => $type,
            'amount'      => $amount,
            'description' => $description,
            'category'    => $category,
            'project_id'  => $projectId,
            'date'        => $date ?? today()->toDateString(),
        ];
    }

    private function user(bool $linked = true, array $permissions = ['create_expense', 'create_advance']): User
    {
        $user = User::forceCreate([
            'username'           => 'tg_' . Str::lower(Str::random(8)),
            'firstname'          => 'Tes',
            'lastname'           => 'Telegram',
            'email'              => Str::lower(Str::random(10)) . '@example.test',
            'password'           => bcrypt('rahasia'),
            'is_active'          => true,
            'push_enabled'       => false,
            'telegram_chat_id'   => $linked ? (string) $this->chatId : null,
            'telegram_linked_at' => $linked ? now() : null,
        ]);

        if ($permissions) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    private function project(
        string $perusahaan = 'PT Tes Telegram',
        string $nama = 'Video Profile Tes',
        string $status = 'in_progress',
        ?string $pic = null,
    ): Project {
        return Project::create([
            'nama_project'    => $nama,
            'nama_perusahaan' => $perusahaan,
            'status'          => $status,
            'nama_pic'        => $pic,
        ]);
    }

    private function openAdvance(User $user, Project $project, int $paid = 2_000_000): Advance
    {
        return Advance::create([
            'user_id'            => $user->id,
            'submission_date'    => today(),
            'project_id'         => $project->id,
            'keterangan_advance' => "Advance {$project->nama_project}",
            'category'           => 'Project Support',
            'amount'             => $paid,
            'paid'               => $paid,
            'status'             => Advance::STATUS_DIBAYARKAN,
        ]);
    }

    private function send(array $update): void
    {
        $this->postJson('/api/telegram/webhook', $update, [
            'X-Telegram-Bot-Api-Secret-Token' => self::SECRET,
        ])->assertOk();
    }

    private function photoUpdate(string $fileId, ?string $caption = null, ?string $album = null): array
    {
        $update = $this->textUpdate('');
        unset($update['message']['text']);

        // Telegram mengirim beberapa ukuran; yang terakhir paling besar.
        $update['message']['photo'] = [
            ['file_id' => "{$fileId}-kecil", 'file_size' => 900, 'width' => 90, 'height' => 90],
            ['file_id' => $fileId, 'file_size' => 48_000, 'width' => 1280, 'height' => 960],
        ];

        if ($caption !== null) {
            $update['message']['caption'] = $caption;
        }

        if ($album !== null) {
            $update['message']['media_group_id'] = $album;
        }

        return $update;
    }

    private function documentUpdate(string $mime, int $size = 10_000): array
    {
        $update = $this->textUpdate('');
        unset($update['message']['text']);
        $update['message']['document'] = ['file_id' => 'dok-1', 'mime_type' => $mime, 'file_size' => $size, 'file_name' => 'berkas'];

        return $update;
    }

    private function textUpdate(string $text): array
    {
        return [
            'update_id' => 700_000_000 + ++$this->updateId,
            'message'   => [
                'message_id' => 10 + $this->updateId,
                'chat'       => ['id' => $this->chatId, 'type' => 'private'],
                'from'       => ['id' => $this->chatId, 'is_bot' => false, 'first_name' => 'Tes'],
                'text'       => $text,
            ],
        ];
    }

    private function tap(string $data): array
    {
        return [
            'update_id'      => 700_000_000 + ++$this->updateId,
            'callback_query' => [
                'id'      => 'cb-' . $this->updateId,
                'data'    => $data,
                'message' => [
                    'message_id' => 1,
                    'chat'       => ['id' => $this->chatId, 'type' => 'private'],
                ],
            ],
        ];
    }

    /**
     * Teks terakhir yang dikirim atau diubah bot.
     */
    private function lastText(): string
    {
        return (string) collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $request) => preg_match('#/(sendMessage|editMessageText)$#', $request->url()))
            ->last()?->data()['text'];
    }
}
