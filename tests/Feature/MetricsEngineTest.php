<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PaymentAccount;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MetricsEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fakta untuk asisten keuangan: yang ditagih per invoice (DP sebesar DP-nya,
 * pelunasan sebesar sisa), piutang tanpa batas periode dengan umur dari jatuh
 * tempo, DP lunas yang belum dibuatkan pelunasan, dan kas dari transaksi yang
 * dibandingkan dengan tanggal yang sama bulan lalu.
 */
class MetricsEngineTest extends TestCase
{
    use DatabaseTransactions;

    private string $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-10 10:00:00');
        $this->client = 'PT Metrik ' . Str::upper(Str::random(6));
    }

    public function test_tagihan_dan_piutang_mengikuti_yang_ditagih(): void
    {
        $before = $this->snapshot();

        // DP 50% dari Rp 10 jt sudah dibayar: ditagih Rp 5 jt, tidak terlambat.
        $dp = $this->invoice(['type_payment' => 'dp_50', 'grand_total' => 10_000_000, 'paid' => 5_000_000, 'status' => 'paid']);
        // Pelunasannya: ditagih Rp 5 jt, belum dibayar, lewat jatuh tempo 70 hari.
        $final = $this->invoice(['type_payment' => 'final_payment', 'grand_total' => 10_000_000, 'parent_invoice_id' => $dp->id, 'status' => 'pending', 'due_date' => '2026-09-01']);
        // Dibatalkan: tidak dihitung.
        $this->invoice(['type_payment' => 'full_payment', 'grand_total' => 7_000_000, 'status' => 'cancelled']);

        $after = $this->snapshot();

        $this->assertEqualsWithDelta(10_000_000, $after['billing']['billed'] - $before['billing']['billed'], 1, 'Rp 5 jt DP + Rp 5 jt pelunasan, bukan 2 × harga penuh.');
        $this->assertSame(1, $after['receivables']['overdue']['count'] - $before['receivables']['overdue']['count'], 'Hanya pelunasan yang belum dibayar.');
        $this->assertEqualsWithDelta(5_000_000, $after['receivables']['aging']['d61_90']['amount'] - $before['receivables']['aging']['d61_90']['amount'], 1);

        $row = collect($after['receivables']['top_overdue'])->firstWhere('invoice', $final->uuid);
        $this->assertNotNull($row, 'Pelunasan Rp 5 jt masuk daftar piutang terbesar.');
        $this->assertSame(70, $row['days_overdue']);
        $this->assertSame('final', $row['kind']);

        // DP-nya sudah punya pelunasan: tidak dianggap belum ditagih.
        $this->assertNull(collect($after['dp_unbilled']['top'])->firstWhere('invoice', $dp->uuid));
    }

    public function test_dp_lunas_tanpa_pelunasan_dan_pelunasan_lama_yang_belum_ditautkan(): void
    {
        $before = $this->snapshot();

        // DP 30% dari Rp 40 jt lunas, project selesai, pelunasan belum dibuat: Rp 28 jt belum ditagih.
        $open = $this->invoice(['type_payment' => 'dp_30', 'grand_total' => 40_000_000, 'paid' => 12_000_000, 'status' => 'paid']);
        Project::create(['nama_project' => 'Booth ' . Str::random(4), 'nama_perusahaan' => $this->client, 'status' => 'completed', 'invoice_id' => $open->id]);

        // DP lama dengan pelunasan yang belum ditautkan (klien dan harga penuh sama).
        $client = 'PT Lama ' . Str::upper(Str::random(6));
        $old    = $this->invoice(['type_payment' => 'dp_50', 'grand_total' => 6_000_000, 'paid' => 3_000_000, 'status' => 'paid', 'nama_perusahaan' => $client]);
        $this->invoice(['type_payment' => 'final_payment', 'grand_total' => 6_000_000, 'status' => 'pending', 'nama_perusahaan' => $client, 'notes' => 'DP: 50% - Rp 3.000.000']);

        // DP yang dibayar harga penuh: tidak ada pelunasan yang perlu ditagih.
        $full = $this->invoice(['type_payment' => 'dp_50', 'grand_total' => 4_500_000, 'paid' => 4_500_000, 'status' => 'paid']);

        $after = $this->snapshot();

        $this->assertNull(collect($after['dp_unbilled']['top'])->firstWhere('invoice', $full->uuid));

        $row = collect($after['dp_unbilled']['top'])->firstWhere('invoice', $open->uuid);
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(28_000_000, $row['unbilled'], 1);
        $this->assertTrue($row['project_done']);
        $this->assertSame(1, $after['dp_unbilled']['done_count'] - $before['dp_unbilled']['done_count']);

        $this->assertNull(collect($after['dp_unbilled']['top'])->firstWhere('invoice', $old->uuid), 'Sudah ada pelunasan, hanya belum ditautkan.');
        $this->assertSame(1, $after['data_quality']['unlinked_finals']['count'] - $before['data_quality']['unlinked_finals']['count']);
    }

    public function test_kas_dibanding_tanggal_yang_sama_bulan_lalu(): void
    {
        $before  = $this->snapshot();
        $account = PaymentAccount::create(['name' => 'Rek ' . Str::random(4), 'bank_name' => 'BCA', 'account_number' => (string) random_int(1_000_000_000, 9_999_999_999), 'account_holder' => 'Tes', 'initial_balance' => 0, 'is_active' => true]);

        $this->transaction($account, 'in', 9_000_000, '2026-11-03');
        $this->transaction($account, 'out', 2_500_000, '2026-11-09');
        $this->transaction($account, 'in', 4_000_000, '2026-10-08');
        // Sesudah 10 Okt: tidak ikut pembanding 1–10 Okt.
        $this->transaction($account, 'in', 50_000_000, '2026-10-20');

        $after = $this->snapshot();

        $this->assertSame('1–10 Nov 2026', $after['period']['label']);
        $this->assertSame('1–10 Okt 2026', $after['period']['compare_label']);
        $this->assertEqualsWithDelta(9_000_000, $after['cash']['in'] - $before['cash']['in'], 1);
        $this->assertEqualsWithDelta(2_500_000, $after['cash']['out'] - $before['cash']['out'], 1);
        $this->assertEqualsWithDelta(4_000_000, $after['cash']['prev_in'] - $before['cash']['prev_in'], 1);
        $this->assertEqualsWithDelta(60_500_000, $after['cash']['balance'] - $before['cash']['balance'], 1, 'Saldo = semua uang masuk − keluar yang tercatat.');

        $october = collect($after['history'])->firstWhere('label', 'Okt 2026');
        $this->assertFalse($october['partial']);
        $this->assertTrue(collect($after['history'])->last()['partial'], 'Bulan berjalan ditandai partial.');
    }

    private function snapshot(): array
    {
        return app(MetricsEngine::class)->generateSnapshot();
    }

    private function invoice(array $attributes): Invoice
    {
        return Invoice::create($attributes + [
            'uuid'            => '#TES/' . Str::upper(Str::random(8)),
            'user_id'         => User::value('id'),
            'type'            => 'sale',
            'nama_perusahaan' => $this->client,
            'date'            => '2026-11-02',
            'due_date'        => '2026-11-05',
            'subtotal'        => $attributes['grand_total'],
            'total_discount'  => 0,
            'total_vat'       => 0,
            'paid'            => 0,
        ]);
    }

    private function transaction(PaymentAccount $account, string $type, int $amount, string $date): void
    {
        Transaction::create([
            'payment_account_id' => $account->id,
            'created_by'         => User::value('id'),
            'type'               => $type,
            'amount'             => $amount,
            'description'        => 'Tes metrik',
            'reference_type'     => Transaction::REFERENCE_MANUAL,
            'transaction_date'   => $date,
        ]);
    }
}
