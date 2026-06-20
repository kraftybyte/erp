<?php

namespace App\Filament\Resources\TaskStage\Pages;

use App\Filament\Resources\TaskStage\TaskStageResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateTaskStage extends CreateRecord
{
    protected static string $resource = TaskStageResource::class;

    protected function afterCreate(): void
    {
        // Clear cache after creating
        \App\Models\TaskStage::clearCache();
    }
}
