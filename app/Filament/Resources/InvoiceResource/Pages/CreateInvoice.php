<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Models\Deal;
use TomatoPHP\FilamentInvoices\Filament\Resources\InvoiceResource\Pages\CreateInvoice as BaseCreateInvoice;

class CreateInvoice extends BaseCreateInvoice
{
    protected static string $resource = \App\Filament\Resources\InvoiceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Bawaan paket mengisi user_id (pembuat invoice). Tanpa ini simpan gagal:
        // kolom user_id wajib dan tidak punya nilai default.
        $data = parent::mutateFormDataBeforeCreate($data);

        /*
        | Simpan tautan ke Deal; isi field-nya datang dari form.
        |
        | _deal_id hanyalah pemilih di form. Tanpa baris ini tautannya ikut
        | terbuang dan invoice baru lahir tanpa deal_id — persis seperti seluruh
        | invoice lama. Nama dan kontak tidak ditimpa di sini karena dropdown
        | sudah mengisinya saat dipilih, dan pengguna boleh menyuntingnya.
        */
        $data['deal_id'] = !empty($data['_deal_id'])
            ? Deal::whereKey($data['_deal_id'])->value('id')
            : null;

        unset($data['_deal_id']);

        return $data;
    }
}
