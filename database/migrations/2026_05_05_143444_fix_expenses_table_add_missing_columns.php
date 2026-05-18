<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            if (!Schema::hasColumn('expenses', 'project_id')) {
                $table->foreignId('project_id')->nullable()->after('user_id');
            }
            if (!Schema::hasColumn('expenses', 'keterangan_expense')) {
                $table->text('keterangan_expense')->nullable()->after('category');
            }
            if (!Schema::hasColumn('expenses', 'status')) {
                $table->string('status')->default('Pending')->after('keterangan_expense');
            }
            if (!Schema::hasColumn('expenses', 'paid')) {
                $table->decimal('paid', 15, 2)->nullable()->after('status');
            }
            if (!Schema::hasColumn('expenses', 'paid_by')) {
                $table->foreignId('paid_by')->nullable()->after('paid');
            }
            if (!Schema::hasColumn('expenses', 'reference')) {
                $table->string('reference')->nullable()->after('paid_by');
            }
            if (!Schema::hasColumn('expenses', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('attachment');
            }
            if (!Schema::hasColumn('expenses', 'attachment_pay')) {
                $table->string('attachment_pay')->nullable()->after('rejection_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn([
                'project_id',
                'keterangan_expense',
                'status',
                'paid',
                'paid_by',
                'reference',
                'rejection_reason',
                'attachment_pay',
            ]);
        });
    }
};
