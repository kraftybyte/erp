<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Format kontrak yang bisa diatur (mis. format PKS):
 * - layout: baris nomor, label & jabatan di kolom tanda tangan, Lampiran 1
 *   ruang lingkup (judul, pembuka, judul kolom, penutup), catatan Tabel A;
 * - signing_city: kota tempat perjanjian dibuat ({kota});
 * - client_legal_basis: dasar kewenangan Pihak Kedua, mis. akta & SK ({dasar_hukum_klien}).
 * Kosong = tampilan lama, jadi kontrak yang ada tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contracts')) {
            return;
        }

        $add = [
            'layout'             => fn (Blueprint $t) => $t->json('layout')->nullable(),
            'signing_city'       => fn (Blueprint $t) => $t->string('signing_city')->nullable(),
            'client_legal_basis' => fn (Blueprint $t) => $t->text('client_legal_basis')->nullable(),
        ];

        foreach ($add as $column => $define) {
            if (! Schema::hasColumn('contracts', $column)) {
                Schema::table('contracts', fn (Blueprint $table) => $define($table));
            }
        }
    }

    public function down(): void
    {
        foreach (['layout', 'signing_city', 'client_legal_basis'] as $column) {
            if (Schema::hasTable('contracts') && Schema::hasColumn('contracts', $column)) {
                Schema::table('contracts', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
