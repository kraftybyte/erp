<?php

namespace App\Filament\Resources\TaskStage\Pages;

use App\Filament\Resources\TaskStage\TaskStageResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTaskStage extends EditRecord
{
    protected static string $resource = TaskStageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        // Clear cache after updating
        \App\Models\TaskStage::clearCache();
    }
}
