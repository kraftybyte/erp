<?php

namespace Tests\Feature;

use App\Livewire\TelegramConnection;
use App\Models\Advance;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use App\Services\Telegram\Draft;
use App\Services\Telegram\TelegramLinker;
use App\Settings\IntegrationSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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

    private function fakeApis(?array $gemini = null): void
    {
        Http::fake([
            'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'erp_tes_bot']]),
            'api.telegram.org/*'       => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
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
    ): Project {
        return Project::create([
            'nama_project'    => $nama,
            'nama_perusahaan' => $perusahaan,
            'status'          => $status,
        ]);
    }

    private function send(array $update): void
    {
        $this->postJson('/api/telegram/webhook', $update, [
            'X-Telegram-Bot-Api-Secret-Token' => self::SECRET,
        ])->assertOk();
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
