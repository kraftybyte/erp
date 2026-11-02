<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data untuk perhitungan penyusutan aset (AssetDepreciation): kelompok harta
 * menurut pajak, metode penyusutan, dan tanggal aset dijual/dibuang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            if (! Schema::hasColumn('assets', 'kelompok_harta')) {
                $table->string('kelompok_harta', 30)->nullable()->after('harga_barang');
            }

            if (! Schema::hasColumn('assets', 'metode_penyusutan')) {
                $table->string('metode_penyusutan', 20)->default('garis_lurus')->after('kelompok_harta');
            }

            if (! Schema::hasColumn('assets', 'tanggal_dilepas')) {
                $table->date('tanggal_dilepas')->nullable()->after('metode_penyusutan');
            }
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            foreach (['tanggal_dilepas', 'metode_penyusutan', 'kelompok_harta'] as $column) {
                if (Schema::hasColumn('assets', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
