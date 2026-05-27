<?php

namespace App\Filament\Widgets;

use App\Models\Expense;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget\Card;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;

class ExpenseCategoryAnalysis extends BaseWidget
{
    protected ?string $heading = 'Expense Analysis';
    protected int|string|array $columnSpan = 2;

    public static function canView(): bool
    {
        return true;
    }

    protected function getCards(): array
    {
        $currentMonth = Carbon::now()->startOfMonth();
        $currentYear = Carbon::now()->startOfYear();

        $totalExpenses = Expense::whereIn('status', ['Disetujui', 'Selesai'])->sum('paid');
        $totalCount = Expense::whereIn('status', ['Disetujui', 'Selesai'])->count();

        $monthlyExpenses = Expense::whereIn('status', ['Disetujui', 'Selesai'])
            ->whereBetween('submission_date', [$currentMonth, now()])
            ->sum('paid');
        $monthlyCount = Expense::whereIn('status', ['Disetujui', 'Selesai'])
            ->whereBetween('submission_date', [$currentMonth, now()])
            ->count();

        $yearlyExpenses = Expense::whereIn('status', ['Disetujui', 'Selesai'])
            ->whereBetween('submission_date', [$currentYear, now()])
            ->sum('paid');

        $pendingExpenses = Expense::where('status', 'Pending')->sum('amount');
        $pendingCount = Expense::where('status', 'Pending')->count();

        $avgExpense = $totalCount > 0 ? $totalExpenses / $totalCount : 0;

        return [
            Card::make('Total Expenses', 'Rp ' . number_format($totalExpenses / 1000000, 1) . 'M')
                ->description($totalCount . ' transactions')
                ->icon('heroicon-o-banknotes'),

            Card::make('This Month', 'Rp ' . number_format($monthlyExpenses / 1000000, 1) . 'M')
                ->description($monthlyCount . ' items')
                ->icon('heroicon-o-calendar'),

            Card::make('YTD Expenses', 'Rp ' . number_format($yearlyExpenses / 1000000, 1) . 'M')
                ->description('Year to date')
                ->icon('heroicon-o-chart-bar'),

            Card::make('Pending', 'Rp ' . number_format($pendingExpenses / 1000000, 1) . 'M')
                ->description($pendingCount . ' awaiting')
                ->descriptionColor($pendingCount > 0 ? 'warning' : 'gray')
                ->icon('heroicon-o-clock'),

            Card::make('Avg Expense', 'Rp ' . number_format($avgExpense / 100000, 1) . 'M')
                ->description('Per transaction')
                ->icon('heroicon-o-calculator'),
        ];
    }
}