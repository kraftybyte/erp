<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Rename typo column
        if (Schema::hasColumn('advances', 'refrence') && !Schema::hasColumn('advances', 'reference')) {
            Schema::table('advances', function (Blueprint $table) {
                $table->renameColumn('refrence', 'reference');
            });
        }

        // Add foreign keys using raw SQL
        try {
            DB::statement('ALTER TABLE advances ADD CONSTRAINT advances_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
        } catch (\Exception $e) {
            // Foreign key might already exist
        }

        try {
            DB::statement('ALTER TABLE advances ADD CONSTRAINT advances_project_id_foreign FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL');
        } catch (\Exception $e) {
            // Foreign key might already exist
        }

        try {
            DB::statement('ALTER TABLE advances ADD CONSTRAINT advances_paid_by_foreign FOREIGN KEY (paid_by) REFERENCES users(id) ON DELETE SET NULL');
        } catch (\Exception $e) {
            // Foreign key might already exist
        }
    }

    public function down(): void
    {
        Schema::table('advances', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['project_id']);
            $table->dropForeign(['paid_by']);

            if (Schema::hasColumn('advances', 'reference')) {
                $table->renameColumn('reference', 'refrence');
            }
        });
    }
};