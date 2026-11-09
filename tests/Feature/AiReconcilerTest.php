<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Services\BankStatement\AiReconciler;
use App\Services\BankStatement\MutationMatcher;
use App\Settings\IntegrationSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pendapat kedua Gemini (konsultan keuangan) atas hasil pencocokan aturan:
 * setuju menaikkan keyakinan, saran baru selalu ditinjau, keraguan
 * menurunkan yang pasti, dan usulan yang tidak valid dibuang.
 */
class AiReconcilerTest extends TestCase
{
    use DatabaseTransactions;

    private PaymentAccount $account;

    private string $client;

    private ?array $answer = null;

    private int $status = 200;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-08 10:00:00');

        IntegrationSettings::fake([
            'gemini_api_key'          => 'kunci-tes',
            'gemini_model'            => 'gemini-3.5-flash-lite',
            'telegram_bot_token'      => '111:PENGAJUAN',
            'telegram_webhook_secret' => 'a',
            'finance_bot_token'       => '222:FINANCE',
            'finance_webhook_secret'  => 'b',
        ]);

        Http::fake(fn () => $this->status === 200
            ? Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['matches' => $this->answer ?? []])]]]]]])
            : Http::response(['error' => ['message' => 'credits depleted']], $this->status));

        $this->client  = 'PT Awan ' . Str::upper(Str::random(5)) . ' Sentosa';
        $this->account = PaymentAccount::create(['name' => 'Rek ' . Str::random(4), 'bank_name' => 'BCA', 'account_number' => (string) random_int(1_000_000_000, 9_999_999_999), 'account_holder' => 'Tes', 'initial_balance' => 0, 'is_active' => true]);
    }

    public function test_ai_setuju_menaikkan_kandidat_menjadi_pasti(): void
    {
        $invoice = $this->invoice(15_430_000);
        $rows    = [$this->row(15_430_000, 'SETORAN TUNAI')];

        $before = app(MutationMatcher::class)->match($rows, $this->account);
        $this->assertSame('review', $before[0]['status'], 'Nominal bulat tanpa nama: aturan ragu.');

        $this->answer = [$this->match(0, [['type' => 'invoice', 'id' => $invoice->id, 'amount' => 15_430_000, 'withholding' => 0, 'withholding_label' => null]], 90, 'Nominal persis sisa tagihan, satu-satunya, invoice 2 minggu sebelumnya.')];
        $after        = app(AiReconciler::class)->review($rows, $before);

        $this->assertSame('sure', $after['results'][0]['status']);
        $this->assertSame(['agree' => 1, 'doubt' => 0, 'added' => 0], $after['summary']);
        $this->assertTrue(collect($after['results'][0]['candidates'][0]['reasons'])->contains(fn ($reason) => str_starts_with($reason, '✓ AI setuju')));
    }

    public function test_saran_baru_dari_ai_selalu_ditinjau(): void
    {
        $invoice = $this->invoice(10_000_000);
        // Termin 1/3 tanpa nama: aturan tidak menemukan pasangan.
        $rows    = [$this->row(3_333_000, 'SETORAN TUNAI')];

        $before = app(MutationMatcher::class)->match($rows, $this->account);
        $this->assertSame('none', $before[0]['status']);

        $this->answer = [$this->match(0, [['type' => 'invoice', 'id' => $invoice->id, 'amount' => 3_333_000, 'withholding' => 0, 'withholding_label' => null]], 95, 'Termin pertama 1/3 dari total Rp 10.000.000.')];
        $after        = app(AiReconciler::class)->review($rows, $before);

        $this->assertSame('review', $after['results'][0]['status']);
        $added = collect($after['results'][0]['candidates'])->last();
        $this->assertSame(75, $added['confidence'], 'Saran AI saja dibatasi 75%.');
        $this->assertStringStartsWith('🤖 ', $added['label']);
    }

    public function test_ai_meragukan_menurunkan_yang_pasti(): void
    {
        $this->invoice(15_437_219);
        $rows = [$this->row(15_437_219, 'TRSF E-BANKING CR ' . strtoupper($this->client))];

        $before = app(MutationMatcher::class)->match($rows, $this->account);
        $this->assertSame('sure', $before[0]['status']);

        $this->answer = [['row' => 0, 'decision' => 'no_match', 'targets' => [], 'confidence' => 85, 'reasoning' => 'Pengirim adalah pengembalian dana, bukan pembayaran invoice.']];
        $after        = app(AiReconciler::class)->review($rows, $before);

        $this->assertSame('review', $after['results'][0]['status']);
        $this->assertTrue(collect($after['results'][0]['candidates'][0]['reasons'])->contains(fn ($reason) => str_starts_with($reason, '✗ AI meragukan')));
    }

    public function test_usulan_ai_yang_tidak_valid_dibuang(): void
    {
        $invoice = $this->invoice(15_430_000);
        $rows    = [$this->row(15_430_000, 'SETORAN TUNAI'), $this->row(2_000_000, 'SETORAN TUNAI')];
        $before  = app(MutationMatcher::class)->match($rows, $this->account);

        $this->answer = [
            $this->match(0, [['type' => 'invoice', 'id' => 999_999_999, 'amount' => 15_430_000, 'withholding' => 0, 'withholding_label' => null]], 99, 'ID karangan.'),
            $this->match(1, [['type' => 'invoice', 'id' => $invoice->id, 'amount' => 1_500_000, 'withholding' => 0, 'withholding_label' => null]], 99, 'Jumlah tidak pas dengan mutasi.'),
        ];
        $after = app(AiReconciler::class)->review($rows, $before);

        $this->assertSame($before, $after['results']);
        $this->assertSame(['agree' => 0, 'doubt' => 0, 'added' => 0], $after['summary']);
    }

    public function test_ai_gagal_hasil_aturan_tetap_dan_alasannya_dicatat(): void
    {
        $this->status = 402;
        $this->invoice(15_430_000);
        $rows   = [$this->row(15_430_000, 'SETORAN TUNAI')];
        $before = app(MutationMatcher::class)->match($rows, $this->account);

        $after = app(AiReconciler::class)->review($rows, $before);

        $this->assertSame($before, $after['results']);
        $this->assertStringContainsString('Saldo prabayar Gemini habis', $after['error']);
    }

    private function match(int $row, array $targets, int $confidence, string $reasoning): array
    {
        return ['row' => $row, 'decision' => 'match', 'targets' => $targets, 'confidence' => $confidence, 'reasoning' => $reasoning];
    }

    private function row(float $amount, string $description): array
    {
        return ['key' => hash('sha256', Str::random(20)), 'date' => '2026-11-03', 'description' => $description, 'amount' => $amount, 'direction' => 'in', 'balance' => null];
    }

    private function invoice(float $total): Invoice
    {
        return Invoice::create([
            'uuid'            => '#TES/' . Str::upper(Str::random(8)),
            'user_id'         => User::value('id'),
            'type'            => 'sale',
            'type_payment'    => 'full_payment',
            'status'          => 'unpaid',
            'nama_perusahaan' => $this->client,
            'date'            => '2026-10-20',
            'due_date'        => '2026-11-20',
            'subtotal'        => $total,
            'total_discount'  => 0,
            'total_vat'       => 0,
            'grand_total'     => $total,
            'paid'            => 0,
        ]);
    }
}
