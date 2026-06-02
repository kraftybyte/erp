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
        // Make payment_account_id nullable in transactions
        if (Schema::hasColumn('transactions', 'payment_account_id')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->unsignedBigInteger('payment_account_id')->nullable()->change();
            });
        }

        // Add payment_account_id to invoices
        if (!Schema::hasColumn('invoices', 'payment_account_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->unsignedBigInteger('payment_account_id')->nullable()->after('due_date');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('payment_account_id');
        });
    }
};
