<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            if (!Schema::hasColumn('deals', 'lead_id')) {
                $table->foreignId('lead_id')->nullable()->after('product_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn('lead_id');
        });
    }
};
