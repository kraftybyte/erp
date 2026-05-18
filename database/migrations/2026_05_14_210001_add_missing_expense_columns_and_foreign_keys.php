<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            // Add missing columns that were referenced in ExpenseResource
            if (!Schema::hasColumn('expenses', 'project_id')) {
                $table->unsignedBigInteger('project_id')->nullable()->after('user_id');
            }

            if (!Schema::hasColumn('expenses', 'keterangan_expense')) {
                $table->text('keterangan_expense')->nullable()->after('category');
            }

            if (!Schema::hasColumn('expenses', 'status')) {
                $table->string('status')->default('Pending')->after('keterangan_expense');
            }

            if (!Schema::hasColumn('expenses', 'paid_by')) {
                $table->unsignedBigInteger('paid_by')->nullable()->after('attachment');
            }

            if (!Schema::hasColumn('expenses', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('paid_by');
            }

            if (!Schema::hasColumn('expenses', 'paid')) {
                $table->decimal('paid', 15, 2)->nullable()->after('rejection_reason');
            }

            if (!Schema::hasColumn('expenses', 'reference')) {
                $table->string('reference')->nullable()->after('paid');
            }

            if (!Schema::hasColumn('expenses', 'attachment_pay')) {
                $table->string('attachment_pay')->nullable()->after('reference');
            }
        });

        // Add foreign keys separately (using raw SQL for compatibility)
        try {
            DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_project_id_foreign FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL');
        } catch (\Exception $e) {
            // Foreign key might already exist
        }
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropColumn([
                'project_id',
                'keterangan_expense',
                'status',
                'paid_by',
                'rejection_reason',
                'paid',
                'reference',
                'attachment_pay',
            ]);
        });
    }
};