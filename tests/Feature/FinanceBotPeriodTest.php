<?php

namespace Tests\Feature;

use App\Models\Advance;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\User;
use App\Services\FinanceOverview;
use App\Services\ReportPeriod;
use App\Settings\IntegrationSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Laporan per periode di bot keuangan. Data tes diberi tanggal Februari 2001
 * (tidak ada data lain di bulan itu), dan satu data pembanding di Januari 2001.
 */
class FinanceBotPeriodTest extends TestCase
{
    use DatabaseTransactions;

    private const SECRET = 'rahasia-bot-finance';

    private int $chatId = 990_000_303;

    private int $updateId = 0;

    private User $user;

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

        $this->user = User::forceCreate([
            'username'           => 'fbper_' . Str::lower(Str::random(8)),
            'firstname'          => 'Tes',
            'lastname'           => 'Periode',
            'email'              => Str::lower(Str::random(10)) . '@example.test',
            'password'           => bcrypt('rahasia'),
            'is_active'          => true,
            'push_enabled'       => false,
            'telegram_chat_id'   => (string) $this->chatId,
            'telegram_linked_at' => now(),
        ]);
        $this->user->assignRole('Finance');

        // Februari 2001
        $this->invoice('2001-02-05', 'dp_50', 20_000_000, paid: 10_000_000);     // DP ditagih 10 jt, lunas
        $this->invoice('2001-02-10', 'full_payment', 7_000_000, paid: 2_000_000); // sisa 5 jt
        $this->invoice('2001-02-12', 'full_payment', 3_000_000, status: 'draft'); // draft: tidak dihitung
        $this->expense('2001-02-03', 'Dibayarkan', 400_000);
        $this->expense('2001-02-04', 'Pending', 600_000);
        $this->expense('2001-02-04', 'Ditolak', 900_000);
        $this->advance('2001-02-06', Advance::STATUS_DIBAYARKAN, 1_500_000);
        $this->advance('2001-02-07', Advance::STATUS_DISETUJUI, 250_000);

