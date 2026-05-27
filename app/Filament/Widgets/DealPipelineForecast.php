<?php

namespace App\Filament\Widgets;

use App\Models\Deal;
use App\Models\Quotation;
use Filament\Widgets\StatsOverviewWidget\Card;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;

class DealPipelineForecast extends BaseWidget
{
    protected ?string $heading = 'Deal Pipeline Forecast';
    protected int|string|array $columnSpan = 2;

    public static function canView(): bool
    {
        return true;
    }

    protected function getCards(): array
    {
        $deals = Deal::all();

        // Calculate deal values by stage
        $stages = [
            'Negosiasi' => ['color' => 'warning', 'value' => 0, 'count' => 0],
            'Menunggu Pembayaran' => ['color' => 'info', 'value' => 0, 'count' => 0],
            'Dalam Tahap Project' => ['color' => 'success', 'value' => 0, 'count' => 0],
            'Selesai' => ['color' => 'gray', 'value' => 0, 'count' => 0],
        ];

        foreach ($deals as $deal) {
            $dealValue = Quotation::where('deal_id', $deal->id)
                ->where('status', 'Approved')
                ->sum('grand_total');

            if (!isset($stages[$deal->status])) {
                $stages[$deal->status] = ['color' => 'gray', 'value' => 0, 'count' => 0];
            }

            $stages[$deal->status]['value'] += $dealValue;
            $stages[$deal->status]['count']++;
        }

        // Total pipeline (active negotiations)
        $totalPipeline = $stages['Negosiasi']['value'] + $stages['Menunggu Pembayaran']['value'];

        // Win rate calculation
        $wonDeals = $deals->where('status', 'Selesai')->count();
        $totalClosed = $deals->whereIn('status', ['Selesai', 'Hold'])->count();
        $winRate = $totalClosed > 0 ? round(($wonDeals / $totalClosed) * 100, 1) : 0;

        // Average deal size
        $avgDealSize = $deals->whereIn('status', ['Selesai', 'Dalam Tahap Project'])
            ->map(fn ($deal) => Quotation::where('deal_id', $deal->id)->sum('grand_total'))
            ->avg() ?? 0;

        return [
            Card::make('Total Pipeline', 'Rp ' . number_format($totalPipeline / 1000000, 1) . 'M')
                ->description('Active negotiations')
                ->icon('heroicon-o-chart-bar'),

            Card::make('Negosiasi', $stages['Negosiasi']['count'] . ' deals')
                ->description('Rp ' . number_format($stages['Negosiasi']['value'] / 1000000, 1) . 'M')
                ->descriptionColor('warning')
                ->icon('heroicon-o-chat-bubble-left-ellipsis'),

            Card::make('Menunggu Bayar', $stages['Menunggu Pembayaran']['count'] . ' deals')
                ->description('Rp ' . number_format($stages['Menunggu Pembayaran']['value'] / 1000000, 1) . 'M')
                ->descriptionColor('info')
                ->icon('heroicon-o-clock'),

            Card::make('In Project', $stages['Dalam Tahap Project']['count'] . ' deals')
                ->description('Rp ' . number_format($stages['Dalam Tahap Project']['value'] / 1000000, 1) . 'M')
                ->descriptionColor('success')
                ->icon('heroicon-o-rocket-launch'),

            Card::make('Win Rate', $winRate . '%')
                ->description('Avg: Rp ' . number_format($avgDealSize / 1000000, 1) . 'M')
                ->descriptionColor($winRate >= 30 ? 'success' : 'warning')
                ->icon('heroicon-o-trophy'),
        ];
    }
}