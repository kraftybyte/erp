<?php

namespace App\Filament\Resources\TaskStage\Pages;

use App\Filament\Resources\TaskStage\TaskStageResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTaskStages extends ListRecords
{
    protected static string $resource = TaskStageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Add Stage'),
        ];
    }
}
