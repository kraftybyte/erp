<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use TomatoPHP\FilamentInvoices\Filament\Resources\InvoiceResource\Pages\CreateInvoice as BaseCreateInvoice;

class CreateInvoice extends BaseCreateInvoice
{
    protected static string $resource = \App\Filament\Resources\InvoiceResource::class;
}