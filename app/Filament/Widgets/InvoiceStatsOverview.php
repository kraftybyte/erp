<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use TomatoPHP\FilamentInvoices\Models\Invoice;
use Illuminate\Support\HtmlString;


class InvoiceStatsOverview extends BaseWidget
{
    
 protected int | string | array $columnSpan = [
    'default' => 1,
    'xl' => 4,
];    protected function getStats(): array
    {
        $now = Carbon::now();

        // =========================
        // REVENUE
        // =========================
        $paidInvoices = Invoice::where('status', 'paid');

        $totalRevenue = (clone $paidInvoices)->sum('grand_total');

        $currentMonthRevenue = (clone $paidInvoices)
            ->whereMonth('created_at', $now->month)
            ->whereYear('created_at', $now->year)
            ->sum('grand_total');

        $lastMonthRevenue = (clone $paidInvoices)
            ->whereMonth('created_at', $now->copy()->subMonth()->month)
            ->whereYear('created_at', $now->copy()->subMonth()->year)
            ->sum('grand_total');

        $revenueChange = $lastMonthRevenue > 0
            ? (($currentMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100
            : 0;

        // =========================
        // PENDING & OVERDUE
        // =========================
        $pendingInvoices = Invoice::where('status', 'pending');

        $pendingCount = (clone $pendingInvoices)->count();
        $pendingTotal = (clone $pendingInvoices)->sum('grand_total');

        $overdueInvoices = (clone $pendingInvoices)
            ->where('due_date', '<', $now);

        $overdueCount = (clone $overdueInvoices)->count();
        $overdueTotal = (clone $overdueInvoices)->sum('grand_total');

        return [
            Stat::make('Total Revenue', $this->formatCurrency($totalRevenue))
                ->description('Total pendapatan yang sudah dibayar')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success')
                ->chart($this->getRevenueChart()),

            Stat::make('Bulan Ini', $this->formatCurrency($currentMonthRevenue))
                ->description($this->formatPercentage($revenueChange) . ' dari bulan lalu')
                ->descriptionIcon(
                    $revenueChange >= 0
                        ? 'heroicon-m-arrow-trending-up'
                        : 'heroicon-m-arrow-trending-down'
                )
                ->color($revenueChange >= 0 ? 'success' : 'danger'),

            Stat::make('Invoice Pending', $pendingCount)
                ->description($this->formatCurrency($pendingTotal))
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),

            Stat::make('Terlambat', $overdueCount)
                ->description($this->formatCurrency($overdueTotal))
                ->descriptionIcon('heroicon-m-exclamation-circle')
                ->color('danger'),
        ];
    }

    public static function canView(): bool
    {
        return false;
    }

    // =========================
    // HELPERS
    // =========================

    private function formatCurrency(float|int $amount): HtmlString
    {
        return new HtmlString(
            '<span class="text-sm font-medium text-gray-400">Rp</span> 
            <span class="text-3xl font-semibold tracking-tight">' 
            . number_format($amount, 0, ',', '.') . 
            '</span>'
        );
    }


    private function formatPercentage(float $value): string
    {
        return ($value >= 0 ? '+' : '') . number_format($value, 1) . '%';
    }

    private function getRevenueChart(): array
    {
        $data = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->toDateString();

            $data[] = Invoice::where('status', 'paid')
                ->whereDate('created_at', $date)
                ->sum('grand_total');
        }

        return $data;
    }
}
