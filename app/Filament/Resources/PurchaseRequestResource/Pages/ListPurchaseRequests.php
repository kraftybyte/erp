<?php

namespace App\Filament\Resources\PurchaseRequestResource\Pages;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\PurchaseRequestResource;
use Filament\Actions;
use Illuminate\Support\Facades\Auth;
use Filament\Resources\Pages\ListRecords;
use App\Models\PurchaseRequest;


class ListPurchaseRequests extends ListRecords
{
    protected static string $resource = PurchaseRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
    
     protected function getTableQuery(): Builder
    {
        $query = parent::getTableQuery();

        if (Auth::user()->can('restore', PurchaseRequest::class)) {
            return $query;
        }

        return $query->where('user_id', Auth::id());
    }
}
