<?php

namespace App\Filament\Resources\MetaAdAccountResource\Pages;

use App\Filament\Resources\MetaAdAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMetaAdAccounts extends ListRecords
{
    protected static string $resource = MetaAdAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
