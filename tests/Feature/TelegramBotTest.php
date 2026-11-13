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
use Illuminate\Support\Facades\DB;
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
        // Izin Shield Director/Finance untuk bot, seperti instalasi yang sudah dimigrasi.
        (require database_path('migrations/2026_11_13_130000_grant_finance_bot_permissions.php'))->up();

        // Salinan sementara struk (TelegramBot::receiptData) tidak boleh menumpuk di storage asli.
        Storage::fake('local');
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

    public function test_koneksi_putus_tidak_membocorkan_token_bot(): void
    {
        // cURL selain gagal konek (mis. error 56) lolos dari Laravel sebagai TransferException berisi URL bertoken.
        Http::fake(fn () => throw new \GuzzleHttp\Exception\TransferException('cURL error 56: Recv failure (see https://api.telegram.org/bot123456:TES/sendMessage)'));
        \Illuminate\Support\Facades\Log::spy();

        $this->assertNull((new \App\Services\Telegram\TelegramClient())->sendMessage($this->chatId, 'tes'));

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context) => ! str_contains($context['error'], '123456:TES'));
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

    public function test_karyawan_auto_approve_langsung_disetujui_lewat_bot(): void
    {
        $user    = $this->user();
        $project = $this->project();

        $settings = app(\App\Settings\GeneralSettings::class);
        $settings->auto_approve_user_ids = [$user->id];
        $settings->save();

        $this->fakeApis(gemini: $this->aiResult(
            type: 'expense',
            amount: 150_000,
            description: 'Grab ke kantor klien',
            category: 'Transport',
            projectId: $project->id,
        ));

        $this->send($this->textUpdate('grab ke kantor klien 150rb'));
        $draft = Draft::load($this->chatId);
        $this->send($this->tap("{$draft->id}:submit:"));

        $this->assertSame('Disetujui', Expense::where('user_id', $user->id)->latest('id')->value('status'));
        $this->assertStringContainsString('langsung <b>disetujui</b>', $this->lastText());
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

    public function test_ucapan_terima_kasih_tidak_membuka_draft(): void
    {
        $this->user();
        $this->fakeApis(gemini: $this->aiResult(type: 'expense', amount: 150_000, description: 'Grab', category: 'Transport'));

        $this->send($this->textUpdate('Makasih pak 🙏'));

        $this->assertNull(Draft::load($this->chatId));
        $this->assertFalse(collect(Http::recorded())->contains(fn ($pair) => str_contains($pair[0]->url(), 'generativelanguage')), 'Tanpa panggilan AI.');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage') && str_contains((string) $request['text'], '/bantuan'));
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

    public function test_foto_struk_dari_bot_dirapikan_seperti_scan(): void
    {
        Storage::fake('public');
        $user = $this->user();
        $this->project('PT Nestory Indonesia', 'Video Profile');

        // Foto HP: kertas terang miring di atas meja gelap.
        $photo = imagecreatetruecolor(900, 1200);
        imagefill($photo, 0, 0, imagecolorallocate($photo, 60, 50, 45));
        imagefilledpolygon($photo, [200, 150, 720, 210, 680, 1080, 170, 1030], imagecolorallocate($photo, 238, 236, 230));
        // Noise sensor kamera: tanpa itu warnanya rata sempurna seperti screenshot (tidak diolah).
        mt_srand(7);
        $noise = imagecreatetruecolor(61, 59);
        for ($y = 0; $y < 59; $y++) {
            for ($x = 0; $x < 61; $x++) {
                imagesetpixel($noise, $x, $y, mt_rand(0, 0xFFFFFF));
            }
        }
        for ($y = 0; $y < 1200; $y += 59) {
            for ($x = 0; $x < 900; $x += 61) {
                imagecopymerge($photo, $noise, $x, $y, 0, 0, 61, 59, 5);
            }
        }
        ob_start();
        imagejpeg($photo, null, 90);
        $this->fakeApis(gemini: null, file: (string) ob_get_clean());

        $this->send($this->photoUpdate('foto-hp', 'expense 150rb grab ke kantor klien nestory'));
        $draft = Draft::load($this->chatId);
        $this->send($this->tap("{$draft->id}:submit:"));

        $expense = Expense::where('user_id', $user->id)->sole();
        $scan    = imagecreatefromstring(Storage::disk('public')->get($expense->attachment));

        $this->assertStringEndsWith('.jpg', $expense->attachment);
        // Meja di sekeliling struk terpotong: perbandingan sisinya mengikuti kertas, pojoknya putih.
        $this->assertEqualsWithDelta(880 / 520, imagesy($scan) / imagesx($scan), 0.15);
        $this->assertGreaterThan(200, imagecolorat($scan, 3, 3) & 0xFF);
        $this->assertNotNull(\App\Services\ReceiptScanner::original($expense->attachment), 'Foto asli ikut disimpan.');
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

    public function test_semua_foto_album_masuk_draft_yang_sama(): void
    {
        $this->user();
        $this->fakeApis(gemini: null);

        $this->send($this->photoUpdate('album-1', album: 'grup-9'));
        $this->send($this->photoUpdate('album-2', album: 'grup-9'));

        $draft = Draft::load($this->chatId);
        $this->assertSame(['album-1', 'album-2'], array_column($draft->receipts, 'file_id'));
        $this->assertStringContainsString('2 struk diterima', $this->lastText());
    }

    public function test_foto_yang_kehabisan_antrean_diminta_dikirim_ulang(): void
    {
        $this->user();
        $this->fakeApis(gemini: null);

        // Pesan sebelumnya di chat ini belum selesai sampai batas tunggu habis;
        // jeda tunggu dipalsukan supaya tes tidak menunggu 60 detik.
        \Illuminate\Support\Facades\Cache::lock("telegram:chat:{$this->chatId}", 120)->get();
        \Illuminate\Support\Sleep::fake(syncWithCarbon: true);

        $photo = $this->photoUpdate('album-3', album: 'grup-10');
        $this->send($photo);

        $this->assertNull(Draft::load($this->chatId));
        $this->assertStringContainsString('belum diproses', $this->lastText());
        $this->assertLastQuestionRepliesTo($photo);
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

    public function test_struk_hanya_diunduh_sekali_dari_telegram(): void
    {
        Storage::fake('public');
        $user = $this->user();
        $this->project('PT Nestory Indonesia', 'Video Profile');
        $this->fakeApis(gemini: ['amount' => 50_000, 'name' => 'Bensin']);

        // Struk album dibutuhkan beberapa kali: dibaca Gemini bersama caption,
        // dibaca lagi per struk saat album bertambah, lalu disimpan saat Kirim.
        $this->send($this->photoUpdate('s1', 'expense bensin nestory', album: 'g5'));
        $this->send($this->photoUpdate('s2', album: 'g5'));
        $draft = Draft::load($this->chatId);
        $this->send($this->tap("{$draft->id}:submit:"));

        $this->assertSame(2, Expense::where('user_id', $user->id)->count());

        $unduhan = collect(Http::recorded())
            ->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/getFile'))
            ->map(fn ($pair) => $pair[0]->data()['file_id'])
            ->countBy()
            ->all();
        $this->assertSame(['s1' => 1, 's2' => 1], $unduhan);

        $this->assertSame([], Storage::disk('local')->allFiles(), 'Salinan sementara dihapus setelah struk tersimpan.');
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

    public function test_tanggal_bisa_diubah_lewat_tombol(): void
    {
        Storage::fake('public');
        $this->travelTo(\Illuminate\Support\Carbon::create(2026, 11, 3, 10));
        $user = $this->user();
        $this->project('PT Nestory Indonesia', 'Video Profile');
        $this->fakeApis(gemini: null);

        $this->send($this->textUpdate('expense 150rb grab ke kantor klien nestory'));
        $this->assertContains('✏️ Tanggal', $this->lastButtons());

        $draft = Draft::load($this->chatId);
        $this->send($this->tap("{$draft->id}:edit:date"));
        $this->assertStringContainsString('Tanggal sekarang: <b>3 Nov 2026</b>', $this->lastText());

        $this->send($this->textUpdate('10/11'));
        $this->assertStringContainsString('tidak boleh setelah hari ini', $this->lastText());

        $this->send($this->textUpdate('tanggal berapa ya'));
        $this->assertStringContainsString('belum terbaca', $this->lastText());
        $this->assertSame('2026-11-03', Draft::load($this->chatId)->date, 'Tanggal lama berlaku sampai dijawab.');

        $this->send($this->textUpdate('1/11'));
        $this->assertStringContainsString('📅 Tanggal: 1 Nov 2026', $this->lastText());

        $this->send($this->tap("{$draft->id}:submit:"));
        $this->assertSame('2026-11-01', Expense::where('user_id', $user->id)->sole()->submission_date->toDateString());
    }

    public function test_tanggal_slip_bca_bulan_hari_tidak_terbalik(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::create(2026, 11, 5, 10));
        $this->user();
        $this->fakeApis(gemini: ['date_text' => '11/03/26 14:22', 'date' => '2026-03-11'] + $this->receiptRead());

        $this->send($this->photoUpdate('slip-bca'));

        $this->assertSame('2026-11-03', Draft::load($this->chatId)->date);
    }

    public function test_foto_tanpa_keterangan_dijabarkan_lalu_jadi_realisasi_advance(): void
    {
        Storage::fake('public');
        $user    = $this->user();
        $advance = $this->openAdvance($user, $this->project('PT Belamy Indonesia', 'Booth Pameran'));
        $this->fakeApis(gemini: $this->receiptRead());

        // Satu-satunya advance terbuka: struk langsung jadi draft realisasinya.
        $this->send($this->photoUpdate('nota-1'));

        $this->assertStringContainsString('Draft Realisasi', $this->lastText());
        $this->assertStringContainsString("💸 Advance #{$advance->id}: <b>Advance Booth Pameran</b>", $this->lastText());
        $this->assertStringContainsString('Kabel HDMI — 2 × Rp 125.000', $this->lastText());
        $this->assertStringContainsString('💰 Nominal: <b>Rp 265.000</b>', $this->lastText());
        // Tanggal pembelian dibaca dari struk, bukan tanggal kirim.
        $this->assertStringContainsString('🗓 Tanggal struk: <b>' . today()->subDays(2)->locale('id')->translatedFormat('j M Y') . '</b> (dibaca dari struk)', $this->lastText());
        $this->assertContains('✏️ Tanggal', $this->lastButtons());
        $this->assertStringContainsString('Struk/invoice dianggap realisasi advance', $this->lastText());
        $this->assertContains('💰 Jadikan Expense', $this->lastButtons());

        $draft = Draft::load($this->chatId);
        $this->send($this->tap("{$draft->id}:submit:"));

        $item = Realisasi::where('advance_id', $advance->id)->with('items.files')->sole()->items->sole();
        $this->assertSame(today()->subDays(2)->toDateString(), $item->receipt_date->toDateString());
        $this->assertSame('Kabel HDMI dan lakban', $item->name);
        $this->assertSame('Kabel HDMI 2 × Rp 125.000; Lakban Rp 15.000', $item->description, 'Rincian struk jadi keterangan item.');
        $this->assertEquals(265_000, $item->price);
        $this->assertCount(1, $item->files);
        $this->assertSame(1, Advance::where('user_id', $user->id)->count(), 'Tidak ada advance baru.');
    }

    public function test_foto_tanpa_keterangan_jadi_expense_dengan_item_pembelian(): void
    {
        Storage::fake('public');
        $user    = $this->user();
        $project = $this->project('PT Nestory Indonesia', 'Video Profile');
        $this->fakeApis(gemini: $this->receiptRead());

        $this->send($this->photoUpdate('nota-2'));
        $this->assertSame(
            ['💰 Expense baru — sudah bayar sendiri', '💸 Advance baru — minta dana', '❌ Batal'],
            $this->lastButtons(),
            'Tanpa advance terbuka, tidak ada pilihan realisasi.'
        );

        $draft = Draft::load($this->chatId);
        $this->send($this->tap("{$draft->id}:to:expense"));
        $this->send($this->tap("{$draft->id}:project:{$project->id}"));

        // Tanggal & kategori dari struk; tinggal Kirim.
        $this->assertStringContainsString('Draft Expense', $this->lastText());
        $this->assertStringContainsString('Kabel HDMI — 2 × Rp 125.000', $this->lastText());
        $this->send($this->tap("{$draft->id}:submit:"));

        $expense = Expense::where('user_id', $user->id)->sole();
        $this->assertEquals(265_000, $expense->amount);
        $this->assertSame('Kabel HDMI dan lakban', $expense->keterangan_expense);
        $this->assertSame('Project Support', $expense->category);
        $this->assertSame(today()->subDays(2)->toDateString(), $expense->submission_date->toDateString());
        $this->assertSame(
            [['Kabel HDMI', 2, 125_000.0], ['Lakban', 1, 15_000.0]],
            $expense->itemExpenses()->orderBy('id')->get()->map(fn ($i) => [$i->name, (int) $i->quantity, (float) $i->price])->all()
        );
    }

    public function test_invoice_vendor_jadi_advance_baru_dengan_rinciannya(): void
    {
        Storage::fake('public');
        $user    = $this->user();
        $project = $this->project('PT Nestory Indonesia', 'Video Profile');
        $this->fakeApis(gemini: $this->receiptRead(items: [['name' => 'Sewa lighting', 'qty' => 2, 'price' => 1_500_000], ['name' => 'Operator', 'qty' => 1, 'price' => 500_000]], amount: 3_500_000));

        $this->send($this->photoUpdate('invoice-vendor'));
        $draft = Draft::load($this->chatId);
        $this->send($this->tap("{$draft->id}:to:advance"));
        $this->send($this->tap("{$draft->id}:project:{$project->id}"));
        $this->send($this->tap("{$draft->id}:submit:"));

        $advance = Advance::where('user_id', $user->id)->sole();
        $this->assertEquals(3_500_000, $advance->amount);
        $this->assertSame(
            [['Sewa lighting', 2, 1_500_000.0], ['Operator', 1, 500_000.0]],
            \App\Models\ItemAdvance::where('advance_id', $advance->id)->orderBy('id')->get()->map(fn ($i) => [$i->name, (int) $i->quantity, (float) $i->price])->all()
        );
    }

    public function test_rincian_tetap_dipakai_dengan_baris_selisih(): void
    {
        $this->user();
        // Total 300 rb: PB1 & service 35 rb tidak terbaca sebagai baris; satu baris gratis.
        $this->fakeApis(gemini: $this->receiptRead(amount: 300_000, items: [
            ['name' => 'Kabel HDMI', 'qty' => 2, 'price' => 125_000],
            ['name' => 'Lakban', 'qty' => 1, 'price' => 15_000],
            ['name' => 'Kantong plastik', 'qty' => 1, 'price' => 0],
        ]));

        $this->send($this->photoUpdate('nota-pajak'));

        $this->assertSame([
            ['name' => 'Kabel HDMI', 'qty' => 2, 'price' => 125_000],
            ['name' => 'Lakban', 'qty' => 1, 'price' => 15_000],
            ['name' => \App\Services\Telegram\SubmissionParser::GAP_ITEM, 'qty' => 1, 'price' => 35_000],
        ], Draft::load($this->chatId)->attachment['items']);
        $this->assertStringContainsString('Masukkan ke mana?', $this->lastText(), 'Jenis tetap ditanyakan.');
    }

    public function test_rincian_yang_tidak_cocok_dengan_total_tidak_dipakai(): void
    {
        $this->user();
        $this->fakeApis(gemini: $this->receiptRead(amount: 400_000));

        $this->send($this->photoUpdate('nota-3'));

        $draft = Draft::load($this->chatId);
        $this->assertSame([], $draft->attachment['items']);
        $this->assertSame(400_000, $draft->amount, 'Total struk tetap dipakai.');
        $this->assertStringNotContainsString('Kabel HDMI —', $this->lastText());
        $this->assertStringContainsString('Masukkan ke mana?', $this->lastText());
    }

    public function test_album_tanpa_keterangan_dibaca_per_struk(): void
    {
        Storage::fake('public');
        $user    = $this->user();
        $project = $this->project('PT Nestory Indonesia', 'Video Profile');
        $this->fakeApis(gemini: $this->receiptRead());

        $this->send($this->photoUpdate('a1', album: 'g8'));
        $this->send($this->photoUpdate('a2', album: 'g8'));

        $this->assertStringContainsString('2 struk terbaca', $this->lastText());
        $this->assertStringContainsString('Total: <b>Rp 530.000</b>', $this->lastText());

        $draft = Draft::load($this->chatId);
        $this->send($this->tap("{$draft->id}:to:expense"));
        $this->send($this->tap("{$draft->id}:project:{$project->id}"));
        $this->send($this->tap("{$draft->id}:submit:"));

        $expenses = Expense::where('user_id', $user->id)->withCount('itemExpenses')->get();
        $this->assertCount(2, $expenses, 'Satu expense per struk.');
        $this->assertSame([2, 2], $expenses->pluck('item_expenses_count')->all());
    }

    public function test_keterangan_tetap_bisa_diketik_setelah_struk_dibaca(): void
    {
        $this->user();
        $project = $this->project('PT Nestory Indonesia', 'Video Profile');
        $this->fakeApis(gemini: $this->receiptRead());

        $this->send($this->photoUpdate('nota-4'));
        $this->send($this->textUpdate('expense beli kabel untuk nestory'));

        $draft = Draft::load($this->chatId);
        $this->assertSame('expense', $draft->type);
        $this->assertSame($project->id, $draft->projectId);
        $this->assertSame(265_000, $draft->amount);
        $this->assertStringContainsString('Draft Expense', $this->lastText());
    }

    public function test_advance_dan_project_bisa_diketik_setelah_struk_dibaca(): void
    {
        $this->user();
        $project = $this->project('PT Nestory Indonesia', 'Video Profile');
        $this->fakeApis(gemini: $this->receiptRead());

        $this->send($this->photoUpdate('invoice-vendor-2'));
        $this->assertStringContainsString('Masukkan ke mana?', $this->lastText(), 'Bot menanyakan jenisnya dulu.');
        $this->send($this->textUpdate('advance sewa lighting nestory'));

        $draft = Draft::load($this->chatId);
        $this->assertSame('advance', $draft->type);
        $this->assertSame($project->id, $draft->projectId);
        $this->assertSame(265_000, $draft->amount, 'Nominal dari struk.');
        $this->assertStringContainsString('Draft Advance', $this->lastText());
    }

    public function test_ketikan_singkat_jenis_dan_project_tetap_dikenali(): void
    {
        $this->user();
        $nestory  = $this->project('PT Nestory Indonesia', 'Video Profile');
        $sinergi  = $this->project('PT Sinergi Citra Nusantara', 'Konten Bulanan');
        $this->project('Belinda Store', 'Katalog');
        $this->fakeApis(); // tanpa Gemini: pembaca lokal

        foreach ([
            'adv nest 2jt sewa lighting'     => ['advance', $nestory->id],
            'exp scn 150rb grab'             => ['expense', $sinergi->id],
            'expens nusntra 80rb parkir'     => ['expense', $sinergi->id],
            'rembes nestori 45rb tol'        => ['expense', $nestory->id],
            'beli kabel 50rb'                => ['expense', null],
        ] as $text => [$type, $projectId]) {
            Draft::forget($this->chatId);
            $this->send($this->textUpdate($text));

            $draft = Draft::load($this->chatId);
            $this->assertSame($type, $draft->type, $text);
            $this->assertSame($projectId, $draft->projectId, $text);
        }
    }

    public function test_realisasi_memilih_advance_dari_keterangan_singkat(): void
    {
        $user     = $this->user();
        $booth    = $this->openAdvance($user, $this->project('PT Belamy Indonesia', 'Booth Pameran'));
        $lighting = $this->openAdvance($user, $this->project('PT Nestory Indonesia', 'Video Profile'));
        $lighting->update(['keterangan_advance' => 'Sewa lighting dan stand']);
        $this->fakeApis();

        $this->send($this->textUpdate('reali lighting 300rb'));

        $draft = Draft::load($this->chatId);
        $this->assertSame('realisasi', $draft->type);
        $this->assertSame($lighting->id, $draft->advanceId, 'Advance dipilih dari keterangannya.');
        $this->assertNotSame($booth->id, $draft->advanceId);
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

        // Klien di baris sendiri, nama project di bawahnya: tidak terpenggal di layar HP.
        $this->assertStringContainsString("1. Budi Santoso - PT Nestory Indonesia\n    ↳ Video Profile", $this->lastText());
        $this->assertStringContainsString("2. Sari Dewi - PT Nestory Indonesia\n    ↳ Konten Instagram", $this->lastText());
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
        $this->assertStringContainsString("Budi Santoso - PT Nestory Indonesia\n       ↳ Video Profile", $this->lastText());
        $this->assertStringContainsString("Sari Dewi - PT Nestory Indonesia\n       ↳ Konten Instagram", $this->lastText());

        $draft = Draft::load($this->chatId);
        $this->send($this->tap("{$draft->id}:adv:{$video->id}"));

        $this->assertSame($video->id, Draft::load($this->chatId)->advanceId);
        $this->assertStringContainsString('Draft Realisasi', $this->lastText());
    }

    public function test_kata_advance_untuk_project_dengan_advance_cair_ditanyakan_dulu(): void
    {
        $user    = $this->user();
        $advance = $this->openAdvance($user, $this->project('PT Belamy Indonesia', 'Booth Pameran'));
        $this->fakeApis(gemini: null);

        // Dulu langsung jadi draft advance baru karena ada kata "advance".
        $this->send($this->textUpdate('advance belamy 200rb beli kabel'));

        $this->assertStringContainsString('minta advance baru?', $this->lastText());
        $this->assertStringContainsString('📁 PT Belamy Indonesia', $this->lastText());
        $this->assertStringContainsString("#{$advance->id} · <b>Advance Booth Pameran</b>", $this->lastText());
        $this->assertStringContainsString('sisa <b>Rp 2.000.000</b>', $this->lastText());

        $draft = Draft::load($this->chatId);
        $this->send($this->tap("{$draft->id}:type:realisasi"));
        $this->assertStringContainsString('Draft Realisasi', $this->lastText());

        $draft = Draft::load($this->chatId);
        $this->send($this->tap("{$draft->id}:submit:"));

        $this->assertSame(1, Advance::where('user_id', $user->id)->count(), 'Tidak ada advance baru.');
        $this->assertEquals(200_000, Realisasi::where('advance_id', $advance->id)->sole()->items->sum('price'));
    }

    public function test_advance_baru_tetap_bisa_dipilih_walau_ada_advance_cair(): void
    {
        $user = $this->user();
        $this->openAdvance($user, $this->project('PT Belamy Indonesia', 'Booth Pameran'));
        $this->fakeApis(gemini: null);

        $this->send($this->textUpdate('advance belamy 3jt beli material booth tahap 2'));
        $draft = Draft::load($this->chatId);
        $this->send($this->tap("{$draft->id}:type:advance"));
        $this->send($this->tap("{$draft->id}:category:0"));

        $this->assertStringContainsString('Draft Advance', $this->lastText());
        $this->assertStringContainsString('Jadikan realisasi advance', $this->lastText());

        $this->send($this->tap("{$draft->id}:submit:"));

        $this->assertSame(2, Advance::where('user_id', $user->id)->count());
    }

    public function test_frasa_ke_advance_dibaca_sebagai_realisasi(): void
    {
        $user    = $this->user();
        $advance = $this->openAdvance($user, $this->project('PT Belamy Indonesia', 'Booth Pameran'));
        $this->fakeApis(gemini: null);

        foreach (['masukkan ke advance belamy 150rb beli lakban', 'dibayar dari advance belamy 150rb beli lakban', 'pakai advance belamy 150rb lakban'] as $pesan) {
            $this->send($this->textUpdate('/batal'));
            $this->send($this->textUpdate($pesan));

            $draft = Draft::load($this->chatId);
            $this->assertSame('realisasi', $draft->type, $pesan);
            $this->assertSame($advance->id, $draft->advanceId, $pesan);
            $this->assertStringContainsString('Draft Realisasi', $this->lastText(), $pesan);
            $this->assertStringNotContainsStringIgnoringCase('masukkan', $this->lastText(), $pesan);
        }
    }

    public function test_finance_merealisasi_advance_karyawan_lewat_bot(): void
    {
        Storage::fake('public');
        $budi    = $this->employee('Budi', 'Santoso');
        $advance = $this->openAdvance($budi, $this->project('PT Belamy Indonesia', 'Booth Pameran'));
        $finance = $this->user();
        $finance->assignRole('Finance');
        $this->fakeApis(gemini: null);

        $this->send($this->textUpdate('realisasi belamy 200rb beli kabel'));

        $this->assertStringContainsString('Draft Realisasi', $this->lastText());
        $this->assertStringContainsString('👤 Pengaju: Budi Santoso', $this->lastText());

        $draft = Draft::load($this->chatId);
        $this->send($this->tap("{$draft->id}:submit:"));

        $batch = Realisasi::where('advance_id', $advance->id)->sole();
        $this->assertSame($finance->id, $batch->uploaded_by, 'Tercatat siapa yang memasukkan.');
        $this->assertSame($budi->id, $advance->fresh()->user_id, 'Advance tetap milik Budi.');
        $this->assertStringContainsString('Pengaju: Budi Santoso', $this->lastText());
    }

    public function test_kata_advance_dari_director_ditanyakan_realisasi_advance_karyawan(): void
    {
        Storage::fake('public');
        $budi     = $this->employee('Budi', 'Santoso');
        $advance  = $this->openAdvance($budi, $this->project('PT Belamy Indonesia', 'Booth Pameran'));
        $director = $this->user();
        $director->assignRole('Director');
        $this->fakeApis(gemini: null);

        // Dulu langsung jadi draft advance baru milik Director: yang dicek hanya advance miliknya sendiri.
        $this->send($this->textUpdate('advance belamy 200rb beli kabel'));

        $this->assertStringContainsString('Project ini punya advance terbuka', $this->lastText());
        $this->assertStringContainsString('<b>Advance Booth Pameran</b>', $this->lastText());
        $this->assertStringContainsString('👤 Budi Santoso', $this->lastText());

        $draft = Draft::load($this->chatId);
        $this->send($this->tap("{$draft->id}:type:realisasi"));
        $this->assertStringContainsString('👤 Pengaju: Budi Santoso', $this->lastText());

        $this->send($this->tap(Draft::load($this->chatId)->id . ':submit:'));

        $this->assertSame(0, Advance::where('user_id', $director->id)->count(), 'Tidak ada advance baru.');
        $this->assertEquals(200_000, Realisasi::where('advance_id', $advance->id)->sole()->items->sum('price'));
    }

    public function test_pesan_tanpa_jenis_dari_finance_ditanyakan_realisasi_advance_karyawan(): void
    {
        $budi = $this->employee('Budi', 'Santoso');
        $this->openAdvance($budi, $this->project('PT Belamy Indonesia', 'Booth Pameran'));
        $this->user()->assignRole('Finance');
        $this->fakeApis(gemini: null);

        // Jenisnya tebakan (expense); bisa jadi realisasi advance Budi.
        $this->send($this->textUpdate('belamy 200rb makan tim'));

        $this->assertStringContainsString('<b>Advance Booth Pameran</b>', $this->lastText());
        $this->assertStringContainsString('👤 Budi Santoso', $this->lastText());
        $this->assertStringContainsString('dibayar dari advance tersebut', $this->lastText());
    }

    public function test_berbagai_cara_menulis_realisasi_dikenali(): void
    {
        $user = $this->user();
        $this->openAdvance($user, $this->project('PT Belamy Indonesia', 'Booth Pameran'));
        $this->fakeApis(gemini: null);

        foreach ([
            'pakai uang advance belamy 200rb makan', 'realisasiin advance belamy 200rb makan', 'relisasi belamy 200rb makan',
            'advance belamy kepake 200rb makan tim', 'lapor pemakaian advance belamy 200rb makan',
            'penggunaan advance belamy 200rb makan', 'masuk advance belamy 200rb makan',
        ] as $text) {
            Draft::forget($this->chatId);
            $this->send($this->textUpdate($text));

            $this->assertSame('realisasi', Draft::load($this->chatId)?->type, $text);
        }

        // Minta advance baru tetap advance.
        Draft::forget($this->chatId);
        $this->send($this->textUpdate('minta advance 2jt untuk shooting, nanti dipakai sewa alat'));
        $this->assertSame('advance', Draft::load($this->chatId)?->type);
    }

    public function test_director_melihat_advance_karyawan_saat_kirim_foto(): void
    {
        $budi = $this->employee('Budi', 'Santoso');
        $advance = $this->openAdvance($budi, $this->project('PT Belamy Indonesia', 'Booth Pameran'));
        $this->user()->assignRole('Director');
        $this->fakeApis(gemini: $this->receiptRead());

        $this->send($this->photoUpdate('nota-dir'));

        $this->assertContains("🧾 #{$advance->id} · Advance Booth Pameran (Budi)", $this->lastButtons());
        $this->assertStringContainsString('👤 Budi Santoso', $this->lastText());
    }

    public function test_daftar_advance_menampilkan_nama_lengkap_dan_penandanya(): void
    {
        $user    = $this->user();
        $advance = $this->openAdvance($user, $this->project('PT Belamy Indonesia', 'Booth Pameran'));
        $advance->update(['keterangan_advance' => 'Advance produksi booth pameran Jakarta Fair hari kedua beserta konsumsi kru dan transport']);
        \App\Models\ItemAdvance::create(['advance_id' => $advance->id, 'name' => 'Sewa lighting', 'description' => '-', 'quantity' => 1, 'price' => 1_500_000]);
        \App\Models\ItemAdvance::create(['advance_id' => $advance->id, 'name' => 'Konsumsi kru', 'description' => '-', 'quantity' => 1, 'price' => 500_000]);
        // Dua advance terbuka: bot menanyakan advance yang mana.
        $this->openAdvance($user, $this->project('PT Nestory Indonesia', 'Video Profile'));
        $this->fakeApis(gemini: $this->receiptRead());

        $this->send($this->photoUpdate('nota-lengkap'));

        $text = $this->lastText();
        $this->assertStringContainsString('Realisasi advance yang mana?', $text);
        $this->assertContains('💰 Bukan realisasi — Expense baru', $this->lastButtons());
        $this->assertStringContainsString("#{$advance->id} · <b>Advance produksi booth pameran Jakarta Fair hari kedua beserta konsumsi kru dan transport</b>", $text);
        $this->assertStringContainsString('Booth Pameran', $text);
        $this->assertStringContainsString('🏷 Project Support · 🗓 ' . today()->locale('id')->translatedFormat('j M Y'), $text);
        $this->assertStringContainsString('📝 Rincian: Sewa lighting, Konsumsi kru', $text);
        $this->assertStringContainsString('💸 Cair Rp 2.000.000 · sisa <b>Rp 2.000.000</b>', $text);
        $this->assertContains("🧾 #{$advance->id} · Advance produksi booth…", $this->lastButtons());
    }

    public function test_finance_mencari_advance_yang_tidak_ada_di_daftar(): void
    {
        $budi = $this->employee('Budi', 'Santoso');
        $sari = $this->employee('Sari', 'Dewi');
        $langka = $this->openAdvance($sari, $this->project('PT Langka Sekali', 'Event Gathering'));

        foreach (range(1, 8) as $n) {
            $this->openAdvance($budi, $this->project("PT Klien {$n}", "Project {$n}"));
        }

        $this->user()->assignRole('Finance');
        $this->fakeApis(gemini: null);

        // Tanpa project: semua advance terbuka, per halaman. Dulu hanya 8 terbaru.
        $this->send($this->textUpdate('realisasi 100rb parkir'));
        $draft = Draft::load($this->chatId);

        $this->assertStringContainsString('advance terbuka</b>, halaman 1 dari ', $this->lastText());
        $this->assertStringNotContainsString('PT Langka Sekali', $this->lastText());
        $this->assertContains('Berikutnya ➡️', $this->lastButtons());

        $this->send($this->tap("{$draft->id}:advlist:1"));
        $this->assertStringContainsString('halaman 2 dari ', $this->lastText());
        $this->assertStringContainsString("#{$langka->id} · <b>Advance Event Gathering</b>", $this->lastText());
        $this->assertContains("#{$langka->id} · Advance Event Gathering (Sari)", $this->lastButtons());
        $this->assertContains('⬅️ Sebelumnya', $this->lastButtons());

        // Cari lewat nama klien atau ID advance.
        $this->send($this->textUpdate('langka'));
        $this->assertStringContainsString('PT Langka Sekali', $this->lastText());
        $this->assertStringContainsString('👤 Sari Dewi', $this->lastText());

        $this->send($this->textUpdate("#{$langka->id}"));
        $this->assertStringContainsString('Ditemukan 1 advance', $this->lastText());
        $this->assertStringContainsString('PT Langka Sekali', $this->lastText());

        $this->send($this->tap("{$draft->id}:adv:{$langka->id}"));

        $draft = Draft::load($this->chatId);
        $this->assertSame($langka->id, $draft->advanceId);
        $this->assertNull($draft->awaiting);
        $this->assertStringContainsString('Draft Realisasi', $this->lastText());
    }

    public function test_expense_finance_tidak_ditahan_advance_karyawan_lain(): void
    {
        $budi = $this->employee('Budi', 'Santoso');
        $this->openAdvance($budi, $this->project('PT Belamy Indonesia', 'Booth Pameran'));
        $this->user()->assignRole('Finance');
        $this->fakeApis(gemini: null);

        // Cek klaim ganda hanya untuk advance milik pengaju sendiri.
        $this->send($this->textUpdate('expense grab 150rb ke kantor klien belamy'));

        $this->assertStringNotContainsString('dibayar dari advance', $this->lastText());
        $this->assertStringContainsString('Draft Expense', $this->lastText());
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

        // Langsung diberi tahu, bukan setelah mengisi semua lalu menekan Kirim.
        $this->assertStringContainsString("⛔ Advance #{$advance->id}", $this->lastText());
        $this->assertStringContainsString('menyetujui overrun', $this->lastText());
        $this->assertContains('💰 Jadikan Expense', $this->lastButtons());

        // Tombol Kirim lama pun tetap ditolak RealisasiService.
        $draft = Draft::load($this->chatId);
        $this->send($this->tap("{$draft->id}:submit:"));
        $this->assertSame(1, Realisasi::where('advance_id', $advance->id)->count());
    }

    public function test_daftar_advance_menandai_yang_belum_bisa_direalisasi(): void
    {
        $user    = $this->user();
        $blocked = $this->openAdvance($user, $this->project('PT Nestory Indonesia', 'Video Profile'), 1_000_000);
        $this->openAdvance($user, $this->project('PT Belamy Indonesia', 'Booth Pameran'));

        app(RealisasiService::class)->createBatch(
            $blocked,
            ['uploaded_by' => $user->id],
            [['name' => 'Sewa kamera', 'quantity' => 1, 'price' => 1_200_000]]
        );

        $this->fakeApis(gemini: null);
        $this->send($this->textUpdate('realisasi 50rb parkir'));

        $this->assertStringContainsString("#{$blocked->id} · <b>Advance Video Profile</b>", $this->lastText());
        $this->assertStringContainsString('💸 Cair Rp 1.000.000 · melebihi <b>Rp 200.000</b>', $this->lastText());
        $this->assertStringContainsString('⛔ Realisasi melebihi advance. Finance harus menyetujui overrun terlebih dahulu.', $this->lastText());
    }

    public function test_karyawan_dengan_banyak_advance_bisa_melihat_semua_dan_mencari_lewat_id(): void
    {
        $user = $this->user();
        $old  = $this->openAdvance($user, $this->project('PT Lama Sekali', 'Event Lama'));

        foreach (range(1, 9) as $n) {
            $this->openAdvance($user, $this->project("PT Klien {$n}", "Project {$n}"));
        }

        $this->fakeApis(gemini: null);
        $this->send($this->textUpdate('realisasi 100rb parkir'));
        $draft = Draft::load($this->chatId);

        $this->assertStringContainsString('<b>10 advance terbuka</b>, halaman 1 dari 2', $this->lastText());
        $this->assertStringContainsString('Ketik ID advance', $this->lastText());
        $this->assertStringNotContainsString('nama pengaju', $this->lastText());

        $this->send($this->tap("{$draft->id}:advlist:1"));
        $this->assertStringContainsString("#{$old->id} · <b>Advance Event Lama</b>", $this->lastText());

        $this->send($this->textUpdate("id {$old->id}"));
        $this->assertContains("#{$old->id} · Advance Event Lama", $this->lastButtons());
    }

    // =================================================================
    // Banyak struk (batch)
    // =================================================================

    public function test_album_realisasi_jadi_satu_batch_dengan_struk_per_item(): void
    {
        Storage::fake('public');
        $user    = $this->user();
        $advance = $this->openAdvance($user, $this->project('PT Nestory Indonesia', 'Video Profile'));
        $this->fakeApis(gemini: null);

        $foto = [
            $this->photoUpdate('r1', 'realisasi nestory', album: 'g1'),
            $this->photoUpdate('r2', album: 'g1'),
            $this->photoUpdate('r3', album: 'g1'),
        ];
        array_map(fn ($update) => $this->send($update), $foto);

        // Nominal ditanyakan per struk sambil membalas foto yang dimaksud.
        $this->assertLastQuestionRepliesTo($foto[0]);
        $this->send($this->textUpdate('120rb sewa lighting'));
        $this->assertLastQuestionRepliesTo($foto[1]);
        $this->send($this->textUpdate('85rb konsumsi kru'));
        $this->assertLastQuestionRepliesTo($foto[2]);
        $this->send($this->textUpdate('15000'));

        $this->assertStringContainsString('Draft Realisasi — 3 struk', $this->lastText());
        $this->assertStringContainsString('Rp 220.000', $this->lastText());

        $draft = Draft::load($this->chatId);
        $this->send($this->tap("{$draft->id}:submit:"));

        $batch = Realisasi::where('advance_id', $advance->id)->with('items.files')->sole();
        $this->assertSame(['Sewa lighting', 'Konsumsi kru'], $batch->items->take(2)->pluck('name')->all());
        $this->assertEquals([120_000, 85_000, 15_000], $batch->items->pluck('price')->map(fn ($p) => (float) $p)->all());

        foreach ($batch->items as $item) {
            $this->assertCount(1, $item->files, 'Setiap item punya strukny sendiri.');
            Storage::disk('public')->assertExists($item->files->first()->filepath);
        }

        $this->assertSame(3, DB::table('telegram_receipts')->where('user_id', $user->id)->count());
    }

    public function test_total_di_caption_dicocokkan_dengan_jumlah_struk(): void
    {
        $user = $this->user();
        $this->openAdvance($user, $this->project('PT Nestory Indonesia', 'Video Profile'));
        $this->fakeApis(gemini: null);

        $this->send($this->photoUpdate('t1', 'realisasi 250rb nestory', album: 'g2'));
        $this->send($this->photoUpdate('t2', album: 'g2'));
        $this->send($this->textUpdate('120rb'));
        $this->send($this->textUpdate('100rb'));

        $this->assertStringContainsString('Jumlah struk Rp 220.000 berbeda dengan Rp 250.000', $this->lastText());
    }

    public function test_album_expense_jadi_beberapa_pengajuan_dengan_struk_masing_masing(): void
    {
        Storage::fake('public');
        $user = $this->user();
        $this->project('PT Nestory Indonesia', 'Video Profile');
        $this->fakeApis(gemini: null);

        $this->send($this->photoUpdate('e1', 'expense grab nestory', album: 'g3'));
        $this->send($this->photoUpdate('e2', album: 'g3'));
        $this->send($this->textUpdate('45rb grab berangkat'));
        $this->send($this->textUpdate('30rb grab pulang'));

        $this->assertStringContainsString('Draft Expense — 2 struk', $this->lastText());

        $draft = Draft::load($this->chatId);
        $this->send($this->tap("{$draft->id}:submit:"));

        $expenses = Expense::where('user_id', $user->id)->orderBy('id')->get();
        $this->assertCount(2, $expenses);
        $this->assertEquals([45_000, 30_000], $expenses->pluck('amount')->map(fn ($a) => (float) $a)->all());
        $this->assertSame(['Grab berangkat', 'Grab pulang'], $expenses->pluck('keterangan_expense')->all());
        $this->assertNotSame($expenses[0]->attachment, $expenses[1]->attachment, 'Masing-masing dengan strukny sendiri.');
        $this->assertStringContainsString('2 expense terkirim', $this->lastText());
    }

    public function test_struk_yang_sama_tidak_bisa_masuk_dua_kali_di_draft(): void
    {
        $this->user();
        $this->fakeApis(gemini: null);

        $this->send($this->photoUpdate('sama'));
        $this->send($this->photoUpdate('sama'));

        $this->assertStringContainsString('sudah ada di draft', $this->lastText());
        $this->assertSame(1, Draft::load($this->chatId)->receiptCount());
    }

    public function test_struk_yang_sudah_pernah_dikirim_ditolak(): void
    {
        Storage::fake('public');
        $user = $this->user();
        $this->project('PT Nestory Indonesia', 'Video Profile');
        $this->fakeApis(gemini: null);

        $this->send($this->photoUpdate('lama', 'expense 150rb grab ke kantor klien nestory'));
        $this->send($this->tap(Draft::load($this->chatId)->id . ':submit:'));

        $this->send($this->photoUpdate('lama', 'expense 150rb grab lagi nestory'));

        $this->assertStringContainsString('sudah pernah dikirim', $this->lastText());
        $this->assertSame(1, Expense::where('user_id', $user->id)->count());
    }

    public function test_foto_dengan_keterangan_tanpa_jenis_didahulukan_sebagai_realisasi(): void
    {
        Storage::fake('public');
        $user    = $this->user();
        $advance = $this->openAdvance($user, $this->project('PT Belamy Indonesia', 'Booth Pameran'));
        $this->fakeApis(gemini: null);

        $this->send($this->photoUpdate('nota-tanpa-jenis', 'sewa lighting 265rb belamy'));

        $this->assertStringContainsString('Draft Realisasi', $this->lastText());
        $this->assertStringContainsString("Advance #{$advance->id}", $this->lastText());
        $this->assertStringContainsString('Struk/invoice dianggap realisasi advance', $this->lastText());

        // Ternyata dibayar sendiri: satu tombol jadi expense.
        $draft = Draft::load($this->chatId);
        $this->send($this->tap("{$draft->id}:type:expense"));
        $this->assertSame('expense', Draft::load($this->chatId)->type);
        $this->assertStringNotContainsString('Draft Realisasi', $this->lastText());
    }

    public function test_foto_untuk_project_tanpa_advance_terbuka_tidak_dianggap_realisasi(): void
    {
        Storage::fake('public');
        $user = $this->user();
        $this->openAdvance($user, $this->project('PT Belamy Indonesia', 'Booth Pameran'));
        $this->project('PT Nestory Indonesia', 'Video Profile');
        $this->fakeApis(gemini: null);

        $this->send($this->photoUpdate('nota-nestory', 'grab 50rb nestory'));

        $this->assertNotSame('realisasi', Draft::load($this->chatId)->type);
        $this->assertStringNotContainsString('Draft Realisasi', $this->lastText());
    }

    public function test_foto_dengan_kata_expense_tetap_expense_walau_ada_advance(): void
    {
        Storage::fake('public');
        $user = $this->user();
        $this->openAdvance($user, $this->project('PT Belamy Indonesia', 'Booth Pameran'));
        $this->fakeApis(gemini: null);

        $this->send($this->photoUpdate('nota-expense', 'expense grab 50rb belamy'));

        $this->assertSame('expense', Draft::load($this->chatId)->type);
    }

    public function test_struk_tidak_terbaca_karena_saldo_gemini_habis_diberi_tahu(): void
    {
        Storage::fake('public');
        $this->user();
        $this->fakeApis(gemini: null);

        $this->send($this->photoUpdate('nota-saldo'));

        $this->assertStringContainsString('📎 Struk diterima.', $this->lastText());
        $this->assertStringContainsString('Baca struk otomatis sedang tidak tersedia: Saldo prabayar Gemini habis', $this->lastText());
    }

    public function test_struk_yang_isinya_tidak_terbaca_diberi_tahu(): void
    {
        Storage::fake('public');
        $this->user();
        $this->fakeApis(gemini: ['amount' => null, 'name' => null, 'description' => null, 'date_text' => null, 'date' => null, 'category' => null, 'items' => []]);

        $this->send($this->photoUpdate('nota-buram'));

        $this->assertStringContainsString('Isi struk tidak terbaca otomatis', $this->lastText());
    }

    public function test_foto_dengan_keterangan_tetap_dibaca_rinciannya(): void
    {
        Storage::fake('public');
        $user    = $this->user();
        $advance = $this->openAdvance($user, $this->project('PT Belamy Indonesia', 'Booth Pameran'));
        $this->openAdvance($user, $this->project('PT Nestory Indonesia', 'Video Profile'));
        $this->fakeApis(gemini: $this->receiptRead());

        // Dulu hanya totalnya yang terbaca bila foto dikirim dengan keterangan.
        $this->send($this->photoUpdate('nota-caption', "realisasi #{$advance->id}"));

        $this->assertStringContainsString('Draft Realisasi', $this->lastText());
        $this->assertStringContainsString('Kabel HDMI — 2 × Rp 125.000', $this->lastText());
        $this->assertSame([['name' => 'Kabel HDMI', 'qty' => 2, 'price' => 125_000], ['name' => 'Lakban', 'qty' => 1, 'price' => 15_000]], Draft::load($this->chatId)->lineItems());
    }

    public function test_upload_lalu_ketik_realisasi_langsung_ke_pilihan_advance(): void
    {
        Storage::fake('public');
        $user = $this->user();
        $this->openAdvance($user, $this->project('PT Belamy Indonesia', 'Booth Pameran'));
        $this->openAdvance($user, $this->project('PT Nestory Indonesia', 'Video Profile'));
        $this->fakeApis(gemini: $this->receiptRead());

        $this->send($this->photoUpdate('nota-lalu-ketik'));
        $this->send($this->textUpdate('Realisasi'));

        $this->assertSame('realisasi', Draft::load($this->chatId)->type);
        $this->assertStringContainsString('Realisasi untuk advance yang mana?', $this->lastText());
        $this->assertStringContainsString('<b>Advance Booth Pameran</b>', $this->lastText());
        $this->assertStringContainsString('<b>Advance Video Profile</b>', $this->lastText());
    }

    public function test_listadvance_menampilkan_advance_terbuka_dengan_id_per_halaman(): void
    {
        $user = $this->user();
        $old  = $this->openAdvance($user, $this->project('PT Lama Sekali', 'Event Lama'));

        foreach (range(1, 8) as $n) {
            $this->openAdvance($user, $this->project("PT Klien {$n}", "Project {$n}"));
        }

        $other = $this->openAdvance($this->employee('Budi', 'Santoso'), $this->project('PT Orang Lain', 'Booth Lain'));
        $this->fakeApis(gemini: null);

        $this->send($this->textUpdate('/listadvance'));

        $this->assertStringContainsString('📋 <b>Advance terbuka milikmu</b> · 9 advance · halaman 1 dari 2', $this->lastText());
        $this->assertStringContainsString('<b>Advance Project 8</b>', $this->lastText());
        $this->assertStringContainsString('💸 Cair Rp 2.000.000 · sisa <b>Rp 2.000.000</b>', $this->lastText());
        $this->assertStringNotContainsString('PT Orang Lain', $this->lastText());
        $this->assertContains('Berikutnya ➡️', $this->lastButtons());

        // Halaman berikutnya tanpa draft.
        $this->send($this->tap('menu:advances:1'));
        $this->assertStringContainsString("#{$old->id} · <b>Advance Event Lama</b>", $this->lastText());
        $this->assertStringContainsString('halaman 2 dari 2', $this->lastText());

        // Cari lewat kata atau ID; advance orang lain tetap tidak terlihat.
        $this->send($this->textUpdate('/listadvance lama'));
        $this->assertStringContainsString("#{$old->id} · <b>Advance Event Lama</b>", $this->lastText());
        $this->assertStringContainsString('· 1 advance', $this->lastText());

        $this->send($this->textUpdate("/listadvance {$other->id}"));
        $this->assertStringContainsString('Tidak ada advance terbuka milikmu yang cocok', $this->lastText());
    }

    public function test_listadvance_finance_melihat_semua_advance(): void
    {
        $budi    = $this->employee('Budi', 'Santoso');
        $advance = $this->openAdvance($budi, $this->project('PT Belamy Indonesia', 'Booth Pameran'));
        $this->user()->assignRole('Finance');
        $this->fakeApis(gemini: null);

        $this->send($this->textUpdate("/listadvance {$advance->id}"));

        $this->assertStringContainsString("#{$advance->id} · <b>Advance Booth Pameran</b>", $this->lastText());
        $this->assertStringContainsString('👤 Budi Santoso', $this->lastText());
    }

    public function test_realisasi_dengan_id_advance_langsung_memilih_advance_itu(): void
    {
        Storage::fake('public');
        $user   = $this->user();
        $target = $this->openAdvance($user, $this->project('PT Belamy Indonesia', 'Booth Pameran'));
        $this->openAdvance($user, $this->project('PT Nestory Indonesia', 'Video Profile'));
        $this->fakeApis(gemini: null);

        // Tanpa nama project pun: ID-nya langsung memilih advance, bukan daftar.
        $this->send($this->photoUpdate('nota-id', "realisasi #{$target->id} parkir 50rb"));

        $draft = Draft::load($this->chatId);
        $this->assertSame($target->id, $draft->advanceId);
        $this->assertSame(50_000, $draft->amount, 'ID advance tidak terbaca sebagai nominal.');
        $this->assertStringContainsString('Draft Realisasi', $this->lastText());
        $this->assertStringContainsString("Advance #{$target->id}", $this->lastText());
    }

    public function test_id_advance_yang_tidak_ada_diberi_tahu(): void
    {
        $user = $this->user();
        $this->openAdvance($user, $this->project('PT Belamy Indonesia', 'Booth Pameran'));
        $this->openAdvance($user, $this->project('PT Nestory Indonesia', 'Video Profile'));
        $this->fakeApis(gemini: null);

        $this->send($this->textUpdate('realisasi #999999999 parkir 50rb'));

        $texts = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $request) => str_ends_with($request->url(), '/sendMessage'))
            ->map(fn (Request $request) => $request->data()['text'])
            ->implode("\n");
        $this->assertStringContainsString('Advance #999999999 tidak ada di daftar advance terbuka milikmu', $texts);
        $this->assertStringContainsString('Realisasi untuk advance yang mana?', $this->lastText());
    }

    public function test_pesan_suara_ditranskrip_lalu_diproses_seperti_ketikan(): void
    {
        $this->user();
        $project = $this->project('PT Nestory Indonesia', 'Video Profile');
        $this->fakeApis(gemini: ['text' => 'expense 50rb grab ke kantor nestory']);

        $this->send($this->voiceUpdate(duration: 6));

        $texts = collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $request) => str_ends_with($request->url(), '/sendMessage'))
            ->map(fn (Request $request) => $request->data()['text']);
        $this->assertTrue($texts->contains(fn ($text) => str_contains($text, '🎙️ Terdengar: <i>«expense 50rb grab ke kantor nestory»</i>')));

        $draft = Draft::load($this->chatId);
        $this->assertSame(['expense', 50_000, $project->id], [$draft->type, $draft->amount, $draft->projectId]);
    }

    public function test_pesan_suara_yang_gagal_dibaca_atau_terlalu_panjang(): void
    {
        $this->user();
        $this->fakeApis(gemini: null);

        $this->send($this->voiceUpdate(duration: 200));
        $this->assertStringContainsString('maksimal 90 detik', $this->lastText());

        $this->send($this->voiceUpdate(duration: 5));
        $this->assertStringContainsString('Pesan suara belum bisa dibaca: Saldo prabayar Gemini habis', $this->lastText());
        $this->assertNull(Draft::load($this->chatId));
    }

    private function voiceUpdate(int $duration): array
    {
        return [
            'update_id' => 700_000_000 + ++$this->updateId,
            'message'   => [
                'message_id' => 10 + $this->updateId,
                'chat'       => ['id' => $this->chatId, 'type' => 'private'],
                'from'       => ['id' => $this->chatId, 'is_bot' => false, 'first_name' => 'Tes'],
                'voice'      => ['file_id' => 'suara-' . $this->updateId, 'file_unique_id' => 'us-' . $this->updateId, 'duration' => $duration, 'mime_type' => 'audio/ogg', 'file_size' => 4_000],
            ],
        ];
    }

    public function test_satu_struk_dibagi_ke_dua_project(): void
    {
        Storage::fake('public');
        $user    = $this->user();
        $nestory = $this->project('PT Nestory Indonesia', 'Video Profile');
        $belamy  = $this->project('PT Belamy Indonesia', 'Booth Pameran');
        $this->fakeApis(gemini: null);

        $this->send($this->photoUpdate('struk-bagi', 'expense grab tim 150rb nestory'));
        $draft = Draft::load($this->chatId);
        $this->assertContains('🔀 Bagi ke beberapa project', $this->lastButtons());

        $this->send($this->tap("{$draft->id}:split:"));
        $this->assertStringContainsString('Total harus <b>Rp 150.000</b>', $this->lastText());

        // Jumlah yang tidak pas ditanyakan ulang.
        $this->send($this->textUpdate('nestory 100rb, belamy 20rb'));
        $this->assertStringContainsString('harus sama dengan total struk Rp 150.000', $this->lastText());

        $this->send($this->textUpdate('nestory 100rb, belamy sisanya'));
        $this->assertStringContainsString('Dibagi ke 2 project', $this->lastText());
        $this->assertStringContainsString('<b>Rp 50.000</b>', $this->lastText());
        $this->assertContains('✅ Kirim 2 expense', $this->lastButtons());

        $this->send($this->tap("{$draft->id}:submit:"));

        $expenses = Expense::where('user_id', $user->id)->orderBy('id')->get();
        $this->assertCount(2, $expenses);
        $this->assertSame([$nestory->id, $belamy->id], $expenses->pluck('project_id')->map(fn ($id) => (int) $id)->all());
        $this->assertEquals([100_000, 50_000], $expenses->pluck('amount')->map(fn ($amount) => (float) $amount)->all());
        $this->assertStringContainsString('(bagian 2/2 dari Rp 150.000)', $expenses[1]->keterangan_expense);
        $this->assertNotSame($expenses[0]->attachment, $expenses[1]->attachment, 'Masing-masing punya salinan struk.');
        Storage::disk('public')->assertExists([$expenses[0]->attachment, $expenses[1]->attachment]);
        $this->assertStringContainsString('2 Expense (#', $this->lastText());
    }

    public function test_struk_yang_difoto_ulang_diberi_peringatan_mirip(): void
    {
        Storage::fake('public');
        $user = $this->user();
        $this->project('PT Nestory Indonesia', 'Video Profile');
        $this->fakeApis(gemini: $this->receiptRead());

        $this->send($this->photoUpdate('struk-asli', 'expense kabel nestory'));
        $draft = Draft::load($this->chatId);
        $this->assertStringNotContainsString('irip struk', $this->lastText());
        $this->send($this->tap("{$draft->id}:submit:"));
        $this->assertSame(1, Expense::where('user_id', $user->id)->count());

        // File Telegram berbeda (difoto ulang), isi struknya sama.
        $this->send($this->photoUpdate('struk-foto-ulang', 'expense kabel nestory'));

        $this->assertStringContainsString('⚠️ Mirip struk yang sudah diajukan: Expense', $this->lastText());
        $this->assertStringContainsString('Rp 265.000', $this->lastText());
    }

    public function test_sidik_jari_gambar_sama_dan_beda(): void
    {
        $image = function (int $seed): string {
            // Gradasi kiri→kanan (seed 1) atau kanan→kiri (seed lain).
            $gd = imagecreatetruecolor(180, 120);
            for ($x = 0; $x < 180; $x += 10) {
                $level = $seed === 1 ? $x : 179 - $x;
                imagefilledrectangle($gd, $x, 0, $x + 9, 119, imagecolorallocate($gd, $level, $level, $level));
            }
            ob_start();
            imagejpeg($gd, null, 90);

            return ob_get_clean();
        };

        $a = \App\Services\Telegram\ReceiptFingerprint::hash($image(1));
        $b = \App\Services\Telegram\ReceiptFingerprint::hash($image(1));
        $c = \App\Services\Telegram\ReceiptFingerprint::hash($image(7));

        $this->assertSame(16, strlen($a));
        $this->assertSame(0, \App\Services\Telegram\ReceiptFingerprint::distance($a, $b));
        $this->assertGreaterThan(5, \App\Services\Telegram\ReceiptFingerprint::distance($a, $c));
        $this->assertNull(\App\Services\Telegram\ReceiptFingerprint::hash('%PDF-1.4'), 'PDF tidak diberi sidik jari gambar.');
    }

    public function test_satu_struk_boleh_untuk_realisasi_beberapa_advance(): void
    {
        Storage::fake('public');
        $user    = $this->user();
        $nestory = $this->openAdvance($user, $this->project('PT Nestory Indonesia', 'Video Profile'));
        $belamy  = $this->openAdvance($user, $this->project('PT Belamy Indonesia', 'Booth Pameran'));
        $this->fakeApis(gemini: null);

        $this->send($this->photoUpdate('struk-bersama', 'realisasi 300rb konsumsi shooting nestory'));
        $this->send($this->tap(Draft::load($this->chatId)->id . ':submit:'));

        // Struk yang sama untuk advance lain: boleh.
        $this->send($this->photoUpdate('struk-bersama', 'realisasi 200rb konsumsi shooting belamy'));
        $this->assertSame($belamy->id, Draft::load($this->chatId)->advanceId);
        $this->send($this->tap(Draft::load($this->chatId)->id . ':submit:'));

        $this->assertStringContainsString('Realisasi terkirim', $this->lastText());
        $this->assertEquals(300_000, Realisasi::where('advance_id', $nestory->id)->first()->items->sum('price'));
        $this->assertEquals(200_000, Realisasi::where('advance_id', $belamy->id)->first()->items->sum('price'));

        // Untuk advance yang sama lagi: ditolak.
        $this->send($this->photoUpdate('struk-bersama', 'realisasi 50rb konsumsi shooting belamy'));
        $this->send($this->tap(Draft::load($this->chatId)->id . ':submit:'));

        $this->assertStringContainsString('sudah pernah dikirim', $this->lastText());
        $this->assertSame(1, Realisasi::where('advance_id', $belamy->id)->count());

        // Sebagai expense: ditolak, supaya tidak tercatat dua kali.
        Draft::forget($this->chatId);
        $this->send($this->photoUpdate('struk-bersama', 'expense 150rb grab ke kantor klien nestory'));
        $this->send($this->tap(Draft::load($this->chatId)->id . ':submit:'));

        $this->assertStringContainsString('sudah pernah dikirim', $this->lastText());
        $this->assertSame(0, Expense::where('user_id', $user->id)->count());
    }

    public function test_struk_expense_tidak_bisa_dipakai_untuk_realisasi(): void
    {
        Storage::fake('public');
        $user    = $this->user();
        $advance = $this->openAdvance($user, $this->project('PT Nestory Indonesia', 'Video Profile'));
        $this->fakeApis(gemini: null);

        $this->send($this->photoUpdate('struk-expense', 'expense 150rb grab ke kantor klien nestory'));
        $this->send($this->tap(Draft::load($this->chatId)->id . ':submit:'));

        $this->send($this->photoUpdate('struk-expense', 'realisasi 150rb grab nestory'));

        $this->assertStringContainsString('sudah pernah dikirim', $this->lastText());
        $this->assertSame(0, Realisasi::where('advance_id', $advance->id)->count());
    }

    public function test_foto_susulan_jadi_struk_kedua_dan_nominal_struk_pertama_tetap(): void
    {
        $user = $this->user();
        $this->openAdvance($user, $this->project('PT Nestory Indonesia', 'Video Profile'));
        $this->fakeApis(gemini: null);

        $this->send($this->photoUpdate('s1', 'realisasi 350rb sewa lighting nestory'));
        $this->send($this->photoUpdate('s2'));

        $draft = Draft::load($this->chatId);
        $this->assertSame(350_000, $draft->receipts[0]['amount'], 'Nominal yang sudah ditulis milik struk pertama.');
        $this->assertSame('Sewa lighting nestory', $draft->receipts[0]['name']);
        $this->assertNull($draft->amount, 'Bukan total album, jadi tidak dicocokkan.');
        $this->assertSame('receipt:1', $draft->awaiting, 'Hanya struk kedua yang ditanyakan.');
    }

    public function test_nominal_struk_bisa_diubah_dan_struk_bisa_dihapus(): void
    {
        $user = $this->user();
        $this->openAdvance($user, $this->project('PT Nestory Indonesia', 'Video Profile'));
        $this->fakeApis(gemini: null);

        $this->send($this->photoUpdate('u1', 'realisasi nestory', album: 'g4'));
        $this->send($this->photoUpdate('u2', album: 'g4'));
        $this->send($this->textUpdate('100rb lighting'));
        $this->send($this->textUpdate('50rb parkir'));
        $draft = Draft::load($this->chatId);

        $this->send($this->tap("{$draft->id}:ramt:0"));
        $this->assertStringContainsString('Struk 1', $this->lastText());
        $this->send($this->textUpdate('130rb'));
        $this->assertSame(130_000, Draft::load($this->chatId)->receipts[0]['amount']);

        // Tinggal satu struk: kembali ke draft biasa dengan nominal struk itu.
        $this->send($this->tap("{$draft->id}:rdel:1"));
        $draft = Draft::load($this->chatId);
        $this->assertFalse($draft->isMulti());
        $this->assertSame(130_000, $draft->amount);
        $this->assertSame('u1', $draft->attachment['file_id']);
        $this->assertStringContainsString('Draft Realisasi', $this->lastText());
    }

    public function test_advance_dengan_5_gambar_jadi_satu_pengajuan_dengan_semua_lampiran(): void
    {
        Storage::fake('public');
        $user = $this->user();
        $this->project('PT Nestory Indonesia', 'Video Profile');
        $this->fakeApis(gemini: null);

        $this->send($this->photoUpdate('adv1', 'advance 2jt sewa kamera nestory', album: 'g5'));
        foreach (['adv2', 'adv3', 'adv4', 'adv5'] as $foto) {
            $this->send($this->photoUpdate($foto, album: 'g5'));
        }

        // Lampiran advance hanya pendukung: tidak ada pertanyaan nominal per gambar.
        $this->assertFalse(
            collect(Http::recorded())->contains(fn ($pair) => isset($pair[0]->data()['reply_parameters'])),
            'Nominal tidak ditanyakan per gambar untuk advance.'
        );

        $draft = Draft::load($this->chatId);
        $this->assertTrue($draft->isComplete());
        $this->assertSame(2_000_000, $draft->amount);
        $this->assertStringContainsString('Draft Advance', $this->lastText());
        $this->assertStringContainsString('5 file', $this->lastText());

        $this->send($this->tap("{$draft->id}:submit:"));

        $advances = Advance::where('user_id', $user->id)->get();
        $this->assertCount(1, $advances, 'Satu pengajuan, bukan lima.');

        $advance = $advances->first();
        $this->assertEquals(2_000_000, $advance->amount);
        $this->assertCount(5, $advance->attachments);
        $this->assertSame($advance->attachments[0], $advance->attachment, 'Kolom lama tetap berisi lampiran pertama.');
        foreach ($advance->attachmentPaths() as $path) {
            Storage::disk('public')->assertExists($path);
        }
    }

    public function test_lampiran_advance_yang_salah_bisa_dihapus(): void
    {
        Storage::fake('public');
        $user = $this->user();
        $this->project('PT Nestory Indonesia', 'Video Profile');
        $this->fakeApis(gemini: null);

        $this->send($this->photoUpdate('b1', 'advance 2jt sewa kamera nestory', album: 'g7'));
        $this->send($this->photoUpdate('b2', album: 'g7'));
        $this->send($this->photoUpdate('b3', album: 'g7'));

        $draft = Draft::load($this->chatId);
        $this->send($this->tap("{$draft->id}:rdel:1"));

        $this->assertSame(['b1', 'b3'], array_column(Draft::load($this->chatId)->receipts, 'file_id'));
        $this->assertStringContainsString('2 file', $this->lastText());

        $this->send($this->tap("{$draft->id}:submit:"));
        $this->assertCount(2, Advance::where('user_id', $user->id)->sole()->attachments);
    }

    public function test_foto_susulan_advance_tidak_mengubah_nominal_advance(): void
    {
        $this->user();
        $this->project('PT Nestory Indonesia', 'Video Profile');
        $this->fakeApis(gemini: null);

        $this->send($this->photoUpdate('c1', 'advance 2jt sewa kamera nestory'));
        $this->send($this->photoUpdate('c2'));

        $draft = Draft::load($this->chatId);
        $this->assertSame(2, $draft->receiptCount());
        $this->assertSame(2_000_000, $draft->amount);
        $this->assertTrue($draft->isComplete());
    }

    public function test_nominal_tiap_struk_album_dibaca_gemini(): void
    {
        $user = $this->user();
        $this->openAdvance($user, $this->project('PT Nestory Indonesia', 'Video Profile'));
        $this->fakeApis(gemini: ['type' => 'realisasi', 'amount' => 75_000, 'name' => 'Toko Lampu', 'description' => null, 'category' => null, 'project_id' => null, 'date' => null]);

        $this->send($this->photoUpdate('ai1', 'realisasi nestory', album: 'g6'));
        $this->send($this->photoUpdate('ai2', album: 'g6'));

        $draft = Draft::load($this->chatId);
        $this->assertSame([75_000, 75_000], array_column($draft->receipts, 'amount'));
        $this->assertTrue($draft->isComplete(), 'Tidak ada nominal yang perlu ditanyakan.');
        $this->assertStringContainsString('Draft Realisasi — 2 struk', $this->lastText());
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

    /**
     * Karyawan lain pemilik advance; tidak terhubung ke chat tes.
     */
    private function employee(string $firstname, string $lastname): User
    {
        $user = $this->user(linked: false);
        $user->forceFill(['firstname' => $firstname, 'lastname' => $lastname])->save();

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
            ['file_id' => "{$fileId}-kecil", 'file_unique_id' => "{$fileId}-kecil-u", 'file_size' => 900, 'width' => 90, 'height' => 90],
            ['file_id' => $fileId, 'file_unique_id' => "{$fileId}-u", 'file_size' => 48_000, 'width' => 1280, 'height' => 960],
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
     * Pertanyaan nominal terakhir harus membalas (mengutip) foto struk yang dimaksud.
     */
    private function assertLastQuestionRepliesTo(array $photoUpdate): void
    {
        $question = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $request) => str_ends_with($request->url(), '/sendMessage') && isset($request->data()['reply_parameters']))
            ->last();

        $this->assertNotNull($question, 'Belum ada pertanyaan yang membalas foto.');
        $this->assertSame($photoUpdate['message']['message_id'], $question->data()['reply_parameters']['message_id']);
    }

    /**
     * Teks terakhir yang dikirim atau diubah bot.
     */
    /**
     * Jawaban Gemini untuk satu struk (readReceipt). Juga dibaca parse() bila
     * user mengetik: kunci yang tidak dikenalnya diabaikan.
     */
    private function receiptRead(?array $items = null, int $amount = 265_000): array
    {
        return [
            'amount'      => $amount,
            'name'        => 'Toko Jaya Elektrik',
            'description' => 'Kabel HDMI dan lakban',
            'date'        => today()->subDays(2)->toDateString(),
            'category'    => 'Project Support',
            'items'       => $items ?? [
                ['name' => 'Kabel HDMI', 'qty' => 2, 'price' => 125_000],
                ['name' => 'Lakban', 'qty' => 1, 'price' => 15_000],
            ],
        ];
    }

    /**
     * @return array<int, string> teks tombol pesan terakhir
     */
    private function lastButtons(): array
    {
        $request = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $request) => preg_match('#/(sendMessage|editMessageText)$#', $request->url()))
            ->last();

        return collect($request?->data()['reply_markup']['inline_keyboard'] ?? [])->flatten(1)->pluck('text')->all();
    }

    private function lastText(): string
    {
        return (string) collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $request) => preg_match('#/(sendMessage|editMessageText)$#', $request->url()))
            ->last()?->data()['text'];
    }
}
