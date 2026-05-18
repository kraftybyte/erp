<?php

namespace App\Filament\Resources\ConnectedAccountMetricResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListConnectedAccountMetrics extends ListRecords
{
    protected static string $resource = \App\Filament\Resources\ConnectedAccountMetricResource\ConnectedAccountMetricResource::class;

    protected static ?string $title = 'Social Media Metrics';

    protected function getHeaderActions(): array
    {
        return [];
    }
}