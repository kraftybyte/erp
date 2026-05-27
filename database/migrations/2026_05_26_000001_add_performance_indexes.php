<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // invoices table indexes
        $this->createIndexIfNotExists('invoices', 'idx_invoices_status_created', '(status, created_at)');
        $this->createIndexIfNotExists('invoices', 'idx_invoices_due_date_status', '(due_date, status)');
        $this->createIndexIfNotExists('invoices', 'idx_invoices_user_status', '(user_id, status)');
        $this->createIndexIfNotExists('invoices', 'idx_invoices_uuid', '(uuid(50))');

        // expenses table indexes
        $this->createIndexIfNotExists('expenses', 'idx_expenses_status_user', '(status, user_id)');
        $this->createIndexIfNotExists('expenses', 'idx_expenses_project', '(project_id)');
        $this->createIndexIfNotExists('expenses', 'idx_expenses_submission_date', '(submission_date)');

        // advances table indexes
        $this->createIndexIfNotExists('advances', 'idx_advances_status_user', '(status, user_id)');
        $this->createIndexIfNotExists('advances', 'idx_advances_project', '(project_id)');

        // absences table indexes
        $this->createIndexIfNotExists('absences', 'idx_absences_user_status', '(user_id, approval_status)');
        $this->createIndexIfNotExists('absences', 'idx_absences_dates', '(start_time, end_time)');

        // tasks table indexes (high traffic)
        $this->createIndexIfNotExists('tasks', 'idx_tasks_status', '(status)');
        $this->createIndexIfNotExists('tasks', 'idx_tasks_dates', '(mulai_task, selesai_task)');
        $this->createIndexIfNotExists('tasks', 'idx_tasks_project_status', '(project_id, status)');

        // deals table indexes
        $this->createIndexIfNotExists('deals', 'idx_deals_lead_status', '(lead_id, status)');

        // leads table indexes (no user_id - that's on deals)
        $this->createIndexIfNotExists('leads', 'idx_leads_status', '(status)');
        $this->createIndexIfNotExists('leads', 'idx_leads_created', '(created_at)');

        // quotations table indexes
        $this->createIndexIfNotExists('quotations', 'idx_quotations_uuid', '(uuid(50))');
        $this->createIndexIfNotExists('quotations', 'idx_quotations_status', '(status)');

        // social_accounts table indexes
        $this->createIndexIfNotExists('social_accounts', 'idx_social_accounts_client', '(client_id)');
        $this->createIndexIfNotExists('social_accounts', 'idx_social_accounts_is_active', '(is_active)');

        // contracts table indexes
        $this->createIndexIfNotExists('contracts', 'idx_contracts_deal', '(deal_id)');
        $this->createIndexIfNotExists('contracts', 'idx_contracts_dates', '(effective_date, expiration_date)');

        // project_timelines table indexes
        $this->createIndexIfNotExists('project_timelines', 'idx_project_timelines_project', '(project_id)');
        $this->createIndexIfNotExists('project_timelines', 'idx_project_timelines_dates', '(start_date, end_date)');
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropIndex(['due_date', 'status']);
            $table->dropIndex(['user_id', 'status']);
            $table->dropIndex(['uuid']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['status', 'user_id']);
            $table->dropIndex(['project_id']);
            $table->dropIndex(['submission_date']);
        });

        Schema::table('advances', function (Blueprint $table) {
            $table->dropIndex(['status', 'user_id']);
            $table->dropIndex(['project_id']);
        });

        Schema::table('absences', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'approval_status']);
            $table->dropIndex(['start_time', 'end_time']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['mulai_task', 'selesai_task']);
            $table->dropIndex(['project_id', 'status']);
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->dropIndex(['lead_id', 'status']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['created_at']);
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->dropIndex(['uuid']);
            $table->dropIndex(['status']);
        });

        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropIndex(['client_id']);
            $table->dropIndex(['is_active']);
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex(['deal_id']);
            $table->dropIndex(['effective_date', 'expiration_date']);
        });

        Schema::table('project_timelines', function (Blueprint $table) {
            $table->dropIndex(['project_id']);
            $table->dropIndex(['start_date', 'end_date']);
        });
    }

    private function createIndexIfNotExists(string $table, string $index, string $columns): void
    {
        $exists = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$index]);

        if (empty($exists)) {
            DB::statement("CREATE INDEX {$index} ON {$table} {$columns}");
        }
    }
};