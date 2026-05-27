<?php

namespace App\Filament\Widgets;

use App\Models\Leads;
use App\Models\Deal;
use App\Models\Quotation;
use Filament\Widgets\StatsOverviewWidget\Card;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;

class LeadConversionFunnel extends BaseWidget
{
    protected ?string $heading = 'Lead Conversion Funnel';
    protected int|string|array $columnSpan = 2;

    public static function canView(): bool
    {
        return true;
    }

    protected function getCards(): array
    {
        $totalLeads = Leads::count();
        $convertedLeads = Leads::where('is_converted', true)->count();
        $activeDeals = Deal::whereNotIn('status', ['Selesai', 'Hold'])->count();
        $completedDeals = Deal::where('status', 'Selesai')->count();

        $pendingQuotations = Quotation::where('status', 'Pending')->count();
        $approvedQuotations = Quotation::where('status', 'Approved')->count();
        $totalQuotations = Quotation::count();

        // Conversion rates
        $leadToDealRate = $totalLeads > 0 ? round(($convertedLeads / $totalLeads) * 100, 1) : 0;
        $dealToQuotationRate = $convertedLeads > 0 ? round(($activeDeals / $convertedLeads) * 100, 1) : 0;

        return [
            Card::make('Total Leads', number_format($totalLeads))
                ->description($convertedLeads . ' converted (' . $leadToDealRate . '%)')
                ->descriptionColor('success')
                ->icon('heroicon-o-user-group'),

            Card::make('Active Deals', number_format($activeDeals))
                ->description($completedDeals . ' completed')
                ->descriptionColor('info')
                ->icon('heroicon-o-currency-dollar'),

            Card::make('Quotations', number_format($totalQuotations))
                ->description($approvedQuotations . ' approved, ' . $pendingQuotations . ' pending')
                ->icon('heroicon-o-document-text'),

            Card::make('Conversion Rate', $leadToDealRate . '%')
                ->description('Lead to Deal')
                ->descriptionColor($leadToDealRate > 20 ? 'success' : 'warning')
                ->icon('heroicon-o-chart-bar'),
        ];
    }
}