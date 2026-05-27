<?php

namespace App\Filament\Widgets;

use App\Models\Budget;
use App\Models\Expense;
use App\Models\Advance;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget\Card;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;

class BudgetUtilization extends BaseWidget
{
    protected int|string|array $columnSpan = 2;

    public static function canView(): bool
    {
        return true;
    }

    protected function getCards(): array
    {
        $budgets = Budget::all();
        $totalBudget = $budgets->sum('amount');

        // Calculate used amount from all expenses and advances
        $allExpenses = Expense::whereIn('status', ['Disetujui', 'Selesai'])->sum('paid');
        $allAdvances = Advance::whereIn('status', ['Disetujui', 'Selesai'])->sum('paid');
        $usedAmount = $allExpenses + $allAdvances;

        $utilizationRate = $totalBudget > 0 ? round(($usedAmount / $totalBudget) * 100, 1) : 0;
        $remaining = $totalBudget - $usedAmount;

        // Monthly spending
        $currentMonth = Carbon::now()->startOfMonth();
        $monthlyExpenses = Expense::whereIn('status', ['Disetujui', 'Selesai'])
            ->whereBetween('submission_date', [$currentMonth, now()])
            ->sum('paid');
        $monthlyAdvances = Advance::whereIn('status', ['Disetujui', 'Selesai'])
            ->whereBetween('submission_date', [$currentMonth, now()])
            ->sum('paid');
        $monthlySpending = $monthlyExpenses + $monthlyAdvances;

        // Budget count
        $budgetCount = $budgets->count();

        // Low balance budgets (below 20%)
        $criticalCount = $budgets->filter(fn ($b) => $b->amount > 0 && (($usedAmount / $b->amount) >= 0.8))->count();

        return [
            Card::make('Total Budget', 'Rp ' . number_format($totalBudget / 1000000, 1) . 'M')
                ->description(number_format($utilizationRate) . '% utilized')
                ->descriptionColor($utilizationRate >= 80 ? 'warning' : 'success')
                ->icon('heroicon-o-wallet'),

            Card::make('Used Amount', 'Rp ' . number_format($usedAmount / 1000000, 1) . 'M')
                ->description('Remaining: Rp ' . number_format($remaining / 1000000, 1) . 'M')
                ->icon('heroicon-o-credit-card'),

            Card::make('Monthly Spending', 'Rp ' . number_format($monthlySpending / 1000000, 1) . 'M')
                ->description('This month')
                ->icon('heroicon-o-calendar'),

            Card::make('Budget Count', number_format($budgetCount))
                ->description($criticalCount . ' critical (>80%)')
                ->descriptionColor($criticalCount > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-calculator'),
        ];
    }
}