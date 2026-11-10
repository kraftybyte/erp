<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('invoices_items', 'original_price')) {
            return; // database lama yang kolomnya sudah ditambah di luar migrasi
        }

        Schema::table('invoices_items', function (Blueprint $table) {
            $column = $table->decimal('original_price', 15, 2)->nullable();
            Schema::hasColumn('invoices_items', 'price') && $column->after('price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices_items', function (Blueprint $table) {
            $table->dropColumn('original_price');
        });
    }
};
