<?php

namespace App\Filament\Resources\AdvanceResource\Pages;

use App\Filament\Resources\AdvanceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListAdvances extends ListRecords
{
    protected static string $resource = AdvanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }



    
}
