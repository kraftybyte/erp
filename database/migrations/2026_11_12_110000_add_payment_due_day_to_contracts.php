<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tanggal jatuh tempo pembayaran tiap bulan ({tanggal_jatuh_tempo}), satu
 * sumber untuk pasal pembayaran dan baris Tabel A (format PKS: "setiap
 * tanggal 25"). Kosong = 25.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contracts') && ! Schema::hasColumn('contracts', 'payment_due_day')) {
            Schema::table('contracts', fn (Blueprint $table) => $table->unsignedTinyInteger('payment_due_day')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('contracts') && Schema::hasColumn('contracts', 'payment_due_day')) {
            Schema::table('contracts', fn (Blueprint $table) => $table->dropColumn('payment_due_day'));
        }
    }
};
