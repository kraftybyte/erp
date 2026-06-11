<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use TomatoPHP\FilamentInvoices\Filament\Resources\InvoiceResource\Pages\ListInvoices as BaseListInvoices;

class ListInvoices extends BaseListInvoices
{
    protected static string $resource = \App\Filament\Resources\InvoiceResource::class;
}