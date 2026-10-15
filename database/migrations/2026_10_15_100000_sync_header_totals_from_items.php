<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Selaraskan subtotal / total_discount / total_vat / grand_total di header
 * quotations dan invoices dengan item-itemnya.
 *
 * Beberapa dokumen lama menyimpan angka header yang tidak pernah dihitung ulang
 * setelah itemnya berubah — ada invoice yang headernya grand_total = 0 padahal
 * itemnya berjumlah belasan juta. Karena PDF dicetak dari item, angka di layar
 * dan di dokumen bisa berbeda jauh.
 *
 * Rumusnya mengikuti satu-satunya rumus yang berlaku di aplikasi:
 *
 *     per baris: (price * qty) - discount + PPN      (discount = total per baris)
 *
 * Pengaman: dokumen yang sudah menerima pembayaran tidak disentuh. Angkanya
 * sudah masuk pembukuan, jadi selisih di sana harus ditinjau manusia, bukan
 * ditimpa diam-diam oleh migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->selaraskan('quotations', 'quotation_items', 'quotation_id');
        $this->selaraskan('invoices', 'invoices_items', 'invoice_id');
    }

    public function down(): void
    {
        // Tidak di-reverse: nilai sebelumnya tidak konsisten dengan itemnya.
    }

    private function selaraskan(string $tabel, string $tabelItem, string $kolomFk): void
    {
        if (! Schema::hasTable($tabel) || ! Schema::hasTable($tabelItem)) {
            return;
        }

        $punyaKolomPaid = Schema::hasColumn($tabel, 'paid');

        DB::table($tabel)
            ->orderBy('id')
            ->chunkById(200, function ($dokumen) use ($tabel, $tabelItem, $kolomFk, $punyaKolomPaid) {
                foreach ($dokumen as $doc) {
                    // Jangan sentuh dokumen yang sudah dibayar sebagian/penuh.
                    if ($punyaKolomPaid && (float) ($doc->paid ?? 0) > 0) {
                        continue;
                    }

                    $items = DB::table($tabelItem)->where($kolomFk, $doc->id)->get();

                    if ($items->isEmpty()) {
                        continue;
                    }

                    $subtotal = 0.0;
                    $totalDiskon = 0.0;
                    $totalVat = 0.0;

                    foreach ($items as $item) {
                        $baris = (float) $item->price * (float) $item->qty;
                        $diskon = (float) $item->discount;
                        $setelahDiskon = max($baris - $diskon, 0);

                        $subtotal += $baris;
                        $totalDiskon += $diskon;
                        $totalVat += $setelahDiskon * ((float) ($item->vat_percentage ?? 0) / 100);
                    }

                    $grandTotal = $subtotal - $totalDiskon + $totalVat;

                    $sudahSelaras = abs((float) $doc->subtotal - $subtotal) < 0.01
                        && abs((float) $doc->total_discount - $totalDiskon) < 0.01
                        && abs((float) $doc->total_vat - $totalVat) < 0.01
                        && abs((float) $doc->grand_total - $grandTotal) < 0.01;

                    if ($sudahSelaras) {
                        continue;
                    }

                    DB::table($tabel)->where('id', $doc->id)->update([
                        'subtotal' => round($subtotal, 2),
                        'total_discount' => round($totalDiskon, 2),
                        'total_vat' => round($totalVat, 2),
                        'grand_total' => round($grandTotal, 2),
                    ]);
                }
            });
    }
};
