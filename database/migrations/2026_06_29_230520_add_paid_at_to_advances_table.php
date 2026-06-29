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
        Schema::table('advances', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('attachment_pay');
            $table->renameColumn('reference', 'reference_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('advances', function (Blueprint $table) {
            $table->dropColumn('paid_at');
            $table->renameColumn('reference_number', 'reference');
        });
    }
};
