<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advance yang memakai bayar paralel (petty cash): realisasi boleh sebelum
 * dibayar, dan pembayarannya boleh bertahap sampai sama dengan realisasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('advances', 'pay_parallel')) {
            Schema::table('advances', function (Blueprint $table) {
                $table->boolean('pay_parallel')->default(false)->after('paid_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('advances', 'pay_parallel')) {
            Schema::table('advances', function (Blueprint $table) {
                $table->dropColumn('pay_parallel');
            });
        }
    }
};
