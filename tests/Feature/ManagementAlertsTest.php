<?php

namespace Tests\Feature;

use App\Models\Advance;
use App\Models\Expense;
use App\Models\PaymentAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RealisasiService;
use App\Settings\IntegrationSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Notifikasi ke Director/Finance lewat bot laporan keuangan: pengajuan baru
 * (Setujui/Tolak) dan setiap batch realisasi (verifikasi), beserta tombolnya.
 */
class ManagementAlertsTest extends TestCase
{
    use DatabaseTransactions;

    private const SECRET = 'rahasia-bot-finance';

    private int $chatId = 990_000_404;

    private int $updateId = 0;

    private bool $financeRefuses = false;

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
        Cache::forget("telegram:finance-approval:{$this->chatId}");

        Http::fake(function (Request $request) {
            if ($this->financeRefuses && str_contains($request->url(), 'bot222:FINANCE/sendMessage')) {
                return Http::response(['ok' => false, 'description' => 'Forbidden: bot can\'t initiate conversation with a user'], 403);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 77]]);
        });
    }

    public function test_pengajuan_baru_dikirim_ke_finance_dengan_tombol_setujui_tolak(): void
    {
        $this->manager('Finance');
        $budi    = $this->employee();
        $expense = $this->expense($budi, 'Pending');

        $message = $this->financeMessages()->last();
        $this->assertNotNull($message, 'Finance menerima Telegram lewat bot laporan keuangan.');
        $this->assertStringContainsString("Expense baru #{$expense->id}", $message['text']);
        $this->assertStringContainsString('Grab ke klien', $message['text']);
        $this->assertStringContainsString("apv:ok:expense:{$expense->id}", json_encode($message['reply_markup']));
        $this->assertStringContainsString("apv:no:expense:{$expense->id}", json_encode($message['reply_markup']));
    }

    public function test_pengaju_tidak_menerima_notifikasi_pengajuannya_sendiri(): void
    {
        $finance = $this->manager('Finance');
        $this->expense($finance, 'Pending');

        $this->assertCount(0, $this->financeMessages());
    }

    public function test_auto_approve_dikirim_tanpa_tombol_keputusan(): void
    {
        $this->manager('Director');
        $this->expense($this->employee(), 'Disetujui');

        $message = $this->financeMessages()->last();
        $this->assertStringNotContainsString('apv:ok', json_encode($message['reply_markup'] ?? []));
    }

    public function test_bila_bot_keuangan_belum_dibuka_dikirim_lewat_bot_pengajuan(): void
    {
        $this->financeRefuses = true;
        $this->manager('Finance');
        $expense = $this->expense($this->employee(), 'Pending');

        $fallback = $this->messagesVia('111:PENGAJUAN')->last();
        $this->assertStringContainsString("Expense baru #{$expense->id}", $fallback['text']);
        $this->assertStringContainsString('Buka bot laporan keuangan', $fallback['text']);
        $this->assertStringNotContainsString('apv:ok', json_encode($fallback['reply_markup'] ?? []));
    }

    public function test_finance_menyetujui_dari_tombol(): void
    {
        $finance = $this->manager('Finance');
        $expense = $this->expense($this->employee(), 'Pending');

        $this->send($this->tap("apv:ok:expense:{$expense->id}"));

        $expense->refresh();
        $this->assertSame('Disetujui', $expense->status);
        $this->assertSame($finance->id, (int) $expense->approved_by);
        $this->assertStringContainsString("disetujui oleh {$finance->firstname}", $this->lastText());

        // Tombol yang sama ditekan lagi (atau dari chat Director): tidak diputuskan dua kali.
        $this->send($this->tap("apv:ok:expense:{$expense->id}"));
        $this->assertStringContainsString('sudah diproses: Disetujui', $this->lastText());
    }

    public function test_menolak_menanyakan_alasan_lalu_pengaju_diberi_tahu(): void
    {
        $this->manager('Director');
        $budi    = $this->employee();
        $advance = $this->advance($budi, Advance::STATUS_PENDING, 0);

        $this->send($this->tap("apv:no:advance:{$advance->id}"));
        $this->assertStringContainsString('ditolak?', $this->lastText());

        $this->send($this->text('ok'));
        $this->assertStringContainsString('minimal 3 huruf', $this->lastText());
        $this->assertSame(Advance::STATUS_PENDING, $advance->fresh()->status);

        $this->send($this->text('Belum ada penawaran vendor'));

        $advance->refresh();
        $this->assertSame([Advance::STATUS_DITOLAK, 'Belum ada penawaran vendor'], [$advance->status, $advance->rejection_reason]);
        $this->assertStringContainsString('Pengaju sudah diberi tahu', $this->lastText());
        $this->assertTrue(
            $this->messagesVia('111:PENGAJUAN')->contains(fn ($message) => $message['chat_id'] == $budi->telegram_chat_id && str_contains($message['text'], 'ditolak')),
            'Pengaju menerima penolakan beserta alasannya lewat bot pengajuan.'
        );
    }

    public function test_karyawan_tidak_bisa_menyetujui_lewat_tombol(): void
    {
        $owner   = $this->employee();
        $expense = $this->expense($owner, 'Pending');
        $this->manager(null);

        $this->send($this->tap("apv:ok:expense:{$expense->id}"));

        $this->assertSame('Pending', $expense->fresh()->status);
    }

    public function test_daftar_persetujuan(): void
    {
        $this->manager('Finance');
        $expense = $this->expense($this->employee(), 'Pending');
        $advance = $this->advance($this->employee(), Advance::STATUS_PENDING, 0);

        $this->send($this->text('/persetujuan'));
        $this->assertStringContainsString('Menunggu persetujuan', $this->lastText());

        // Terlama di depan: pengajuan baru ada di halaman terakhir.
        $this->send($this->tap('apv:list:999'));
        $this->assertStringContainsString("Expense #{$expense->id}", $this->lastText());
        $this->assertStringContainsString("Advance #{$advance->id}", $this->lastText());
        $this->assertStringContainsString("apv:ok:advance:{$advance->id}", json_encode($this->lastRequest()->data()['reply_markup']));
    }

    public function test_setiap_batch_realisasi_dikirim_termasuk_lanjutan(): void
    {
        $this->manager('Finance');
        $budi    = $this->employee();
        $advance = $this->advance($budi, Advance::STATUS_DIBAYARKAN, 1_000_000);

        $this->batch($advance, $budi, 400_000);
        $this->batch($advance, $budi, 250_000);

        $alerts = $this->financeMessages()->filter(fn ($message) => str_contains($message['text'], "Realisasi masuk · Advance #{$advance->id}"))->values();
        $this->assertCount(2, $alerts);
        $this->assertStringContainsString('(batch ke-2)', $alerts[1]['text']);
        $this->assertStringContainsString('Terealisasi Rp 650.000 dari Rp 1.000.000 (65%)', $alerts[1]['text']);
        $this->assertStringContainsString("rlz:open:{$advance->id}", json_encode($alerts[1]['reply_markup']));
    }

    public function test_verifikasi_setujui_kelebihan_lalu_selesaikan(): void
    {
        $this->manager('Finance');
        $budi    = $this->employee();
        $advance = $this->advance($budi, Advance::STATUS_DIBAYARKAN, 1_000_000);
        $this->batch($advance, $budi, 1_200_000);

        $this->send($this->text('/verifikasi'));
        $this->send($this->tap('rlz:list:999'));
        $this->assertStringContainsString("⚠️ <b>#{$advance->id}</b>", $this->lastText());

        $this->send($this->tap("rlz:open:{$advance->id}"));
        $this->assertStringContainsString('melebihi advance Rp 200.000', $this->lastText());
        $this->assertStringContainsString("rlz:over:{$advance->id}", json_encode($this->lastRequest()->data()['reply_markup']));

        $this->send($this->tap("rlz:over:{$advance->id}"));
        $this->assertNotNull($advance->fresh()->overrun_approved_by);

        $this->send($this->tap("rlz:fin:{$advance->id}"));
        $this->assertSame(Advance::STATUS_SELESAI, $advance->fresh()->status);
    }

    public function test_pengembalian_sisa_dicatat_lalu_selesai(): void
    {
        $this->manager('Finance');
        $budi    = $this->employee();
        $advance = $this->advance($budi, Advance::STATUS_DIBAYARKAN, 1_000_000);
        $this->batch($advance, $budi, 700_000);

        $this->send($this->tap("rlz:ret:{$advance->id}"));
        $this->assertStringContainsString('Sisa Rp 300.000', $this->lastText());
        $this->assertSame(Advance::STATUS_REALIZED_USER, $advance->fresh()->status, 'Belum dicatat sebelum dipastikan.');

        $this->send($this->tap("rlz:retok:{$advance->id}"));

        $this->assertSame(Advance::STATUS_SELESAI, $advance->fresh()->status);
        $this->assertEquals(300_000, Transaction::where('reference_type', 'advance_return')->where('reference_id', $advance->id)->sole()->amount);
    }

    // =================================================================
    // Helper
    // =================================================================

    private function manager(?string $role): User
    {
        $user = User::forceCreate([
            'username'           => 'mgr_' . Str::lower(Str::random(8)),
            'firstname'          => 'Manajer' . Str::upper(Str::random(3)),
            'lastname'           => $role ?? 'Biasa',
            'email'              => Str::lower(Str::random(10)) . '@example.test',
            'password'           => bcrypt('rahasia'),
            'is_active'          => true,
            'push_enabled'       => false,
            'telegram_chat_id'   => (string) $this->chatId,
            'telegram_linked_at' => now(),
        ]);

        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function employee(): User
    {
        return User::forceCreate([
            'username'           => 'emp_' . Str::lower(Str::random(8)),
            'firstname'          => 'Budi',
            'lastname'           => 'Santoso',
            'email'              => Str::lower(Str::random(10)) . '@example.test',
            'password'           => bcrypt('rahasia'),
            'is_active'          => true,
            'push_enabled'       => false,
            'telegram_chat_id'   => (string) random_int(990_100_000, 990_199_999),
            'telegram_linked_at' => now(),
        ]);
    }

    private function expense(User $owner, string $status): Expense
    {
        return Expense::create(['user_id' => $owner->id, 'submission_date' => today(), 'category' => 'Transport', 'keterangan_expense' => 'Grab ke klien', 'amount' => 187_313, 'paid' => 0, 'status' => $status]);
    }

    private function advance(User $owner, string $status, float $paid): Advance
    {
        $account = PaymentAccount::create(['name' => 'Rek ' . Str::random(4), 'bank_name' => 'BCA', 'account_number' => '1', 'account_holder' => 'Tes', 'initial_balance' => 0, 'is_active' => true]);

        return Advance::create(['user_id' => $owner->id, 'submission_date' => today(), 'category' => 'Project Support', 'keterangan_advance' => 'Sewa alat shooting', 'amount' => max($paid, 500_000), 'paid' => $paid, 'payment_account_id' => $account->id, 'status' => $status]);
    }

    private function batch(Advance $advance, User $by, float $amount): void
    {
        app(RealisasiService::class)->createBatch($advance->fresh(), ['uploaded_by' => $by->id], [['name' => 'Sewa kamera', 'quantity' => 1, 'price' => $amount]]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array>
     */
    private function messagesVia(string $token)
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $request) => str_contains($request->url(), "bot{$token}/sendMessage"))
            ->map(fn (Request $request) => $request->data())
            ->values();
    }

    private function financeMessages()
    {
        return $this->messagesVia('222:FINANCE')->filter(fn ($message) => $message['chat_id'] == $this->chatId)->values();
    }

    private function send(array $update): void
    {
        $this->postJson('/api/telegram/finance-webhook', $update, ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function text(string $text): array
    {
        return [
            'update_id' => 830_000_000 + ++$this->updateId,
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
            'update_id'      => 830_000_000 + ++$this->updateId,
            'callback_query' => [
                'id'      => 'cb-' . $this->updateId,
                'data'    => $data,
                'message' => ['message_id' => 77, 'chat' => ['id' => $this->chatId, 'type' => 'private']],
            ],
        ];
    }

    private function lastRequest(): ?Request
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $request) => str_contains($request->url(), 'bot222:FINANCE') && preg_match('#/(sendMessage|editMessageText)$#', $request->url()))
            ->last();
    }

    private function lastText(): string
    {
        return (string) $this->lastRequest()?->data()['text'];
    }
}
