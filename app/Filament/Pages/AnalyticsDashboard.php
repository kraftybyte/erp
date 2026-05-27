<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use App\Filament\Widgets\FinancialAnalyzer;
use App\Filament\Widgets\RevenueAnalytics;
use App\Filament\Widgets\TrendAnalysis;
use App\Filament\Widgets\ClientLifetimeValue;
use App\Filament\Widgets\DealPipelineForecast;
use App\Filament\Widgets\BudgetUtilization;
use App\Filament\Widgets\ExpenseCategoryAnalysis;
use App\Filament\Widgets\InvoiceStatsOverview;
use App\Filament\Widgets\InvoiceRevenueChart;
use App\Filament\Widgets\InvoiceStatusChart;
use App\Filament\Widgets\EmployeeProductivity;
use App\Filament\Widgets\LeadConversionFunnel;
use App\Filament\Widgets\SocialROIDashboard;
use App\Filament\Widgets\TopExpensesChart;
use App\Filament\Widgets\TopAdvancesChart;
use App\Filament\Widgets\RecentInvoicesTable;
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
            RevenueAnalytics::class,
        ];
    }

    public function getFooterWidgets(): array
    {
        return [
            TrendAnalysis::class,
            ClientLifetimeValue::class,
            DealPipelineForecast::class,
            BudgetUtilization::class,
            ExpenseCategoryAnalysis::class,
            InvoiceStatsOverview::class,
            InvoiceRevenueChart::class,
            InvoiceStatusChart::class,
            EmployeeProductivity::class,
            LeadConversionFunnel::class,
            SocialROIDashboard::class,
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
        ];
    }

    public function getFooterWidgetsColumns(): int | array
    {
        return [
            'default' => 1,
            'md'      => 2,
            'lg'      => 3,
        ];
    }
}