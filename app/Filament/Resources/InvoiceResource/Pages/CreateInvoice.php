<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Models\Deal;
use App\Models\Invoice;
use TomatoPHP\FilamentInvoices\Filament\Resources\InvoiceResource\Pages\CreateInvoice as BaseCreateInvoice;

class CreateInvoice extends BaseCreateInvoice
{
    protected static string $resource = \App\Filament\Resources\InvoiceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Bawaan paket mengisi user_id (pembuat invoice). Tanpa ini simpan gagal:
        // kolom user_id wajib dan tidak punya nilai default.
        $data = parent::mutateFormDataBeforeCreate($data);

        // Nomor diambil saat simpan, bukan saat form dibuka (field uuid hanya pratinjau).
        $data['uuid'] = Invoice::generateUuid($data['type'] ?? 'sale');

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

        // Tautan ke invoice DP hanya berlaku untuk invoice pelunasan. Tipe yang
        // terkunci (sudah ada pembayaran) tidak ikut dikirim: tautannya dibiarkan.
        if (array_key_exists('type_payment', $data) && $data['type_payment'] !== 'final_payment') {
            $data['parent_invoice_id'] = null;
        }

        return $data;
    }

    /**
     * Dibuka dari tab Invoice di halaman Deal (?deal=ID): client langsung terisi
     * dan invoice tertaut ke Deal itu, sama seperti memilih "Ambil dari Deal".
     */
    protected function afterFill(): void
    {
        $deal = Deal::find(request()->query('deal'));

        if (! $deal) {
            return;
        }

        $this->data['_deal_id']        = $deal->id;
        $this->data['nama_perusahaan'] = $deal->nama_perusahaan;
        $this->data['nama_pic']        = $deal->nama_pic;
        $this->data['telephone']       = $deal->telephone ?? '';
        $this->data['alamat']          = $deal->alamat ?? '';
    }
}
