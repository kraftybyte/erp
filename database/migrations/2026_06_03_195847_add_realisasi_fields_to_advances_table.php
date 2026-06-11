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
            $table->unsignedBigInteger('overrun_approved_by')->nullable()->after('paid_by');
            $table->timestamp('overrun_approved_at')->nullable()->after('overrun_approved_by');
            $table->text('realisasi_notes')->nullable()->after('overrun_approved_at');
            $table->timestamp('realisasi_at')->nullable()->after('realisasi_notes');
            $table->unsignedBigInteger('realisasi_by')->nullable()->after('realisasi_at');

            // Add foreign keys
            $table->foreign('overrun_approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('realisasi_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('advances', function (Blueprint $table) {
            $table->dropForeign(['overrun_approved_by']);
            $table->dropForeign(['realisasi_by']);
            $table->dropColumn([
                'overrun_approved_by',
                'overrun_approved_at',
                'realisasi_notes',
                'realisasi_at',
                'realisasi_by',
            ]);
        });
    }
};