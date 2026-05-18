<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use App\Filament\Widgets\InvoiceStatsOverview;
use App\Filament\Widgets\InvoiceRevenueChart;
use App\Filament\Widgets\InvoiceStatusChart;
use App\Filament\Widgets\RecentInvoicesTable;
use App\Filament\Widgets\TopExpensesChart;
use App\Filament\Widgets\TopAdvancesChart;
use App\Filament\Widgets\FinancialAnalyzer;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;

class AnalyticsDashboard extends Page
{
    use HasPageShield;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';
    protected static ?string $navigationLabel = 'Financial Analytics';
    protected static ?string $title = 'Financial Analytics Dashboard';
    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): ?string
    {
        return __("Finance");
    }

    protected static string $view = 'filament.pages.analytics-dashboard';

    public function getHeaderWidgets(): array
    {
        return [
            FinancialAnalyzer::class,
        ];
    }

    public function getFooterWidgets(): array
    {
        return [
            InvoiceStatsOverview::class,
            InvoiceRevenueChart::class,
            InvoiceStatusChart::class,
            TopExpensesChart::class,
            TopAdvancesChart::class,
            RecentInvoicesTable::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int | array
    {
        return [
            'default' => 1,
            'md'      => 2,
            'xl'      => 1,
        ];
    }

    public function getFooterWidgetsColumns(): int | array
    {
        return [
            'default' => 1,
            'md'      => 2,
            'xl'      => 4,
        ];
    }
}