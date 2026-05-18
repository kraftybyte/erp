<?php

namespace App\Filament\Resources\DealResource\Pages;

use App\Filament\Resources\DealResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDeal extends EditRecord
{
    protected static string $resource = DealResource::class;


    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
