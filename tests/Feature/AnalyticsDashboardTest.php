<?php

namespace Tests\Feature;

use App\Filament\Pages\AnalyticsDashboard;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Financial Analytics menghitung nilai invoice sebesar yang ditagih:
 * invoice DP sebesar DP-nya, full payment sebesar Total, pelunasan sebesar
 * harga penuh − DP. Satu pekerjaan Rp 20 jt (DP 50% + pelunasan) tidak
 * lagi terhitung Rp 40 jt.
 */
class AnalyticsDashboardTest extends TestCase
{
    use DatabaseTransactions;

    public function test_pendapatan_dan_tagihan_sebesar_yang_ditagih(): void
    {
        $page   = app(AnalyticsDashboard::class);
        $before = $page->getRevenueData();
        $overview = $page->getOverviewData()['revenue'];

        $dp = $this->invoice(['type_payment' => 'dp_50', 'paid' => 10_000_000, 'status' => 'paid']);
        $this->invoice(['type_payment' => 'final_payment', 'parent_invoice_id' => $dp->id, 'status' => 'pending', 'due_date' => now()->subDays(3)->toDateString()]);
        $this->invoice(['type_payment' => 'full_payment', 'grand_total' => 4_000_000, 'subtotal' => 4_000_000, 'status' => 'paid']);

        $after = $page->getRevenueData();

        $this->assertEqualsWithDelta(14_000_000, $after['stats']->total_revenue - $before['stats']->total_revenue, 1, 'DP Rp 10 jt + full Rp 4 jt.');
        $this->assertEqualsWithDelta(10_000_000, $after['stats']->pending_total - $before['stats']->pending_total, 1, 'Pelunasan Rp 20 jt − DP Rp 10 jt.');
        $this->assertEqualsWithDelta(10_000_000, $after['stats']->overdue_total - $before['stats']->overdue_total, 1);
        $this->assertSame(1, $after['stats']->overdue_count - $before['stats']->overdue_count);
        $this->assertEqualsWithDelta(14_000_000, $after['distribution']->paid - $before['distribution']->paid, 1);
        $this->assertEqualsWithDelta(14_000_000, $page->getOverviewData()['revenue']['all_time'] - $overview['all_time'], 1);
    }

    private function invoice(array $attributes): Invoice
    {
        return Invoice::create($attributes + [
            'uuid'            => '#TES/' . Str::upper(Str::random(8)),
            'user_id'         => User::value('id'),
            'type'            => 'sale',
            'nama_perusahaan' => 'PT Analitik ' . Str::random(4),
            'date'            => now()->toDateString(),
            'due_date'        => now()->addDays(14)->toDateString(),
            'subtotal'        => 20_000_000,
            'total_discount'  => 0,
            'total_vat'       => 0,
            'grand_total'     => 20_000_000,
            'paid'            => 0,
        ]);
    }
}
