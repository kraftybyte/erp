<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use TomatoPHP\FilamentInvoices\Filament\Resources\InvoiceResource\Pages\EditInvoice as BaseEditInvoice;

class EditInvoice extends BaseEditInvoice
{
    protected static string $resource = \App\Filament\Resources\InvoiceResource::class;
}