<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill deal_id pada quotations.
 *
 * Sama seperti invoices: kolomnya sudah lama ada tapi tidak pernah terisi,
 * sehingga Deal::quotations() hanya bisa bersandar pada kecocokan nama
 * perusahaan — tautan yang putus begitu nama diubah dan tercampur bila ada
 * dua Deal bernama sama.
 *
 * Pencocokan sengaja konservatif: hanya mengisi bila kandidatnya tunggal.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('quotations', 'deal_id')) {
            return;
        }

        DB::table('quotations')
            ->whereNull('deal_id')
            ->whereNotNull('nama_perusahaan')
            ->orderBy('id')
            ->chunkById(200, function ($quotations) {
                foreach ($quotations as $quotation) {
                    $dealId = $this->cariDealTunggal(
                        $quotation->nama_perusahaan,
                        $quotation->nama_pic ?? null
                    );

                    if ($dealId !== null) {
                        DB::table('quotations')
                            ->where('id', $quotation->id)
                            ->update(['deal_id' => $dealId]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Tidak di-reverse: nilai sebelumnya NULL karena bug, bukan data yang sah.
    }

    private function cariDealTunggal(string $namaPerusahaan, ?string $namaPic): ?int
    {
        if (filled($namaPic) && $namaPic !== '-') {
            $cocokKeduanya = DB::table('deals')
                ->where('nama_perusahaan', $namaPerusahaan)
                ->where('nama_pic', $namaPic)
                ->pluck('id');

            if ($cocokKeduanya->count() === 1) {
                return (int) $cocokKeduanya->first();
            }
        }

        $cocokPerusahaan = DB::table('deals')
            ->where('nama_perusahaan', $namaPerusahaan)
            ->pluck('id');

        return $cocokPerusahaan->count() === 1
            ? (int) $cocokPerusahaan->first()
            : null;
    }
};
