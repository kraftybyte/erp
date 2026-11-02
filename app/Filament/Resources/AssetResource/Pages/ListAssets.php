<?php

namespace App\Filament\Resources\AssetResource\Pages;

use App\Filament\Resources\AssetResource;
use App\Filament\Resources\AssetResource\Widgets\AssetDepreciationStats;
use App\Services\AssetDepreciation;
use Filament\Forms;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListAssets extends ListRecords
{
    protected static string $resource = AssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('unduhPenyusutan')
                ->label('Unduh penyusutan')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn () => AssetDepreciation::visibleTo(auth()->user()))
                ->modalHeading('Unduh daftar penyusutan')
                ->modalDescription('Excel per aset: harga perolehan, akumulasi awal tahun, penyusutan tahun itu, dan nilai buku akhir tahun.')
                ->modalSubmitActionLabel('Unduh')
                ->form([
                    Forms\Components\Select::make('tahun')
                        ->label('Tahun buku')
                        ->options(fn () => collect(range(now()->year, now()->year - 6))->mapWithKeys(fn ($year) => [$year => $year]))
                        ->default(now()->year)
                        ->required(),
                ])
                ->action(fn (array $data) => redirect()->route('asset.depreciation', $data['tahun'])),
            Actions\CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [AssetDepreciationStats::class];
    }

}
