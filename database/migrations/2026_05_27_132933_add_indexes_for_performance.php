<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ========== LEADS ==========
        $this->createIndexIfNotExists('leads', 'idx_leads_status', 'status');
        $this->createIndexIfNotExists('leads', 'idx_leads_source', 'source');
        $this->createIndexIfNotExists('leads', 'idx_leads_is_converted', 'is_converted');
        $this->createIndexIfNotExists('leads', 'idx_leads_status_converted', 'status, is_converted');

        // ========== DEALS ==========
        $this->createIndexIfNotExists('deals', 'idx_deals_status', 'status');
        $this->createIndexIfNotExists('deals', 'idx_deals_status_lead', 'status, lead_id');

        // ========== EXPENSES ==========
        $this->createIndexIfNotExists('expenses', 'idx_expenses_status', 'status');
        $this->createIndexIfNotExists('expenses', 'idx_expenses_status_user', 'status, user_id');
        $this->createIndexIfNotExists('expenses', 'idx_expenses_submission_date', 'submission_date');

        // ========== INVOICES ==========
        $this->createIndexIfNotExists('invoices', 'idx_invoices_status', 'status');
        $this->createIndexIfNotExists('invoices', 'idx_invoices_due_date', 'due_date');
        $this->createIndexIfNotExists('invoices', 'idx_invoices_status_due', 'status, due_date');

        // ========== TASKS ==========
        $this->createIndexIfNotExists('tasks', 'idx_tasks_status', 'status');
        $this->createIndexIfNotExists('tasks', 'idx_tasks_status_creator', 'status, creator_id');

        // ========== PROJECTS ==========
        $this->createIndexIfNotExists('projects', 'idx_projects_deal', 'deal_id');

        // ========== ABSENCES ==========
        $this->createIndexIfNotExists('absences', 'idx_absences_status', 'approval_status');
        $this->createIndexIfNotExists('absences', 'idx_absences_start_time', 'start_time');
        $this->createIndexIfNotExists('absences', 'idx_absences_end_time', 'end_time');

        // ========== ADVANCES ==========
        $this->createIndexIfNotExists('advances', 'idx_advances_status', 'status');
        $this->createIndexIfNotExists('advances', 'idx_advances_user', 'user_id');

        // ========== QUOTATIONS ==========
        $this->createIndexIfNotExists('quotations', 'idx_quotations_status', 'status');

        // ========== CONTRACTS ==========
        $this->createIndexIfNotExists('contracts', 'idx_contracts_expiration', 'expiration_date');
    }

    public function down(): void
    {
        // Leads
        $this->dropIndexIfNotExists('leads', 'idx_leads_status');
        $this->dropIndexIfNotExists('leads', 'idx_leads_source');
        $this->dropIndexIfNotExists('leads', 'idx_leads_is_converted');
        $this->dropIndexIfNotExists('leads', 'idx_leads_status_converted');

        // Deals
        $this->dropIndexIfNotExists('deals', 'idx_deals_status');
        $this->dropIndexIfNotExists('deals', 'idx_deals_status_lead');

        // Expenses
        $this->dropIndexIfNotExists('expenses', 'idx_expenses_status');
        $this->dropIndexIfNotExists('expenses', 'idx_expenses_status_user');
        $this->dropIndexIfNotExists('expenses', 'idx_expenses_submission_date');

        // Invoices
        $this->dropIndexIfNotExists('invoices', 'idx_invoices_status');
        $this->dropIndexIfNotExists('invoices', 'idx_invoices_due_date');
        $this->dropIndexIfNotExists('invoices', 'idx_invoices_status_due');

        // Tasks
        $this->dropIndexIfNotExists('tasks', 'idx_tasks_status');
        $this->dropIndexIfNotExists('tasks', 'idx_tasks_status_creator');

        // Projects
        $this->dropIndexIfNotExists('projects', 'idx_projects_deal');

        // Absences
        $this->dropIndexIfNotExists('absences', 'idx_absences_status');
        $this->dropIndexIfNotExists('absences', 'idx_absences_start_time');
        $this->dropIndexIfNotExists('absences', 'idx_absences_end_time');

        // Advances
        $this->dropIndexIfNotExists('advances', 'idx_advances_status');
        $this->dropIndexIfNotExists('advances', 'idx_advances_user');

        // Quotations
        $this->dropIndexIfNotExists('quotations', 'idx_quotations_status');

        // Contracts
        $this->dropIndexIfNotExists('contracts', 'idx_contracts_expiration');
    }

    private function createIndexIfNotExists(string $table, string $index, string $columns): void
    {
        if ($this->tableExists($table) && !$this->indexExists($table, $index)) {
            $t = addslashes($table);
            $i = addslashes($index);
            $cols = implode('`, `', explode(', ', $columns));
            DB::statement("ALTER TABLE `{$t}` ADD INDEX `{$i}` (`{$cols}`)");
        }
    }

    private function dropIndexIfNotExists(string $table, string $index): void
    {
        if ($this->tableExists($table) && $this->indexExists($table, $index)) {
            $t = addslashes($table);
            $i = addslashes($index);
            DB::statement("ALTER TABLE `{$t}` DROP INDEX `{$i}`");
        }
    }

    private function tableExists(string $table): bool
    {
        $escape = addslashes($table);
        $result = DB::select("SHOW TABLES LIKE '{$escape}'");
        return count($result) > 0;
    }

    private function indexExists(string $table, string $index): bool
    {
        $t = addslashes($table);
        $i = addslashes($index);
        $result = DB::select("SHOW INDEX FROM `{$t}` WHERE Key_name = '{$i}'");
        return count($result) > 0;
    }
};
