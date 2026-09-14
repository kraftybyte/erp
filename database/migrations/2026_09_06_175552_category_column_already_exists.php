<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     * No-op: category column already exists from previous partial migration
     */
    public function up(): void
    {
        // Category columns already exist in expenses and advances tables
        // This migration just records that the schema change was applied
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No rollback needed
    }
};
