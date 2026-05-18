<?php

namespace App\Filament\Resources\ProjectTimelineResource\Pages;

use App\Filament\Resources\ProjectTimelineResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProjectTimelines extends ListRecords
{
    protected static string $resource = ProjectTimelineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
