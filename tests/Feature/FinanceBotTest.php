<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use App\Settings\IntegrationSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Bot laporan keuangan: terpisah dari bot pengajuan, hanya untuk Finance,
 * Director, dan super_admin.
 */
class FinanceBotTest extends TestCase
{
    use DatabaseTransactions;

    private const SECRET = 'rahasia-bot-finance';

    private int $chatId = 990_000_101;

    private int $updateId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        IntegrationSettings::fake([
            'gemini_api_key'          => null,
            'gemini_model'            => 'gemini-3.5-flash-lite',
            'telegram_bot_token'      => '111:PENGAJUAN',
            'telegram_webhook_secret' => 'rahasia-bot-pengajuan',
            'finance_bot_token'       => '222:FINANCE',
            'finance_webhook_secret'  => self::SECRET,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    }

    public function test_finance_melihat_ringkasan_lewat_token_bot_finance(): void
    {
        $this->user('Finance');

        $this->send($this->text('/start'));

        $this->assertStringContainsString('Ringkasan keuangan', $this->lastText());
        $this->assertStringContainsString('Advance', $this->lastText());
        $this->assertStringContainsString('Invoice', $this->lastText());

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'bot222:FINANCE/sendMessage'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'bot111:PENGAJUAN'));
    }

    public function test_role_lain_tidak_bisa_melihat_angka(): void
    {
        $this->user('Staff');

        $this->send($this->text('/ringkasan'));

        $this->assertStringContainsString('khusus untuk Finance dan Director', $this->lastText());
        $this->assertStringNotContainsString('Rp', $this->lastText());
    }

    public function test_akun_yang_belum_terhubung_diarahkan_ke_bot_pengajuan(): void
    {
        $this->send($this->text('/ringkasan'));

        $this->assertStringContainsString('belum terhubung', $this->lastText());
    }

    public function test_tombol_menampilkan_daftar_piutang(): void
    {
        $user = $this->user('Director');

        Invoice::create([
            'uuid'            => 'TES/PIUTANG-' . Str::upper(Str::random(5)),
            'user_id'         => $user->id,
            'type'            => 'sale',
            'type_payment'    => 'full_payment',
            'nama_perusahaan' => 'PT Belum Bayar Sekali',
            'grand_total'     => 999_999_999,
            'paid'            => 0,
            'status'          => 'pending',
            'due_date'        => today()->subDays(3),
        ]);

        $this->send($this->tap('fin:piutang'));

        $edited = collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $request) => str_ends_with($request->url(), '/editMessageText'))
            ->last();

        $this->assertNotNull($edited);
        $this->assertStringContainsString('PT Belum Bayar Sekali', $edited['text']);
        $this->assertStringContainsString('lewat 3 hari', $edited['text']);
    }

    public function test_daftar_belum_dibayar_memakai_total_dikurangi_paid(): void
    {
        $user = $this->user('Finance');

        foreach ([['PT Sudah Lunas DP', 'paid'], ['PT Baru Sebagian', 'partial']] as [$client, $status]) {
            Invoice::create([
                'uuid'            => 'TES/DP-' . Str::upper(Str::random(5)),
                'user_id'         => $user->id,
                'type'            => 'sale',
                'type_payment'    => 'dp_40',
                'nama_perusahaan' => $client,
                'grand_total'     => 777_000_000,
                'paid'            => 310_800_000,
                'status'          => $status,
            ]);
        }

        $this->send($this->text('/piutang'));

        $this->assertStringNotContainsString('PT Sudah Lunas DP', $this->lastText(), 'Berstatus paid: tidak ditampilkan.');
        $this->assertStringContainsString('PT Baru Sebagian</b> — sisa Rp 466.200.000', $this->lastText());
    }

    public function test_tombol_excel_mengirim_file_berisi_seluruh_data(): void
    {
        $this->user('Director');

        $this->send($this->tap('fin:excel'));

        $request = collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->first(fn (Request $request) => str_ends_with($request->url(), '/sendDocument'));

        $this->assertNotNull($request, 'File tidak dikirim.');
        $this->assertStringContainsString('bot222:FINANCE', $request->url());

        $document = collect($request->data())->firstWhere('name', 'document');
        $this->assertStringEndsWith('.xlsx', $document['filename']);

        $path = tempnam(sys_get_temp_dir(), 'tes-') . '.xlsx';
        file_put_contents($path, $document['contents']);
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        unlink($path);

        $this->assertSame(['Ringkasan', 'Invoice', 'Expense', 'Advance', 'Per Project'], $book->getSheetNames());
    }

    public function test_akses_dicek_ulang_saat_tombol_ditekan(): void
    {
        $this->user('Staff');

        $this->send($this->tap('fin:ringkasan'));

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/answerCallbackQuery')
            && $request['text'] === 'Tidak punya akses.');
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/editMessageText'));
    }

    public function test_secret_bot_pengajuan_tidak_bisa_masuk_ke_bot_finance(): void
    {
        $this->user('Finance');

        $this->postJson('/api/telegram/finance-webhook', $this->text('/ringkasan'), [
            'X-Telegram-Bot-Api-Secret-Token' => 'rahasia-bot-pengajuan',
        ])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_pesan_dari_grup_diabaikan(): void
    {
        $this->user('Finance');

        $update = $this->text('/ringkasan');
        $update['message']['chat']['type'] = 'group';
        $this->send($update);

        Http::assertNothingSent();
    }

    // =================================================================
    // Helper
    // =================================================================

    private function user(string $role): User
    {
        $user = User::forceCreate([
            'username'           => 'fb_' . Str::lower(Str::random(8)),
            'firstname'          => 'Tes',
            'lastname'           => $role,
            'email'              => Str::lower(Str::random(10)) . '@example.test',
            'password'           => bcrypt('rahasia'),
            'is_active'          => true,
            'push_enabled'       => false,
            'telegram_chat_id'   => (string) $this->chatId,
            'telegram_linked_at' => now(),
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function send(array $update): void
    {
        $this->postJson('/api/telegram/finance-webhook', $update, ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])
            ->assertOk();
    }

    private function text(string $text): array
    {
        return [
            'update_id' => 800_000_000 + ++$this->updateId,
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
            'update_id'      => 800_000_000 + ++$this->updateId,
            'callback_query' => [
                'id'      => 'cb-' . $this->updateId,
                'data'    => $data,
                'message' => ['message_id' => 1, 'chat' => ['id' => $this->chatId, 'type' => 'private']],
            ],
        ];
    }

    private function lastText(): string
    {
        return (string) collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $request) => preg_match('#/(sendMessage|editMessageText)$#', $request->url()))
            ->last()?->data()['text'];
    }
}
