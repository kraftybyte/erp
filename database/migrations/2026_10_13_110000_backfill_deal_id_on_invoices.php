<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill deal_id pada invoices.
 *
 * Form invoice memakai _deal_id hanya untuk menyalin nama perusahaan/PIC, lalu
 * membuangnya sebelum simpan — sehingga kolom deal_id tidak pernah terisi dan
 * invoice hanya bisa dicocokkan ke Deal lewat kecocokan nama. Tautan itu putus
 * begitu nama perusahaan diubah, dan tercampur bila ada dua Deal bernama sama.
 *
 * Pencocokan di sini sengaja konservatif: hanya mengisi bila kandidatnya tidak
 * ambigu. Invoice yang tidak bisa dipastikan dibiarkan kosong, karena menautkan
 * ke Deal yang salah lebih berbahaya daripada membiarkannya tidak tertaut.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('invoices', 'deal_id')) {
            return;
        }

        DB::table('invoices')
            ->whereNull('deal_id')
            ->whereNotNull('nama_perusahaan')
            ->orderBy('id')
            ->chunkById(200, function ($invoices) {
                foreach ($invoices as $invoice) {
                    $dealId = $this->cariDealTunggal(
                        $invoice->nama_perusahaan,
                        $invoice->nama_pic ?? null
                    );

                    if ($dealId !== null) {
                        DB::table('invoices')
                            ->where('id', $invoice->id)
                            ->update(['deal_id' => $dealId]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Tidak di-reverse: nilai sebelumnya NULL karena bug, bukan data yang sah.
    }

    /**
     * Kembalikan id Deal hanya bila kecocokannya tunggal dan tidak ambigu.
     */
    private function cariDealTunggal(string $namaPerusahaan, ?string $namaPic): ?int
    {
        // Paling tepat: perusahaan DAN PIC sama-sama cocok.
        if (filled($namaPic) && $namaPic !== '-') {
            $cocokKeduanya = DB::table('deals')
                ->where('nama_perusahaan', $namaPerusahaan)
                ->where('nama_pic', $namaPic)
                ->pluck('id');

            if ($cocokKeduanya->count() === 1) {
                return (int) $cocokKeduanya->first();
            }
        }

        // Cadangan: nama perusahaan saja, tapi hanya bila tidak ambigu.
        $cocokPerusahaan = DB::table('deals')
            ->where('nama_perusahaan', $namaPerusahaan)
            ->pluck('id');

        return $cocokPerusahaan->count() === 1
            ? (int) $cocokPerusahaan->first()
            : null;
    }
};
