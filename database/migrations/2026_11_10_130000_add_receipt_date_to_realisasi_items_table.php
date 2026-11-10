<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tanggal pembelian di struk/invoice/bukti bayar setiap item realisasi, dibaca
 * otomatis oleh AI: realisasi bisa dikirim jauh setelah uangnya dipakai, jadi
 * tanggal kirim tidak menunjukkan kapan pembelian itu dibayar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('realisasi_items', 'receipt_date')) {
            Schema::table('realisasi_items', function (Blueprint $table) {
                $table->date('receipt_date')->nullable()->after('subtotal');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('realisasi_items', 'receipt_date')) {
            Schema::table('realisasi_items', function (Blueprint $table) {
                $table->dropColumn('receipt_date');
            });
        }
    }
};
