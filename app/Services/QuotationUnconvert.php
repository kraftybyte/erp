<?php

namespace App\Services;

use App\Exceptions\QuotationUnconvertException;
use App\Models\Invoice;
use App\Models\Quotation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Membatalkan konversi quotation ke Proforma Invoice: proforma yang dibuat
 * darinya dihapus (soft delete, masih bisa dipulihkan) dan status quotation
 * dikembalikan, sehingga quotation bisa diedit lalu dikonversi ulang.
 *
 * Ditolak bila proforma itu sudah menjadi invoice, sudah menerima pembayaran,
 * atau sudah dipakai untuk project / invoice lanjutan: data uang tidak ikut
 * terhapus diam-diam.
 */
class QuotationUnconvert
{
    /** Status quotation setelah konversi dibatalkan. */
    public const STATUSES = ['Approved' => 'Approved', 'Pending' => 'Pending'];

    /**
     * Proforma yang dibuat dari quotation ini dan belum dihapus.
     *
     * @return Collection<int, Invoice>
     */
    public static function invoices(Quotation $quotation): Collection
    {
        return Invoice::withCount(['projects', 'childInvoices'])
            ->where('for_type', 'quotation')
            ->where('for_id', $quotation->getKey())
            ->orderBy('id')
            ->get();
    }

    /**
     * Alasan konversi tidak bisa dibatalkan; kosong bila bisa.
     *
     * @param  ?Collection<int, Invoice>  $invoices
     * @return array<int, string>
     */
    public static function blockers(Quotation $quotation, ?Collection $invoices = null): array
    {
        return ($invoices ?? self::invoices($quotation))
            ->map(fn (Invoice $invoice) => match (true) {
                $invoice->type !== 'estimate'       => "{$invoice->uuid} sudah dikonversi menjadi invoice.",
                (float) $invoice->paid > 0          => "{$invoice->uuid} sudah menerima pembayaran.",
                $invoice->projects_count > 0        => "{$invoice->uuid} sudah dipakai untuk project.",
                $invoice->child_invoices_count > 0  => "{$invoice->uuid} sudah punya invoice lanjutan.",
                default                             => null,
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, string> nomor proforma yang dihapus (sebelum diberi tanda BATAL)
     */
    public static function run(Quotation $quotation, string $status): Collection
    {
        return DB::transaction(function () use ($quotation, $status) {
            $quotation = Quotation::lockForUpdate()->findOrFail($quotation->getKey());

            if ($quotation->status !== 'Converted') {
                throw new QuotationUnconvertException('Quotation ini tidak berstatus Converted.');
            }

            // Dikunci supaya proforma tidak dibayar atau dikonversi selagi dihapus.
            Invoice::where('for_type', 'quotation')->where('for_id', $quotation->getKey())->lockForUpdate()->get();
            $invoices = self::invoices($quotation);

            if ($blockers = self::blockers($quotation, $invoices)) {
                throw new QuotationUnconvertException(implode(' ', $blockers));
            }

            if (! isset(self::STATUSES[$status])) {
                throw new QuotationUnconvertException("Status {$status} tidak dikenal.");
            }

            $numbers = $invoices->pluck('uuid');
            $invoices->each->delete();
            $quotation->update(['status' => $status]);

            return $numbers;
        });
    }
}
