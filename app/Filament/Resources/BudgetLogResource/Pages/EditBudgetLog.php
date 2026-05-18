<?php

namespace App\Filament\Resources\BudgetLogResource\Pages;

use App\Filament\Resources\BudgetLogResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBudgetLog extends EditRecord
{
    protected static string $resource = BudgetLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
