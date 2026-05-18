<?php

namespace App\Filament\Resources\BudgetLogResource\Pages;

use App\Filament\Resources\BudgetLogResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateBudgetLog extends CreateRecord
{
    protected static string $resource = BudgetLogResource::class;
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
