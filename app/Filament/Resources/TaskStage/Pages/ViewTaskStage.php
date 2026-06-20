<?php

namespace App\Filament\Resources\TaskStage\Pages;

use App\Filament\Resources\TaskStage\TaskStageResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewTaskStage extends ViewRecord
{
    protected static string $resource = TaskStageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
