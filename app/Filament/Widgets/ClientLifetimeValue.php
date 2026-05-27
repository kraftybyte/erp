<?php

namespace App\Filament\Widgets;

use App\Models\Deal;
use App\Models\Quotation;
use App\Models\Project;
use Filament\Widgets\StatsOverviewWidget\Card;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;

class ClientLifetimeValue extends BaseWidget
{
    protected ?string $heading = 'Client Lifetime Value';
    protected int|string|array $columnSpan = 2;

    public static function canView(): bool
    {
        return true;
    }

    protected function getCards(): array
    {
        // All time clients
        $totalClients = Deal::distinct('nama_perusahaan')->count('nama_perusahaan');
        $totalDeals = Deal::count();

        // Revenue from all approved quotations
        $totalRevenue = Quotation::where('status', 'Approved')->sum('grand_total');

        // Average client value
        $avgClientValue = $totalClients > 0 ? $totalRevenue / $totalClients : 0;

        // Active deals (ongoing projects)
        $activeDeals = Deal::whereIn('status', ['Negosiasi', 'Menunggu Pembayaran', 'Dalam Tahap Project'])->count();

        // Repeat clients (clients with multiple deals)
        $repeatClients = Deal::selectRaw('nama_perusahaan, COUNT(*) as deal_count')
            ->groupBy('nama_perusahaan')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        // Completed deals
        $completedDeals = Deal::where('status', 'Selesai')->count();

        // Top client by revenue
        $topClient = Deal::selectRaw('nama_perusahaan, SUM(COALESCE((SELECT SUM(grand_total) FROM quotations WHERE quotations.deal_id = deals.id AND quotations.status = "Approved"), 0)) as total_revenue')
            ->groupBy('nama_perusahaan')
            ->orderByRaw('total_revenue DESC')
            ->first();

        return [
            Card::make('Total Clients', number_format($totalClients))
                ->description($totalDeals . ' total deals')
                ->icon('heroicon-o-building-office'),

            Card::make('Total Revenue', 'Rp ' . number_format($totalRevenue / 1000000, 1) . 'M')
                ->description('From approved quotations')
                ->icon('heroicon-o-currency-dollar'),

            Card::make('Avg Client Value', 'Rp ' . number_format($avgClientValue / 1000000, 1) . 'M')
                ->description('Per client')
                ->icon('heroicon-o-calculator'),

            Card::make('Active Deals', number_format($activeDeals))
                ->description($completedDeals . ' completed')
                ->descriptionColor($activeDeals > 0 ? 'info' : 'gray')
                ->icon('heroicon-o-folder'),

            Card::make('Repeat Clients', number_format($repeatClients))
                ->description('Multiple projects')
                ->descriptionColor($repeatClients > 0 ? 'success' : 'gray')
                ->icon('heroicon-o-star'),

            Card::make('Top Client', $topClient ? 'Rp ' . number_format($topClient->total_revenue / 1000000, 1) . 'M' : 'N/A')
                ->description($topClient->nama_perusahaan ?? '-')
                ->descriptionColor('success')
                ->icon('heroicon-o-trophy'),
        ];
    }
}