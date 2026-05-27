<?php

namespace App\Filament\Widgets;

use TomatoPHP\FilamentInvoices\Models\Invoice;
use App\Models\Expense;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget\Card;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;

class RevenueAnalytics extends BaseWidget
{
    protected ?string $heading = 'Revenue Analytics';
    protected int|string|array $columnSpan = 2;

    public static function canView(): bool
    {
        return true;
    }

    protected function getCards(): array
    {
        $currentYear = Carbon::now()->startOfYear();
        $currentMonth = Carbon::now()->startOfMonth();
        $lastMonth = Carbon::now()->subMonth()->startOfMonth();

        // All time paid revenue
        $allTimeRevenue = Invoice::where('status', 'paid')->sum('grand_total');

        // This year revenue
        $yearRevenue = Invoice::where('status', 'paid')
            ->whereBetween('date', [$currentYear, now()])
            ->sum('grand_total');

        // This month revenue
        $currentRevenue = Invoice::where('status', 'paid')
            ->whereBetween('date', [$currentMonth, now()])
            ->sum('grand_total');

        // Last month revenue
        $lastRevenue = Invoice::where('status', 'paid')
            ->whereBetween('date', [$lastMonth, $currentMonth->copy()->endOfMonth()])
            ->sum('grand_total');

        // Billed this month (draft invoices)
        $billedThisMonth = Invoice::where('status', 'draft')
            ->whereBetween('date', [$currentMonth, now()])
            ->sum('grand_total');

        // All time expenses (Disetujui + Selesai)
        $allTimeExpenses = Expense::whereIn('status', ['Disetujui', 'Selesai'])->sum('paid');

        // This year expenses
        $yearExpenses = Expense::whereIn('status', ['Disetujui', 'Selesai'])
            ->whereBetween('submission_date', [$currentYear, now()])
            ->sum('paid');

        // Profit calculation
        $profit = $yearRevenue - $yearExpenses;
        $profitMargin = $yearRevenue > 0 ? round(($profit / $yearRevenue) * 100, 1) : 0;

        // Growth calculation
        $growth = $lastRevenue > 0 ? round((($currentRevenue - $lastRevenue) / $lastRevenue) * 100, 1) : ($currentRevenue > 0 ? 100 : 0);

        // Overdue invoices
        $overdueInvoices = Invoice::where('status', 'draft')
            ->where('due_date', '<', now())
            ->count();
        $overdueAmount = Invoice::where('status', 'draft')
            ->where('due_date', '<', now())
            ->sum('grand_total');

        // Total invoices
        $totalInvoices = Invoice::count();

        return [
            Card::make('Total Revenue', 'Rp ' . number_format($allTimeRevenue / 1000000, 1) . 'M')
                ->description(number_format($totalInvoices) . ' invoices')
                ->descriptionColor('success')
                ->icon('heroicon-o-currency-dollar'),

            Card::make('Revenue This Year', 'Rp ' . number_format($yearRevenue / 1000000, 1) . 'M')
                ->description($growth > 0 ? "+{$growth}% from last month" : ($growth < 0 ? "{$growth}%" : "No change"))
                ->descriptionColor($growth >= 0 ? 'success' : 'danger')
                ->icon('heroicon-o-arrow-trending-up'),

            Card::make('Billed (Unpaid)', 'Rp ' . number_format($billedThisMonth / 1000000, 1) . 'M')
                ->description('Draft invoices')
                ->icon('heroicon-o-document-text'),

            Card::make('Total Expenses', 'Rp ' . number_format($allTimeExpenses / 1000000, 1) . 'M')
                ->description('YTD: Rp ' . number_format($yearExpenses / 1000000, 1) . 'M')
                ->descriptionColor($yearExpenses > 0 ? 'warning' : 'gray')
                ->icon('heroicon-o-credit-card'),

            Card::make('Profit YTD', 'Rp ' . number_format($profit / 1000000, 1) . 'M')
                ->description('Margin: ' . $profitMargin . '%')
                ->descriptionColor($profitMargin >= 20 ? 'success' : ($profitMargin >= 10 ? 'warning' : 'danger'))
                ->icon('heroicon-o-chart-pie'),

            Card::make('Overdue Invoices', $overdueInvoices)
                ->description('Rp ' . number_format($overdueAmount / 1000000, 1) . 'M overdue')
                ->descriptionColor($overdueInvoices > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-exclamation-triangle'),
        ];
    }
}