<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use App\Models\Invoice;
use Illuminate\Support\HtmlString;


class InvoiceStatsOverview extends BaseWidget
{
    
 protected int | string | array $columnSpan = [
    'default' => 1,
    'xl' => 4,
];    protected function getStats(): array
    {
        $now = Carbon::now();

        // Single query for all status-based metrics using conditional aggregation
        $stats = Invoice::query()
            ->selectRaw("
                SUM(CASE WHEN status = 'paid' THEN grand_total ELSE 0 END) as total_revenue,
                SUM(CASE WHEN status = 'paid' AND MONTH(created_at) = ? AND YEAR(created_at) = ? THEN grand_total ELSE 0 END) as current_month_revenue,
                SUM(CASE WHEN status = 'paid' AND MONTH(created_at) = ? AND YEAR(created_at) = ? THEN grand_total ELSE 0 END) as last_month_revenue,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'pending' THEN grand_total ELSE 0 END) as pending_total,
                SUM(CASE WHEN status = 'pending' AND due_date < ? THEN 1 ELSE 0 END) as overdue_count,
                SUM(CASE WHEN status = 'pending' AND due_date < ? THEN grand_total ELSE 0 END) as overdue_total
            ", [
                $now->month, $now->year,
                $now->copy()->subMonth()->month, $now->copy()->subMonth()->year,
                $now->toDateString(), $now->toDateString()
            ])
            ->first();

        $totalRevenue = $stats->total_revenue ?? 0;
        $currentMonthRevenue = $stats->current_month_revenue ?? 0;
        $lastMonthRevenue = $stats->last_month_revenue ?? 0;
        $pendingCount = $stats->pending_count ?? 0;
        $pendingTotal = $stats->pending_total ?? 0;
        $overdueCount = $stats->overdue_count ?? 0;
        $overdueTotal = $stats->overdue_total ?? 0;

        $revenueChange = $lastMonthRevenue > 0
            ? (($currentMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100
            : 0;

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
        return true;
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
        $days = [];

        for ($i = 6; $i >= 0; $i--) {
            $days[] = Carbon::now()->subDays($i)->toDateString();
        }

        $chartData = Invoice::where('status', 'paid')
            ->whereDate('created_at', '>=', $days[0])
            ->whereDate('created_at', '<=', $days[6])
            ->selectRaw('DATE(created_at) as date, SUM(grand_total) as total')
            ->groupByRaw('DATE(created_at)')
            ->pluck('total', 'date');

        foreach ($days as $day) {
            $data[] = $chartData[$day] ?? 0;
        }

        return $data;
    }
}
