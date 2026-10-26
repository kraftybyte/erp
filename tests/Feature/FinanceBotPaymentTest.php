<?php

namespace Tests\Feature;

use App\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use App\Models\Invoice;
use App\Models\PaymentAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Settings\IntegrationSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Mencatat pembayaran invoice lewat bot laporan keuangan (/bayar), hanya untuk
 * Finance, Director, dan super_admin. Jalurnya sama dengan tombol Bayar di ERP.
 */
class FinanceBotPaymentTest extends TestCase
{
    use DatabaseTransactions;

    private const SECRET = 'rahasia-bot-finance';

    private int $chatId = 990_000_202;

    private int $updateId = 0;

    private PaymentAccount $account;

    private string $client;

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
        Cache::forget("telegram:finance-pay:{$this->chatId}");

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $this->client  = 'PT Bayar ' . Str::upper(Str::random(6));
        $this->account = PaymentAccount::create([
            'name'            => 'Rekening Tes ' . Str::upper(Str::random(4)),
            'bank_name'       => 'BCA',
            'account_number'  => '123',
            'account_holder'  => 'Tes',
            'initial_balance' => 0,
            'is_active'       => true,
        ]);
    }

    public function test_finance_melunasi_invoice_dp_lewat_tombol(): void
    {
        $user    = $this->user('Finance');
        $invoice = $this->invoice(['type_payment' => 'dp_50', 'grand_total' => 10_000_000]);

        $this->send($this->text('/bayar ' . $this->client));
        $this->assertStringContainsString('1 invoice belum lunas', $this->lastText());
        $this->assertButton("pay:inv:{$invoice->id}");

        $this->send($this->tap("pay:inv:{$invoice->id}"));
        $this->assertStringContainsString('DP 50%', $this->lastText());
        $this->assertButton("pay:acc:{$this->account->id}");

        $this->send($this->tap("pay:acc:{$this->account->id}"));
        $this->assertStringContainsString('Sisa tagihan <b>Rp 5.000.000</b>', $this->lastText());

        $this->send($this->tap('pay:full'));
        $this->assertStringContainsString('Setelah ini: <b>Lunas</b>', $this->lastText());

        $this->send($this->tap('pay:ok'));
        $this->assertStringContainsString('Pembayaran tercatat', $this->lastText());

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertEquals(5_000_000, $invoice->paid, 'Invoice DP dilunasi sebesar DP-nya.');

        $transaction = Transaction::where('reference_type', 'invoice')->where('reference_id', $invoice->id)->sole();
        $this->assertSame('in', $transaction->type);
        $this->assertSame($user->id, $transaction->created_by);
        $this->assertEquals(5_000_000, $this->account->fresh()->current_balance);
        $this->assertTrue($invoice->invoiceLogs()->where('type', 'payment')->exists());
    }

    public function test_bayar_sebagian_dengan_mengetik_nominal(): void
    {
        $this->user('Director');
        $invoice = $this->invoice(['grand_total' => 30_000_000]);

        $this->send($this->text('/bayar'));
        $this->send($this->tap("pay:inv:{$invoice->id}"));
        $this->send($this->tap("pay:acc:{$this->account->id}"));

        $this->send($this->text('40jt'));
        $this->assertStringContainsString('melebihi sisa tagihan', $this->lastText());

        $this->send($this->text('12,5jt'));
        $this->assertStringContainsString('sisa Rp 17.500.000', $this->lastText());

        $this->send($this->tap('pay:ok'));

        $invoice->refresh();
        $this->assertSame('partial', $invoice->status);
        $this->assertEquals(12_500_000, $invoice->paid);
    }

    public function test_ketukan_ganda_hanya_mencatat_sekali(): void
    {
        $this->user('super_admin');
        $invoice = $this->invoice(['grand_total' => 8_000_000]);

        $this->send($this->text('/bayar'));
        $this->send($this->tap("pay:inv:{$invoice->id}"));
        $this->send($this->tap("pay:acc:{$this->account->id}"));
        $this->send($this->text('3jt'));

        $this->send($this->tap('pay:ok'));
        $this->send($this->tap('pay:ok'));

        $this->assertStringContainsString('sudah selesai', $this->lastText());
        $this->assertSame(1, Transaction::where('reference_type', 'invoice')->where('reference_id', $invoice->id)->count());
        $this->assertEquals(3_000_000, $invoice->fresh()->paid);
    }

    public function test_role_lain_tidak_bisa_mencatat_pembayaran(): void
    {
        $this->user('Staff');
        $invoice = $this->invoice(['grand_total' => 8_000_000]);

        // Sesi dipalsukan seolah sudah sampai konfirmasi.
        Cache::put("telegram:finance-pay:{$this->chatId}", ['invoice_id' => $invoice->id, 'account_id' => $this->account->id, 'amount' => 8_000_000], 600);

        $this->send($this->text('/bayar'));
        $this->assertStringContainsString('khusus untuk Finance dan Director', $this->lastText());

        $this->send($this->tap('pay:ok'));

        $this->assertEquals(0, $invoice->fresh()->paid);
        $this->assertFalse(Transaction::where('reference_type', 'invoice')->where('reference_id', $invoice->id)->exists());
    }

    public function test_invoice_lunas_dan_proforma_tidak_ditawarkan(): void
    {
        $this->user('Finance');
        $this->invoice(['grand_total' => 5_000_000, 'paid' => 5_000_000, 'status' => 'paid']);
        $this->invoice(['grand_total' => 5_000_000, 'type' => 'estimate']);

        $this->send($this->text('/bayar ' . $this->client));

        $this->assertStringContainsString('Tidak ada invoice yang belum lunas', $this->lastText());
    }

    public function test_tombol_bayar_di_erp_memakai_jalur_yang_sama(): void
    {
        $user = $this->user('super_admin');
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $invoice = $this->invoice(['grand_total' => 6_000_000]);

        Livewire::test(ListInvoices::class)
            ->callTableAction('pay', $invoice, data: ['payment_account_id' => $this->account->id, 'amount' => 9_000_000]);
        $this->assertEquals(0, $invoice->fresh()->paid, 'Melebihi sisa tagihan: ditolak.');

        Livewire::test(ListInvoices::class)
            ->callTableAction('pay', $invoice, data: ['payment_account_id' => $this->account->id, 'amount' => 6_000_000]);

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertEquals(6_000_000, $invoice->paid);
        $this->assertEquals(6_000_000, $this->account->fresh()->current_balance);
    }

    // =================================================================
    // Helper
    // =================================================================

    private function invoice(array $attributes): Invoice
    {
        return Invoice::create($attributes + [
            'uuid'            => 'TES/' . Str::upper(Str::random(8)),
            'user_id'         => User::value('id'),
            'type'            => 'sale',
            'type_payment'    => 'full_payment',
            'status'          => 'unpaid',
            'nama_perusahaan' => $this->client,
            'date'            => now(),
            'due_date'        => now()->addWeek(),
            'paid'            => 0,
        ]);
    }

    private function user(string $role): User
    {
        $user = User::forceCreate([
            'username'           => 'fbp_' . Str::lower(Str::random(8)),
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
            'update_id' => 810_000_000 + ++$this->updateId,
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
            'update_id'      => 810_000_000 + ++$this->updateId,
            'callback_query' => [
                'id'      => 'cb-' . $this->updateId,
                'data'    => $data,
                'message' => ['message_id' => 1, 'chat' => ['id' => $this->chatId, 'type' => 'private']],
            ],
        ];
    }

    private function lastRequest(): ?Request
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $request) => preg_match('#/(sendMessage|editMessageText)$#', $request->url()))
            ->last();
    }

    private function lastText(): string
    {
        return (string) $this->lastRequest()?->data()['text'];
    }

    private function assertButton(string $callbackData): void
    {
        $this->assertStringContainsString(
            '"callback_data":"' . $callbackData . '"',
            json_encode($this->lastRequest()?->data()['reply_markup'] ?? []),
            "Tombol {$callbackData} tidak ada."
        );
    }
}
