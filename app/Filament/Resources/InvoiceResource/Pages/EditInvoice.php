<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use TomatoPHP\FilamentInvoices\Filament\Resources\InvoiceResource\Pages\EditInvoice as BaseEditInvoice;
use Filament\Actions;

class EditInvoice extends BaseEditInvoice
{
    protected static string $resource = \App\Filament\Resources\InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->icon('heroicon-o-trash'),
            Actions\ViewAction::make()->icon('heroicon-o-eye'),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Eager load invoicesItems relationship for edit
        $this->record->load('invoicesItems');

        return $data;
    }

    public function afterSave()
    {
        $data = [];
        $data['discount'] = collect($this->getRecord()->invoicesItems)->sum(function ($item) {
            return $item->discount * $item->qty;
        });
        $data['vat'] = collect($this->getRecord()->invoicesItems)->sum(function ($item) {
            return $item->vat * $item->qty;
        });
        $data['total'] = collect($this->getRecord()->invoicesItems)->sum('total');

        $this->getRecord()->update($data);

        $this->getRecord()->invoiceLogs()->create([
            'log' => "Invoice Updated By: " . auth()->user()->name,
            'type' => 'updated'
        ]);
    }
}