<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use App\Services\FinanceAdvisor;
use App\Services\MetricsEngine;
use App\Settings\IntegrationSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Asisten keuangan: temuan dan status dari aturan, prioritas dari AI yang
 * diperiksa ulang (nominal dan nomor dokumen harus ada di data), dan kembali
 * ke aturan bila AI tidak tersedia.
 */
class FinanceAdvisorTest extends TestCase
{
    use DatabaseTransactions;

    private ?array $answer = null;

    private int $status = 200;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-10 10:00:00');
        $this->fakeSettings('kunci-tes');

        Http::fake(fn () => $this->status === 200
            ? Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($this->answer ?? ['ringkasan' => '', 'prioritas' => [], 'peluang' => ''])]]]]]])
            : Http::response(['error' => ['message' => 'quota']], $this->status));
    }

    public function test_angka_dan_nomor_yang_dikarang_ai_dibuang(): void
    {
        $client  = 'PT Nadi ' . Str::upper(Str::random(5));
        $invoice = $this->invoice($client, 97_654_321);

        $this->answer = [
            'ringkasan' => "Piutang {$client} Rp 97.654.321 lewat jatuh tempo; tagih minggu ini.",
            'prioritas' => [
                ['judul' => 'Tagih piutang terbesar', 'fakta' => "{$invoice->uuid} ({$client}) Rp 97.654.321 telat 40 hari.", 'aksi' => "Telepon {$client} untuk {$invoice->uuid}, minta tanggal transfer pasti.", 'pic' => 'Finance', 'tenggat' => 'hari ini'],
                ['judul' => 'Kejar target', 'fakta' => 'Potensi Rp 123.456.789 bulan depan.', 'aksi' => 'Tawarkan paket baru.', 'pic' => 'Sales', 'tenggat' => 'minggu ini'],
                ['judul' => 'Tagih invoice lama', 'fakta' => 'Invoice #SC/9999999/99999 belum dibayar.', 'aksi' => 'Kirim pengingat.', 'pic' => 'Finance', 'tenggat' => 'besok'],
                // Dibulatkan ke 0,1 jt: masih dalam toleransi angka yang ditulis.
                ['judul' => 'Kunci jadwal bayar', 'fakta' => "Sisa {$client} sekitar Rp 97,7 jt.", 'aksi' => 'Minta komitmen tertulis tanggal transfer.', 'pic' => 'Director', 'tenggat' => 'minggu ini'],
            ],
            'peluang' => 'Upsell bisa menambah Rp 51.234.567.',
        ];

        $analysis = app(FinanceAdvisor::class)->analyze();

        $this->assertSame('ai', $analysis['source']);
        $this->assertSame(['Tagih piutang terbesar', 'Kunci jadwal bayar'], array_column($analysis['priorities'], 'title'));
        $this->assertSame(3, $analysis['dropped'], 'Dua butir dan peluang memakai angka/nomor yang tidak ada.');
        $this->assertNull($analysis['opportunity']);
        $this->assertStringContainsString('Rp 97.654.321', $analysis['summary']);

        $message = app(FinanceAdvisor::class)->message($analysis);
        $this->assertStringContainsString('3 saran AI dibuang', $message);
        $this->assertStringNotContainsString('123.456.789', $message);

        // Prompt berisi fakta berformat Rupiah dan nomor invoicenya.
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'generativelanguage')
            && str_contains($request['contents'][0]['parts'][0]['text'], 'Rp 97.654.321')
            && str_contains($request['contents'][0]['parts'][0]['text'], $invoice->uuid));
    }

    public function test_tanpa_kunci_gemini_prioritas_dari_aturan(): void
    {
        $this->fakeSettings(null);
        $this->invoice('PT Aturan ' . Str::random(4), 88_000_000);

        $analysis = app(FinanceAdvisor::class)->analyze();

        $this->assertSame('rules', $analysis['source']);
        $this->assertSame('API key Gemini belum diisi', $analysis['ai_error']);
        $this->assertNotEmpty($analysis['priorities']);
        $this->assertStringContainsString('Prioritas dari aturan sistem', app(FinanceAdvisor::class)->message($analysis));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'generativelanguage'));
    }

    public function test_ai_gagal_beralih_ke_aturan(): void
    {
        $this->status = 429;

        $analysis = app(FinanceAdvisor::class)->analyze('kenapa kas minus?');

        $this->assertSame('rules', $analysis['source']);
        $this->assertStringContainsString('Batas pemakaian', $analysis['ai_error']);
        $this->assertStringContainsString('Pertanyaan belum bisa dijawab AI', app(FinanceAdvisor::class)->message($analysis));
    }

    public function test_temuan_dan_status_dari_aturan(): void
    {
        $advisor = app(FinanceAdvisor::class);
        $facts   = $this->neutral();

        $this->assertSame([], $advisor->findings($facts));
        $this->assertSame('sehat', FinanceAdvisor::status([]));

        // Piutang lebih dari 60 hari: kritis.
        $facts['receivables']['overdue']                = ['count' => 1, 'amount' => 15_000_000.0];
        $facts['receivables']['aging']['d61_90']       = ['count' => 1, 'amount' => 15_000_000.0];
        $facts['receivables']['top_overdue']            = [['invoice' => '#SC/0132609/00007', 'client' => 'PT Lama', 'kind' => 'full', 'status' => 'pending', 'remaining' => 15_000_000.0, 'due' => '2026-09-01', 'days_overdue' => 70]];
        // Project selesai, pelunasan belum dibuat.
        $facts['dp_unbilled'] = ['count' => 1, 'amount' => 28_000_000.0, 'done_count' => 1, 'done_amount' => 28_000_000.0, 'top' => [
            ['invoice' => '#SC/0132610/00009', 'client' => 'PT Selesai', 'unbilled' => 28_000_000.0, 'days_since_dp' => 40, 'project_done' => true],
        ]];

        $findings = $advisor->findings($facts);

        $this->assertSame(['piutang_telat', 'pelunasan_selesai'], array_column($findings, 'key'));
        $this->assertSame('kritis', FinanceAdvisor::status($findings));
        $this->assertStringContainsString('Rp 15.000.000 dari 1 invoice lewat jatuh tempo', $findings[0]['fact']);
        $this->assertStringContainsString('#SC/0132610/00009', $findings[1]['action']);

        // Saldo kurang dari sebulan pengeluaran: kritis, dan nominalnya dari fakta.
        $facts = $this->neutral();
        $facts['cash'] = ['balance' => 6_000_000.0, 'avg_out' => 10_000_000.0, 'runway_months' => 0.6] + $facts['cash'];

        $findings = $advisor->findings($facts);
        $this->assertSame('kas_menipis', $findings[0]['key']);
        $this->assertSame('kritis', $findings[0]['level']);
        $this->assertStringContainsString('±0,6 bulan', $findings[0]['fact']);
    }

    public function test_nominal_ditulis_lengkap_atau_dibulatkan(): void
    {
        $this->assertSame([[12_500_000.0, 1.0]], FinanceAdvisor::amountsIn('sisa Rp 12.500.000.'));
        $this->assertSame([[12_500_000.0, 1.0]], FinanceAdvisor::amountsIn('sisa Rp12.500.000,00'));
        $this->assertEqualsWithDelta(12_500_000, FinanceAdvisor::amountsIn('Rp 12,5 jt')[0][0], 0.01);
        $this->assertEqualsWithDelta(50_000, FinanceAdvisor::amountsIn('Rp 12,5 jt')[0][1], 0.01);
        $this->assertEqualsWithDelta(1_200_000_000, FinanceAdvisor::amountsIn('Rp 1,2 M')[0][0], 0.01);
        $this->assertSame([], FinanceAdvisor::amountsIn('telat 40 hari, 3 invoice'));

        $this->assertTrue(FinanceAdvisor::grounded('Tagih Rp 12,5 jt dari #SC/1/2', [12_480_000.0], ['#SC/1/2' => 0]));
        $this->assertFalse(FinanceAdvisor::grounded('Tagih Rp 12,5 jt', [12_400_000.0], []));
        $this->assertFalse(FinanceAdvisor::grounded('Tagih #SC/1/3', [], ['#SC/1/2' => 0]));
    }

    public function test_pesan_tetap_di_bawah_batas_telegram(): void
    {
        $long     = str_repeat('Kalimat panjang berisi angka. ', 20);
        $analysis = [
            'status'      => 'waspada',
            'question'    => str_repeat('kenapa ', 40),
            'summary'     => Str::limit($long, 400),
            'priorities'  => array_fill(0, 4, ['title' => Str::limit($long, 90), 'fact' => Str::limit($long, 280), 'action' => Str::limit($long, 260), 'pic' => 'Finance', 'when' => 'minggu ini']),
            'opportunity' => Str::limit($long, 300),
            'source'      => 'ai',
            'ai_error'    => null,
            'dropped'     => 2,
            'facts'       => app(MetricsEngine::class)->generateSnapshot(),
            'findings'    => [],
        ];

        $message = app(FinanceAdvisor::class)->message($analysis);

        $this->assertLessThan(4096, mb_strlen($message));
        $this->assertStringContainsString('<b>1. ', $message);
        $this->assertStringContainsString('Angka kunci', $message, 'Angka kunci tidak ikut dipotong.');
    }

    /**
     * Fakta tanpa satu pun hal yang perlu ditindaklanjuti.
     */
    private function neutral(): array
    {
        $zero  = ['count' => 0, 'amount' => 0.0];
        $facts = app(MetricsEngine::class)->generateSnapshot();

        $facts['cash']        = ['in' => 10_000_000.0, 'out' => 5_000_000.0, 'net' => 5_000_000.0, 'in_invoice' => 10_000_000.0, 'prev_in' => 8_000_000.0, 'prev_out' => 4_000_000.0, 'balance' => 100_000_000.0, 'accounts' => [], 'avg_out' => 10_000_000.0, 'runway_months' => 10.0];
        $facts['billing']     = ['billed' => 20_000_000.0, 'count' => 2, 'prev_billed' => 18_000_000.0, 'change_pct' => 11, 'last_month_billed' => 30_000_000.0, 'avg_billed' => 25_000_000.0];
        $facts['receivables'] = ['total' => $zero, 'overdue' => $zero, 'aging' => ['not_due' => $zero, 'd1_30' => $zero, 'd31_60' => $zero, 'd61_90' => $zero, 'd90_plus' => $zero], 'top_overdue' => [], 'due_soon' => []];
        $facts['dp_unbilled'] = ['count' => 0, 'amount' => 0.0, 'done_count' => 0, 'done_amount' => 0.0, 'top' => []];
        $facts['pipeline']    = ['proforma' => $zero + ['top' => []], 'quotes_approved' => $zero + ['top' => []], 'quotes_pending' => $zero];
        $facts['expenses']    = ['total' => 3_000_000.0, 'prev_total' => 3_000_000.0, 'change_pct' => 0, 'avg_total' => 3_000_000.0, 'pending' => $zero, 'top_categories' => []];
        $facts['advances']    = ['outstanding' => $zero, 'stale' => $zero, 'overrun' => $zero, 'top' => []];
        $facts['pending']     = ['expense' => $zero + ['oldest_days' => null], 'advance' => $zero + ['oldest_days' => null]];

        return $facts;
    }

    private function invoice(string $client, int $amount): Invoice
    {
        return Invoice::create([
            'uuid'            => '#TES/' . Str::upper(Str::random(8)),
            'user_id'         => User::value('id'),
            'type'            => 'sale',
            'type_payment'    => 'full_payment',
            'status'          => 'pending',
            'nama_perusahaan' => $client,
            'date'            => '2026-09-15',
            'due_date'        => '2026-10-01',
            'subtotal'        => $amount,
            'grand_total'     => $amount,
            'total_discount'  => 0,
            'total_vat'       => 0,
            'paid'            => 0,
        ]);
    }

    private function fakeSettings(?string $key): void
    {
        // Kunci kosong di pengaturan jatuh ke .env; kosongkan juga supaya tes tidak memanggil Gemini sungguhan.
        config(['services.gemini.key' => null]);

        IntegrationSettings::fake([
            'gemini_api_key'          => $key,
            'gemini_model'            => 'gemini-3.5-flash-lite',
            'telegram_bot_token'      => '111:PENGAJUAN',
            'telegram_webhook_secret' => 'a',
            'finance_bot_token'       => '222:FINANCE',
            'finance_webhook_secret'  => 'b',
        ]);
    }
}