        // Januari 2001: di luar periode
        $this->invoice('2001-01-20', 'full_payment', 99_000_000);
        $this->expense('2001-01-20', 'Pending', 99_000);
        $this->advance('2001-01-20', Advance::STATUS_PENDING, 99_000);
    }

    public function test_perhitungan_hanya_dari_periode_itu(): void
    {
        $o = app(FinanceOverview::class)->forPeriod(ReportPeriod::parse('februari 2001'));

        $kinds = $o->invoiceBreakdown()['kinds'];
        $this->assertEquals([1, 10_000_000], [$kinds['dp']['count'], $kinds['dp']['billed']], 'Invoice DP ditagih sebesar DP-nya.');
        $this->assertEquals([1, 7_000_000], [$kinds['full']['count'], $kinds['full']['billed']], 'Draft dan Januari tidak dihitung.');
        $this->assertEquals(5_000_000, $o->invoices()['outstanding']['amount']);

        $e = $o->expenses();
        $this->assertEquals(1_000_000, $e['total']['amount'], 'Yang ditolak tidak dihitung.');
        $this->assertEquals(600_000, $e['pending']['amount']);

        $a = $o->advances();
        $this->assertEquals(1_750_000, $a['total']['amount']);
        $this->assertEquals(1_500_000, $a['outstanding']['amount']);
    }

    public function test_ringkasan_mengikuti_periode_yang_diketik(): void
    {
        $this->send($this->text('/periode 1/2/2001 - 28/2/2001'));

        $text = $this->lastText();
        $this->assertStringContainsString('Februari 2001', $text);
        $this->assertStringContainsString('Invoice DP: <b>Rp 10.000.000</b> (1)', $text);
        $this->assertStringContainsString('Invoice full payment: <b>Rp 7.000.000</b> (1)', $text);
        $this->assertStringContainsString('Belum dibayarkan: <b>Rp 5.000.000</b> (1 invoice)', $text);
        $this->assertStringNotContainsString('pelunasan', Str::before($text, 'Total:'), 'Pelunasan disembunyikan bila tidak ada.');
        $this->assertStringContainsString("💰 <b>Expense</b> — 2 pengajuan\nTotal: <b>Rp 1.000.000</b>\nBelum dibayarkan: <b>Rp 600.000</b> (1)", $text);
        $this->assertStringContainsString("Total: <b>Rp 1.750.000</b>\nBelum dibayarkan: <b>Rp 250.000</b> (1)", $text);
        $this->assertStringContainsString('📅 Periode: Februari 2001', $this->lastButtons());
    }

    public function test_tombol_periode_lalu_ketik_tanggal_untuk_bagian_expense(): void
    {
        $this->send($this->tap('fin:periode:expense'));
        $this->assertStringContainsString('Pilih periode', $this->lastText());
        $this->assertStringContainsString('per:bulan-ini:expense', $this->lastButtons());

        $this->send($this->text('februari 2001'));

        $this->assertStringContainsString('💰 <b>Expense</b>', $this->lastText());
        $this->assertStringContainsString('Total: <b>Rp 1.000.000</b> (2 pengajuan)', $this->lastText());

        // Periode diingat untuk laporan berikutnya.
        $this->send($this->text('/advance'));
        $this->assertStringContainsString('Februari 2001', $this->lastText());
        $this->assertStringContainsString('Total: <b>Rp 1.750.000</b> (2 pengajuan)', $this->lastText());
    }

    public function test_tanggal_yang_tidak_terbaca_diminta_ulang(): void
    {
        $this->send($this->tap('fin:periode:ringkasan'));
        $this->send($this->text('kemarin sore'));

        $this->assertStringContainsString('Tanggal tidak terbaca', $this->lastText());

        $this->send($this->text('1/2/2001 - 28/2/2001'));
        $this->assertStringContainsString('Ringkasan keuangan', $this->lastText());
    }

    public function test_tombol_semua_waktu_mengembalikan_periode(): void
    {
        $this->send($this->text('/periode februari 2001'));
        $this->send($this->tap('per:semua:ringkasan'));

        $this->assertStringContainsString('📅 <b>Semua waktu</b>', $this->lastText());
    }

    public function test_excel_mengikuti_periode(): void
    {
        $this->send($this->text('/periode februari 2001'));
        $this->send($this->tap('fin:excel'));

        $request = collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->first(fn (Request $request) => str_ends_with($request->url(), '/sendDocument'));

        $document = collect($request->data())->firstWhere('name', 'document');
        $this->assertSame('laporan-keuangan-2001-02-01_2001-02-28.xlsx', $document['filename']);

        $path = tempnam(sys_get_temp_dir(), 'tes-');
        file_put_contents($path, $document['contents']);
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        unlink($path);

        $this->assertSame('Ringkasan keuangan — Februari 2001', $book->getSheetByName('Ringkasan')->getCell('A2')->getValue());
        // Tiga invoice Februari (draft ikut dicantumkan dengan keterangannya) + judul kolom; Januari tidak.
        $this->assertSame(4, $book->getSheetByName('Invoice')->getHighestRow());
        $this->assertNotContains('2001-01-20', array_column($book->getSheetByName('Invoice')->toArray(), 1));
    }

    // =================================================================
    // Helper
    // =================================================================

    private function invoice(string $date, string $typePayment, int $total, int $paid = 0, string $status = 'unpaid'): Invoice
    {
        return Invoice::create([
            'uuid'            => 'TES/PER-' . Str::upper(Str::random(6)),
            'user_id'         => $this->user->id,
            'type'            => 'sale',
            'type_payment'    => $typePayment,
            'status'          => $status,
            'nama_perusahaan' => 'PT Periode ' . Str::upper(Str::random(4)),
            'date'            => $date,
            'due_date'        => $date,
            'grand_total'     => $total,
            'paid'            => $paid,
        ]);
    }

    private function expense(string $date, string $status, int $amount): Expense
    {
        return Expense::create([
            'user_id'            => $this->user->id,
            'submission_date'    => $date,
            'category'           => 'Transport',
            'keterangan_expense' => "Tes periode {$status}",
            'amount'             => $amount,
            'paid'               => 0,
            'status'             => $status,
        ]);
    }

    private function advance(string $date, string $status, int $amount): Advance
    {
        return Advance::create([
            'user_id'            => $this->user->id,
            'submission_date'    => $date,
            'category'           => 'Project Support',
            'keterangan_advance' => "Tes periode {$status}",
            'amount'             => $amount,
            'paid'               => $status === Advance::STATUS_DIBAYARKAN ? $amount : 0,
            'status'             => $status,
        ]);
    }

    private function send(array $update): void
    {
        $this->postJson('/api/telegram/finance-webhook', $update, ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])
            ->assertOk();
    }

    private function text(string $text): array
    {
        return [
            'update_id' => 820_000_000 + ++$this->updateId,
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
            'update_id'      => 820_000_000 + ++$this->updateId,
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

    private function lastButtons(): string
    {
        return json_encode($this->lastRequest()?->data()['reply_markup'] ?? [], JSON_UNESCAPED_UNICODE);
    }
}
