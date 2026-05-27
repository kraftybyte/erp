<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add SoftDeletes to Contract
        Schema::table('contracts', function (Blueprint $table) {
            $table->softDeletes();
        });

        // Add SoftDeletes to Expense
        Schema::table('expenses', function (Blueprint $table) {
            $table->softDeletes();
        });

        // Add SoftDeletes to Advance
        Schema::table('advances', function (Blueprint $table) {
            $table->softDeletes();
        });

        // Add deal_id FK to invoices for proper relationship
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('deal_id')->nullable()->after('id');

            $table->foreign('deal_id')
                ->references('id')
                ->on('deals')
                ->onDelete('set null');
        });

        // Add deal_id FK to quotations for proper relationship
        Schema::table('quotations', function (Blueprint $table) {
            $table->unsignedBigInteger('deal_id')->nullable()->after('id');

            $table->foreign('deal_id')
                ->references('id')
                ->on('deals')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropForeign(['deal_id']);
            $table->dropSoftDeletes();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('advances', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['deal_id']);
            $table->dropColumn('deal_id');
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->dropForeign(['deal_id']);
            $table->dropColumn('deal_id');
        });
    }
};