<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use App\Services\MetricsEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Analisa keuangan mingguan membaca yang ditagih per invoice
 * (Invoice::amountDue()): invoice DP menagih persennya, pelunasan menagih
 * harga penuh − DP. Invoice DP yang DP-nya sudah lunas tidak terlambat.
 */
class MetricsEngineTest extends TestCase
{
    use DatabaseTransactions;

    public function test_tagihan_dan_keterlambatan_mengikuti_yang_ditagih(): void
    {
        $before = app(MetricsEngine::class)->generateSnapshot()['finance'];

        // DP 50% dari Rp 10 jt sudah dibayar: ditagih Rp 5 jt, tidak terlambat.
        $dp = $this->invoice(['type_payment' => 'dp_50', 'grand_total' => 10_000_000, 'paid' => 5_000_000, 'status' => 'paid']);
        // Pelunasan-nya: ditagih Rp 5 jt, belum dibayar, lewat jatuh tempo.
        $this->invoice(['type_payment' => 'final_payment', 'grand_total' => 10_000_000, 'parent_invoice_id' => $dp->id, 'status' => 'pending']);
        // Dibatalkan: tidak dihitung.
        $this->invoice(['type_payment' => 'full_payment', 'grand_total' => 7_000_000, 'status' => 'cancelled']);

        $after = app(MetricsEngine::class)->generateSnapshot()['finance'];

        $this->assertEqualsWithDelta(10_000_000, $after['billed_total'] - $before['billed_total'], 1, 'Rp 5 jt DP + Rp 5 jt pelunasan, bukan 2 × harga penuh.');
        $this->assertSame(1, $after['overdue_invoices'] - $before['overdue_invoices'], 'Hanya pelunasan yang belum dibayar.');
    }

    private function invoice(array $attributes): Invoice
    {
        return Invoice::create($attributes + [
            'uuid'            => '#TES/' . Str::upper(Str::random(8)),
            'user_id'         => User::value('id'),
            'type'            => 'sale',
            'nama_perusahaan' => 'PT Metrik ' . Str::random(4),
            'date'            => now()->subDays(20)->toDateString(),
            'due_date'        => now()->subDays(5)->toDateString(),
            'subtotal'        => $attributes['grand_total'],
            'total_discount'  => 0,
            'total_vat'       => 0,
            'paid'            => 0,
        ]);
    }
}
