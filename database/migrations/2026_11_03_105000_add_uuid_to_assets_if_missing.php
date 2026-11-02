<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom "ID Barang" (uuid) dipakai form dan pencarian Asset, tetapi tidak
 * pernah dibuat oleh migration: menyimpan dan mencari aset gagal dengan
 * "Unknown column 'uuid'". Dibuat hanya bila belum ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('assets', 'uuid')) {
            return;
        }

        Schema::table('assets', function (Blueprint $table) {
            $table->string('uuid')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        // Tidak dihapus: kolomnya mungkin sudah ada sebelum migration ini.
    }
};
