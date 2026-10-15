<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normalkan nilai type_payment pada invoices.
 *
 * Kode hanya mengenali 'full_payment', 'final_payment', dan 'dp_*'. Nilai lain —
 * tersimpan 'Full Payment' (berspasi, berkapital) dan sejumlah NULL dari invoice
 * lama — jatuh ke cabang terakhir yang menganggap semuanya DP, sehingga invoice
 * full payment tercetak sebagai "Down Payment 0%" di dokumen ke klien.
 *
 * NULL diisi 'full_payment' karena invoice tanpa tipe memang ditagih penuh:
 * grand_total-nya sudah selaras dengan item dan tidak ada jejak DP padanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('invoices', 'type_payment')) {
            return;
        }

        $dikenali = ['full_payment', 'final_payment'];

        DB::table('invoices')
            ->orderBy('id')
            ->chunkById(200, function ($invoices) use ($dikenali) {
                foreach ($invoices as $invoice) {
                    $asli = $invoice->type_payment;
                    $normal = strtolower(str_replace(' ', '_', trim((string) $asli)));

                    if ($normal === '') {
                        $normal = 'full_payment';
                    }

                    $valid = in_array($normal, $dikenali, true)
                        || preg_match('/^dp_(10|20|30|40|50|60|70|80|90)$/', $normal) === 1;

                    if (! $valid) {
                        // Nilai yang tidak bisa dipetakan dengan yakin dibiarkan apa
                        // adanya agar bisa ditinjau manusia, bukan ditebak di sini.
                        continue;
                    }

                    if ($normal !== $asli) {
                        DB::table('invoices')
                            ->where('id', $invoice->id)
                            ->update(['type_payment' => $normal]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Tidak di-reverse: nilai sebelumnya tidak dikenali aplikasi.
    }
};
