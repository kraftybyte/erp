<?php

namespace App\Filament\Resources\PerformanceIndicatorResource\Pages;

use App\Filament\Resources\PerformanceIndicatorResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;


class ListPerformanceIndicators extends ListRecords
{
    protected static string $resource = PerformanceIndicatorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()->where('status', 'done');
    }
}
