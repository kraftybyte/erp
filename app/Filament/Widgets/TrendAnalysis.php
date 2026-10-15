<?php

namespace App\Filament\Widgets;

use App\Models\Leads;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Expense;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget\Card;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;

class TrendAnalysis extends BaseWidget
{
    protected ?string $heading = 'Trend Analysis';
    protected int|string|array $columnSpan = 2;

    public static function canView(): bool
    {
        return true;
    }

    protected function getCards(): array
    {
        $currentMonth = Carbon::now()->startOfMonth();
        $lastMonth = Carbon::now()->subMonth()->startOfMonth();
        $currentYear = Carbon::now()->startOfYear();

        // Total data (all time)
        $totalLeads = Leads::count();
        $totalDeals = Deal::count();
        $totalRevenue = Invoice::where('status', 'paid')->sum('grand_total');
        $totalExpenses = Expense::whereIn('status', ['Disetujui', 'Selesai'])->sum('paid');

        // This month
        $currentLeads = Leads::whereBetween('created_at', [$currentMonth, now()])->count();
        $currentDeals = Deal::whereBetween('created_at', [$currentMonth, now()])->count();
        $currentRevenue = Invoice::where('status', 'paid')
            ->whereBetween('date', [$currentMonth, now()])
            ->sum('grand_total');
        $currentExpenses = Expense::whereIn('status', ['Disetujui', 'Selesai'])
            ->whereBetween('submission_date', [$currentMonth, now()])
            ->sum('paid');

        // Last month
        $lastLeads = Leads::whereBetween('created_at', [$lastMonth, $currentMonth->copy()->endOfMonth()])->count();
        $lastDeals = Deal::whereBetween('created_at', [$lastMonth, $currentMonth->copy()->endOfMonth()])->count();
        $lastRevenue = Invoice::where('status', 'paid')
            ->whereBetween('date', [$lastMonth, $currentMonth->copy()->endOfMonth()])
            ->sum('grand_total');
        $lastExpenses = Expense::whereIn('status', ['Disetujui', 'Selesai'])
            ->whereBetween('submission_date', [$lastMonth, $currentMonth->copy()->endOfMonth()])
            ->sum('paid');

        // Growth calculations
        $leadsGrowth = $lastLeads > 0 ? round((($currentLeads - $lastLeads) / $lastLeads) * 100, 1) : ($currentLeads > 0 ? 100 : 0);
        $dealsGrowth = $lastDeals > 0 ? round((($currentDeals - $lastDeals) / $lastDeals) * 100, 1) : ($currentDeals > 0 ? 100 : 0);
        $revenueGrowth = $lastRevenue > 0 ? round((($currentRevenue - $lastRevenue) / $lastRevenue) * 100, 1) : ($currentRevenue > 0 ? 100 : 0);
        $expensesGrowth = $lastExpenses > 0 ? round((($currentExpenses - $lastExpenses) / $lastExpenses) * 100, 1) : ($currentExpenses > 0 ? 100 : 0);

        return [
            Card::make('Total Leads', number_format($totalLeads))
                ->description('+' . $currentLeads . ' this month')
                ->descriptionColor('success')
                ->icon('heroicon-o-user-group'),

            Card::make('Total Deals', number_format($totalDeals))
                ->description('+' . $currentDeals . ' this month')
                ->descriptionColor('success')
                ->icon('heroicon-o-user-group'),

            Card::make('Total Revenue', 'Rp ' . number_format($totalRevenue / 1000000, 1) . 'M')
                ->description('+' . number_format($currentRevenue / 1000000, 1) . 'M this month')
                ->descriptionColor($revenueGrowth >= 0 ? 'success' : 'danger')
                ->icon('heroicon-o-currency-dollar'),

            Card::make('Total Expenses', 'Rp ' . number_format($totalExpenses / 1000000, 1) . 'M')
                ->description('+' . number_format($currentExpenses / 1000000, 1) . 'M this month')
                ->descriptionColor('warning')
                ->icon('heroicon-o-credit-card'),
        ];
    }
}