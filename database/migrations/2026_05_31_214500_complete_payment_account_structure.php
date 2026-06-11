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
        // Step 1: Add missing columns to expenses table
        if (!Schema::hasColumn('expenses', 'payment_account_id')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->unsignedBigInteger('payment_account_id')->nullable()->after('project_id');
                // Note: Foreign key constraint removed - payment_accounts table may not exist yet
            });
        }

        if (!Schema::hasColumn('expenses', 'paid')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->decimal('paid', 15, 2)->nullable()->after('attachment_pay');
            });
        }

        // Step 2: Add missing columns to advances table
        if (!Schema::hasColumn('advances', 'payment_account_id')) {
            Schema::table('advances', function (Blueprint $table) {
                $table->unsignedBigInteger('payment_account_id')->nullable()->after('project_id');
                // Note: Foreign key constraint removed - payment_accounts table may not exist yet
            });
        }

        if (!Schema::hasColumn('advances', 'paid')) {
            Schema::table('advances', function (Blueprint $table) {
                $table->decimal('paid', 15, 2)->nullable()->after('attachment_pay');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Nothing to rollback - this migration adds optional columns
    }
};
