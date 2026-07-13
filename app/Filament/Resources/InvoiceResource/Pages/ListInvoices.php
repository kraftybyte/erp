<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use TomatoPHP\FilamentInvoices\Filament\Resources\InvoiceResource\Pages\ListInvoices as BaseListInvoices;
use TomatoPHP\FilamentInvoices\Facades\FilamentInvoices;
use Filament\Resources\Pages\ListRecords\Tab;

class ListInvoices extends BaseListInvoices
{
    protected static string $resource = \App\Filament\Resources\InvoiceResource::class;

    public function mount(): void
    {
        parent::mount();
        FilamentInvoices::loadTypes();
    }

    public function getTabs(): array
    {
        $tabs = [
            null => Tab::make('All'),
            'Proforma Invoice' => Tab::make()->query(fn ($query) => $query->where('type', '=', 'estimate')),
            'Invoice' => Tab::make()->query(fn ($query) => $query->where('type', '=', 'sale')),
        ];

        return $tabs;
    }
}