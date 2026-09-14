<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use TomatoPHP\FilamentInvoices\Filament\Resources\InvoiceResource\Pages\EditInvoice as BaseEditInvoice;
use Filament\Actions;
use App\Models\Deal;

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

        // Find matching Deal based on nama_perusahaan and nama_pic
        $deal = Deal::where('nama_perusahaan', $this->record->nama_perusahaan)
            ->where('nama_pic', $this->record->nama_pic)
            ->first();

        // Set _deal_id for the dropdown to show the correct selection
        $data['_deal_id'] = $deal?->id;

        // If deal found, ensure form uses Deal's data
        if ($deal) {
            // Switch to Deal mode
            $data['is_manual_company'] = false;

            // Override all company fields with fresh data from Deal
            $data['nama_perusahaan'] = $deal->nama_perusahaan ?? '-';
            $data['nama_pic'] = $deal->nama_pic ?? '-';
            $data['telephone'] = $deal->telephone ?? '';
            $data['alamat'] = $deal->alamat ?? '';
        } else {
            // No matching deal found, enable manual mode
            $data['is_manual_company'] = true;
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // If Deal is selected, sync company fields from Deal
        if (!empty($data['_deal_id'])) {
            $deal = Deal::find($data['_deal_id']);

            if ($deal) {
                $data['nama_perusahaan'] = $deal->nama_perusahaan ?? '-';
                $data['nama_pic'] = $deal->nama_pic ?? '-';
                $data['telephone'] = $deal->telephone ?? '';
                $data['alamat'] = $deal->alamat ?? '';
            }
        }

        // Remove helper field - don't save to database
        unset($data['_deal_id']);

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
