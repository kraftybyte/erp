<?php

namespace App\Filament\Resources\AssetResource\Widgets;

use App\Helpers\Money;
use App\Models\Asset;
use App\Services\AssetDepreciation;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Nilai aset yang masih dimiliki per akhir bulan ini. Hanya untuk Finance,
 * Director, dan super_admin: karyawan lain hanya melihat aset tanggungannya.
 */
class AssetDepreciationStats extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        return AssetDepreciation::visibleTo(auth()->user());
    }

    protected function getStats(): array
    {
        $sum = app(AssetDepreciation::class)->summary(Asset::all());

        return array_filter([
            Stat::make('Harga perolehan', Money::rupiah($sum['cost']))
                ->description(number_format($sum['count'], 0, ',', '.') . ' aset yang masih dimiliki')
                ->icon('heroicon-o-archive-box'),
            Stat::make('Akumulasi penyusutan', Money::rupiah($sum['accumulated']))
                ->description('Sampai akhir bulan ini')
                ->icon('heroicon-o-arrow-trending-down'),
            Stat::make('Nilai buku', Money::rupiah($sum['book']))
                ->description('Beban penyusutan bulan ini ' . Money::rupiah($sum['monthly']))
                ->icon('heroicon-o-calculator'),
            $sum['without_group'] ? Stat::make('Belum ada kelompok harta', number_format($sum['without_group'], 0, ',', '.'))
                ->description('Belum dihitung penyusutannya; isi kelompoknya di Edit')
                ->color('warning')
                ->icon('heroicon-o-exclamation-triangle') : null,
        ]);
    }
}
