<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * File faktur pajak keluaran (PDF dari Coretax) yang di-upload di halaman
 * Faktur dan dipasangkan ke invoice penjualannya. Nomornya tetap di kolom faktur.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('invoices', 'faktur_file')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('faktur_file')->nullable()->after('faktur');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('invoices', 'faktur_file')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('faktur_file');
        });
    }
};
