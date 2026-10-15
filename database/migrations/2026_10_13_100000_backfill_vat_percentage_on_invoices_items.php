<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill vat_percentage pada invoices_items.
 *
 * Field ini tidak pernah ada di $fillable InvoicesItem, sehingga mass assignment
 * membuangnya diam-diam dan seluruh baris tersimpan dengan nilai 0. Akibatnya PDF
 * invoice — yang menghitung ulang VAT dari vat_percentage — mencetak Grand Total
 * tanpa PPN, berbeda dari grand_total yang tercatat di sistem.
 *
 * Persentase dihitung balik dari nominal `vat` yang sudah benar tersimpan, supaya
 * angka invoice yang sudah terbit tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('invoices_items', 'vat_percentage')) {
            return;
        }

        DB::table('invoices_items')
            ->where('vat', '>', 0)
            ->where(function ($query) {
                $query->whereNull('vat_percentage')
                    ->orWhere('vat_percentage', 0);
            })
            ->orderBy('id')
            ->chunkById(200, function ($items) {
                foreach ($items as $item) {
                    $base = ((float) $item->price * (float) $item->qty) - (float) $item->discount;

                    if ($base <= 0) {
                        continue;
                    }

                    DB::table('invoices_items')
                        ->where('id', $item->id)
                        ->update([
                            'vat_percentage' => round((float) $item->vat / $base * 100, 6),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Tidak di-reverse: nilai sebelumnya adalah 0 akibat bug, bukan data yang sah.
    }
};
