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

        /*
        | Tampilkan data yang tersimpan di invoice apa adanya.
        |
        | Sebelumnya blok ini mencari Deal yang cocok persis pada nama_perusahaan
        | DAN nama_pic, lalu menimpa seluruh field dengan data Deal tersebut.
        | Dua akibatnya:
        |
        |   1. Invoice hasil convert quotation yang tidak punya Deal cocok dilempar
        |      ke "mode manual", dan di mode itu field namanya memang tampil —
        |      tetapi tidak pernah tersimpan (lihat form: dehydrated(false)).
        |   2. Nama yang sudah sengaja disunting di invoice tertimpa diam-diam oleh
        |      nama Deal setiap kali halaman edit dibuka.
        |
        | Dropdown Deal kini sekadar pengisi otomatis, jadi cukup diarahkan ke
        | tautan yang memang tersimpan.
        */
        $data['_deal_id'] = $this->record->deal_id;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /*
        | Simpan tautan ke Deal; isi field-nya datang dari form.
        |
        | Dropdown Deal sudah mengisi nama/telepon/alamat lewat afterStateUpdated
        | saat dipilih, jadi menimpanya lagi di sini hanya akan membatalkan
        | suntingan yang sengaja dilakukan pengguna setelah memilih Deal.
        */
        $data['deal_id'] = !empty($data['_deal_id'])
            ? Deal::whereKey($data['_deal_id'])->value('id')
            : null;

        // Field bantu, bukan kolom tabel.
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
