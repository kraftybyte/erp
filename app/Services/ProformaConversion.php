<?php

namespace App\Services;

use App\Exceptions\InvoicePaymentException;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * Proforma (#PI) menjadi invoice penjualan (#SC): nomor SC baru, nomor PI
 * lamanya disimpan di proforma_uuid supaya tidak diberikan ke proforma lain.
 * Satu jalur untuk tombol "Convert to Invoice" di ERP dan pencatatan mutasi
 * rekening (klien sering mentransfer berdasarkan proforma).
 */
class ProformaConversion
{
    /**
     * @param  array{faktur?: ?string, delivery_note_number?: ?string}  $extra
     *
     * @throws InvoicePaymentException
     */
    public function toInvoice(Invoice $invoice, array $extra = []): Invoice
    {
        return DB::transaction(function () use ($invoice, $extra) {
            $invoice = Invoice::lockForUpdate()->find($invoice->getKey());

            if (! $invoice || $invoice->type !== 'estimate') {
                throw new InvoicePaymentException('Proforma ini sudah dikonversi menjadi invoice atau sudah dihapus.');
            }

            $invoice->update([
                'type'          => 'sale',
                'uuid'          => Invoice::generateUuid('sale'),
                'proforma_uuid' => $invoice->uuid,
            ] + array_intersect_key($extra, array_flip(['faktur', 'delivery_note_number'])));

            return $invoice;
        });
    }
}
