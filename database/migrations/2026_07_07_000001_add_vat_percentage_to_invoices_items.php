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
        Schema::table('invoices_items', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices_items', 'vat_percentage')) {
                $table->double('vat_percentage')->default(0)->after('vat');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices_items', function (Blueprint $table) {
            if (Schema::hasColumn('invoices_items', 'vat_percentage')) {
                $table->dropColumn('vat_percentage');
            }
        });
    }
};
