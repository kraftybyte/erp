<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data untuk mengenali struk yang sama tapi difoto ulang (file_unique_id-nya
 * beda): nominal, tanggal, dan toko hasil baca Gemini, serta sidik jari
 * gambar (dHash 64-bit).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('telegram_receipts', 'phash')) {
            return;
        }

        Schema::table('telegram_receipts', function (Blueprint $table) {
            $table->string('phash', 16)->nullable()->after('reference');
            $table->decimal('amount', 15, 2)->nullable()->after('phash');
            $table->date('receipt_date')->nullable()->after('amount');
            $table->string('shop', 100)->nullable()->after('receipt_date');
            $table->index(['amount', 'receipt_date']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('telegram_receipts', 'phash')) {
            return;
        }

        Schema::table('telegram_receipts', function (Blueprint $table) {
            $table->dropIndex(['amount', 'receipt_date']);
            $table->dropColumn(['phash', 'amount', 'receipt_date', 'shop']);
        });
    }
};
