<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nomor proforma (#PI/...) dari invoice yang dulunya proforma. Saat dikonversi
 * menjadi invoice, uuid diganti nomor #SC, dan tanpa kolom ini nomor PI-nya
 * bisa diberikan lagi ke proforma lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('invoices', 'proforma_uuid')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('proforma_uuid', 50)->nullable()->after('uuid')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('invoices', 'proforma_uuid')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['proforma_uuid']);
            $table->dropColumn('proforma_uuid');
        });
    }
};
