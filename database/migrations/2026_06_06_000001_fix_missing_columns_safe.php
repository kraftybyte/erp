<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * SAFE MIGRATION - Only adds columns if they don't exist
     */
    public function up(): void
    {
        // =====================
        // USERS TABLE
        // =====================
        $this->addColumnIfNotExists('users', 'is_active', function($table) {
            $table->boolean('is_active')->default(true)->after('division');
        });

        // =====================
        // TASKS TABLE
        // =====================
        $this->addColumnIfNotExists('tasks', 'creator_id', function($table) {
            $table->unsignedBigInteger('creator_id')->nullable()->after('id');
        });
        $this->addColumnIfNotExists('tasks', 'deal_id', function($table) {
            $table->unsignedBigInteger('deal_id')->nullable()->after('project_id');
        });
        $this->addColumnIfNotExists('tasks', 'user_id', function($table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('creator_id');
        });
        $this->addColumnIfNotExists('tasks', 'proritas', function($table) {
            $table->string('proritas', 50)->default('Normal')->after('status');
        });

        // =====================
        // ABSENCES TABLE
        // =====================
        $this->addColumnIfNotExists('absences', 'start_time', function($table) {
            $table->dateTime('start_time')->nullable()->after('user_id');
        });
        $this->addColumnIfNotExists('absences', 'end_time', function($table) {
            $table->dateTime('end_time')->nullable()->after('start_time');
        });

        // =====================
        // LEADS TABLE
        // =====================
        $this->addColumnIfNotExists('leads', 'product_id', function($table) {
            $table->unsignedBigInteger('product_id')->nullable()->after('telephone');
        });
        $this->addColumnIfNotExists('leads', 'nama_perusahaan', function($table) {
            $table->string('nama_perusahaan', 100)->nullable()->after('nama_pic');
        });
        $this->addColumnIfNotExists('leads', 'notes', function($table) {
            $table->text('notes')->nullable()->after('status');
        });

        // =====================
        // DEALS TABLE
        // =====================
        $this->addColumnIfNotExists('deals', 'lead_id', function($table) {
            $table->unsignedBigInteger('lead_id')->nullable()->after('id');
        });
        $this->addColumnIfNotExists('deals', 'NPWP', function($table) {
            $table->string('NPWP', 50)->nullable()->after('telephone');
        });
        $this->addColumnIfNotExists('deals', 'alamat', function($table) {
            $table->text('alamat')->nullable()->after('NPWP');
        });
        $this->addColumnIfNotExists('deals', 'notes', function($table) {
            $table->text('notes')->nullable()->after('alamat');
        });
        $this->addColumnIfNotExists('deals', 'status', function($table) {
            $table->string('status', 50)->default('Negosiasi')->after('alamat');
        });
        // Make product_id nullable (old column was NOT NULL)
        if (Schema::hasColumn('deals', 'product_id')) {
            DB::statement('ALTER TABLE deals MODIFY COLUMN product_id BIGINT UNSIGNED NULL');
        }
        // Make other non-essential columns nullable
        foreach (['email', 'telephone', 'source', 'alamat', 'layanan', 'notes'] as $col) {
            if (Schema::hasColumn('deals', $col)) {
                DB::statement('ALTER TABLE deals MODIFY COLUMN ' . $col . ' VARCHAR(255) NULL');
            }
        }

        // =====================
        // PRODUCTS TABLE
        // =====================
        $this->addColumnIfNotExists('products', 'harga_layanan', function($table) {
            $table->decimal('harga_layanan', 15, 2)->nullable()->after('detail_layanan');
        });
        $this->addColumnIfNotExists('products', 'pajak', function($table) {
            $table->json('pajak')->nullable()->after('harga_layanan');
        });
        $this->addColumnIfNotExists('products', 'total_harga', function($table) {
            $table->decimal('total_harga', 15, 2)->nullable()->after('pajak');
        });

        // =====================
        // QUOTATIONS TABLE
        // =====================
        $this->addColumnIfNotExists('quotations', 'uuid', function($table) {
            $table->string('uuid', 50)->nullable()->after('id');
        });
        $this->addColumnIfNotExists('quotations', 'date', function($table) {
            $table->date('date')->nullable()->after('uuid');
        });
        $this->addColumnIfNotExists('quotations', 'due_date', function($table) {
            $table->date('due_date')->nullable()->after('date');
        });
        $this->addColumnIfNotExists('quotations', 'subtotal', function($table) {
            $table->decimal('subtotal', 15, 2)->default(0)->after('due_date');
        });
        $this->addColumnIfNotExists('quotations', 'total_discount', function($table) {
            $table->decimal('total_discount', 15, 2)->default(0)->after('subtotal');
        });
        $this->addColumnIfNotExists('quotations', 'total_vat', function($table) {
            $table->decimal('total_vat', 15, 2)->default(0)->after('total_discount');
        });
        $this->addColumnIfNotExists('quotations', 'grand_total', function($table) {
            $table->decimal('grand_total', 15, 2)->default(0)->after('total_vat');
        });
        $this->addColumnIfNotExists('quotations', 'nama_pic', function($table) {
            $table->string('nama_pic', 100)->nullable()->after('alamat');
        });
        $this->addColumnIfNotExists('quotations', 'email', function($table) {
            $table->string('email', 100)->nullable()->after('nama_pic');
        });

        // Make product_id and other columns nullable in deals table
        if (Schema::hasColumn('deals', 'product_id')) {
            DB::statement('ALTER TABLE deals MODIFY COLUMN product_id BIGINT UNSIGNED NULL');
        }
        foreach (['email', 'telephone', 'source', 'alamat', 'layanan', 'notes'] as $col) {
            if (Schema::hasColumn('deals', $col)) {
                DB::statement("ALTER TABLE deals MODIFY COLUMN {$col} VARCHAR(255) NULL");
            }
        }

        // =====================
        // QUOTATION_ITEMS TABLE
        // =====================
        $this->addColumnIfNotExists('quotation_items', 'product_name', function($table) {
            $table->string('product_name', 100)->nullable()->after('item');
        });
        $this->addColumnIfNotExists('quotation_items', 'description', function($table) {
            $table->text('description')->nullable()->after('product_name');
        });
        $this->addColumnIfNotExists('quotation_items', 'vat_percentage', function($table) {
            $table->double('vat_percentage')->default(0)->after('discount');
        });

        // =====================
        // INVOICES TABLE
        // =====================
        $this->addColumnIfNotExists('invoices', 'grand_total', function($table) {
            $table->decimal('grand_total', 15, 2)->nullable()->after('paid');
        });
        // Make non-essential columns nullable
        foreach (['for_type', 'for_id', 'from_type', 'from_id', 'name', 'phone', 'address'] as $col) {
            if (Schema::hasColumn('invoices', $col)) {
                DB::statement("ALTER TABLE invoices MODIFY COLUMN {$col} VARCHAR(255) NULL");
            }
        }

        // =====================
        // PAYMENT_ACCOUNTS TABLE (Create if not exists)
        // =====================
        if (!Schema::hasTable('payment_accounts')) {
            Schema::create('payment_accounts', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('type')->default('bank');
                $table->string('account_number')->nullable();
                $table->string('account_holder')->nullable();
                $table->string('bank_name')->nullable();
                $table->decimal('initial_balance', 15, 2)->default(0);
                $table->decimal('current_balance', 15, 2)->default(0);
                $table->decimal('amount', 15, 2)->default(0);
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        } else {
            // Add missing columns if table exists but lacks them
            if (Schema::hasTable('payment_accounts') && !Schema::hasColumn('payment_accounts', 'initial_balance')) {
                DB::statement('ALTER TABLE payment_accounts ADD COLUMN initial_balance DECIMAL(15,2) DEFAULT 0');
                DB::statement('ALTER TABLE payment_accounts ADD COLUMN current_balance DECIMAL(15,2) DEFAULT 0');
                DB::statement('ALTER TABLE payment_accounts ADD COLUMN account_holder VARCHAR(255) NULL');
                DB::statement('ALTER TABLE payment_accounts ADD COLUMN notes TEXT NULL');
            }
        }

        // =====================
        // TRANSACTIONS TABLE (Create if not exists)
        // =====================
        if (!Schema::hasTable('transactions')) {
            Schema::create('transactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('payment_account_id')->nullable();
                $table->string('type')->default('expense');
                $table->decimal('amount', 15, 2);
                $table->text('description')->nullable();
                $table->string('reference_type')->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->string('category')->default('other');
                $table->date('transaction_date');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('attachment')->nullable();
                $table->timestamps();
            });
        }

        // =====================
        // PROJECTS TABLE - Make columns nullable
        // =====================
        if (Schema::hasColumn('projects', 'deal_id')) DB::statement('ALTER TABLE projects MODIFY COLUMN deal_id BIGINT UNSIGNED NULL');
        if (Schema::hasColumn('projects', 'nama_project')) DB::statement('ALTER TABLE projects MODIFY COLUMN nama_project VARCHAR(255) NULL');
        if (Schema::hasColumn('projects', 'detail_project')) DB::statement('ALTER TABLE projects MODIFY COLUMN detail_project VARCHAR(255) NULL');
        if (Schema::hasColumn('projects', 'notes')) DB::statement('ALTER TABLE projects MODIFY COLUMN notes TEXT NULL');
        if (Schema::hasColumn('projects', 'timeline')) DB::statement('ALTER TABLE projects MODIFY COLUMN timeline DATETIME NULL');

        // =====================
        // TASKS TABLE - Make columns nullable
        // =====================
        if (Schema::hasColumn('tasks', 'nama_task')) DB::statement('ALTER TABLE tasks MODIFY COLUMN nama_task VARCHAR(255) NULL');
        if (Schema::hasColumn('tasks', 'project_id')) DB::statement('ALTER TABLE tasks MODIFY COLUMN project_id BIGINT UNSIGNED NULL');
        if (Schema::hasColumn('tasks', 'detail_task')) DB::statement('ALTER TABLE tasks MODIFY COLUMN detail_task TEXT NULL');
        if (Schema::hasColumn('tasks', 'mulai_task')) DB::statement('ALTER TABLE tasks MODIFY COLUMN mulai_task DATETIME NULL');
        if (Schema::hasColumn('tasks', 'selesai_task')) DB::statement('ALTER TABLE tasks MODIFY COLUMN selesai_task DATETIME NULL');
        if (Schema::hasColumn('tasks', 'status')) DB::statement('ALTER TABLE tasks MODIFY COLUMN status VARCHAR(255) NULL');
        if (Schema::hasColumn('tasks', 'proritas')) DB::statement('ALTER TABLE tasks MODIFY COLUMN proritas VARCHAR(255) NULL');

        // =====================
        // EXPENSES TABLE - Make columns nullable
        // =====================
        if (Schema::hasColumn('expenses', 'user_id')) DB::statement('ALTER TABLE expenses MODIFY COLUMN user_id BIGINT UNSIGNED NULL');
        if (Schema::hasColumn('expenses', 'submission_date')) DB::statement('ALTER TABLE expenses MODIFY COLUMN submission_date DATE NULL');
        if (Schema::hasColumn('expenses', 'category')) DB::statement('ALTER TABLE expenses MODIFY COLUMN category VARCHAR(255) NULL');
        if (Schema::hasColumn('expenses', 'status')) DB::statement('ALTER TABLE expenses MODIFY COLUMN status VARCHAR(255) NULL');
        if (Schema::hasColumn('expenses', 'amount')) DB::statement('ALTER TABLE expenses MODIFY COLUMN amount DECIMAL(15,2) NULL');

        // =====================
        // ADVANCES TABLE - Make columns nullable
        // =====================
        if (Schema::hasColumn('advances', 'user_id')) DB::statement('ALTER TABLE advances MODIFY COLUMN user_id BIGINT UNSIGNED NULL');
        if (Schema::hasColumn('advances', 'submission_date')) DB::statement('ALTER TABLE advances MODIFY COLUMN submission_date DATE NULL');
        if (Schema::hasColumn('advances', 'project_id')) DB::statement('ALTER TABLE advances MODIFY COLUMN project_id BIGINT UNSIGNED NULL');
        if (Schema::hasColumn('advances', 'status')) DB::statement('ALTER TABLE advances MODIFY COLUMN status VARCHAR(255) NULL');
        if (Schema::hasColumn('advances', 'amount')) DB::statement('ALTER TABLE advances MODIFY COLUMN amount DECIMAL(15,2) NULL');

        // =====================
        // CONTRACTS TABLE
        // =====================
        $this->addColumnIfNotExists('contracts', 'client_id', function($table) {
            $table->unsignedBigInteger('client_id')->nullable()->after('deal_id');
        });
        $this->addColumnIfNotExists('contracts', 'scope_of_work', function($table) {
            $table->text('scope_of_work')->nullable()->after('max_revisions');
        });
        $this->addColumnIfNotExists('contracts', 'platform', function($table) {
            $table->string('platform', 100)->nullable()->after('scope_of_work');
        });
        $this->addColumnIfNotExists('contracts', 'content_count', function($table) {
            $table->integer('content_count')->nullable()->after('platform');
        });
        $this->addColumnIfNotExists('contracts', 'file_path', function($table) {
            $table->string('file_path', 255)->nullable()->after('agency_signatory_title');
        });

        // =====================
        // PROJECTS TABLE
        // =====================
        $this->addColumnIfNotExists('projects', 'timeline_mulai', function($table) {
            $table->date('timeline_mulai')->nullable()->after('detail_project');
        });
        $this->addColumnIfNotExists('projects', 'timeline_selesai', function($table) {
            $table->date('timeline_selesai')->nullable()->after('timeline_mulai');
        });
        $this->addColumnIfNotExists('projects', 'nama_perusahaan', function($table) {
            $table->string('nama_perusahaan', 100)->nullable()->after('timeline_selesai');
        });
        $this->addColumnIfNotExists('projects', 'alamat', function($table) {
            $table->text('alamat')->nullable()->after('nama_perusahaan');
        });
        $this->addColumnIfNotExists('projects', 'nama_pic', function($table) {
            $table->string('nama_pic', 100)->nullable()->after('alamat');
        });

        // =====================
        // EXPENSES TABLE
        // =====================
        $this->addColumnIfNotExists('expenses', 'payment_account_id', function($table) {
            $table->unsignedBigInteger('payment_account_id')->nullable()->after('project_id');
        });
        $this->addColumnIfNotExists('expenses', 'paid', function($table) {
            $table->decimal('paid', 15, 2)->nullable()->after('attachment_pay');
        });

        // =====================
        // ADVANCES TABLE
        // =====================
        $this->addColumnIfNotExists('advances', 'payment_account_id', function($table) {
            $table->unsignedBigInteger('payment_account_id')->nullable()->after('project_id');
        });
        $this->addColumnIfNotExists('advances', 'paid', function($table) {
            $table->decimal('paid', 15, 2)->nullable()->after('attachment_pay');
        });
        // Fix paid column type if it's tinyint
        if (Schema::hasColumn('advances', 'paid')) {
            $columns = DB::select("DESCRIBE advances");
            foreach ($columns as $col) {
                if ($col->Field === 'paid' && str_contains($col->Type ?? '', 'tinyint')) {
                    DB::statement('ALTER TABLE advances MODIFY COLUMN paid DECIMAL(15,2) DEFAULT 0');
                    break;
                }
            }
        }
        // Rename keterangan_expense to keterangan_advance if exists
        $this->renameColumnIfExists('advances', 'keterangan_expense', 'keterangan_advance');

        // =====================
        // ITEM_EXPENSES TABLE
        // =====================
        $this->addColumnIfNotExists('item_expenses', 'subtotal', function($table) {
            $table->decimal('subtotal', 15, 2)->nullable()->after('price');
        });

        // =====================
        // ITEM_ADVANCE TABLE
        // =====================
        $this->addColumnIfNotExists('item_advance', 'subtotal', function($table) {
            $table->decimal('subtotal', 15, 2)->nullable()->after('price');
        });

        // =====================
        // EVENTS TABLE
        // =====================
        $this->addColumnIfNotExists('events', 'create_by', function($table) {
            $table->unsignedBigInteger('create_by')->nullable()->after('color');
        });

        // =====================
        // INVOICES TABLE
        // =====================
        $this->addColumnIfNotExists('invoices', 'grand_total', function($table) {
            $table->decimal('grand_total', 15, 2)->nullable()->after('paid');
        });
        $this->addColumnIfNotExists('invoices', 'type_payment', function($table) {
            $table->string('type_payment', 100)->nullable()->after('type');
        });
        $this->addColumnIfNotExists('invoices', 'po_number', function($table) {
            $table->string('po_number', 100)->nullable()->after('type_payment');
        });
        $this->addColumnIfNotExists('invoices', 'faktur', function($table) {
            $table->string('faktur', 100)->nullable()->after('po_number');
        });
        $this->addColumnIfNotExists('invoices', 'nama_perusahaan', function($table) {
            $table->string('nama_perusahaan', 255)->nullable()->after('name');
        });
        $this->addColumnIfNotExists('invoices', 'telephone', function($table) {
            $table->string('telephone', 50)->nullable()->after('nama_perusahaan');
        });
        $this->addColumnIfNotExists('invoices', 'alamat', function($table) {
            $table->text('alamat')->nullable()->after('telephone');
        });
        $this->addColumnIfNotExists('invoices', 'subtotal', function($table) {
            $table->decimal('subtotal', 15, 2)->default(0)->after('alamat');
        });
        $this->addColumnIfNotExists('invoices', 'total_discount', function($table) {
            $table->decimal('total_discount', 15, 2)->default(0)->after('subtotal');
        });
        $this->addColumnIfNotExists('invoices', 'total_vat', function($table) {
            $table->decimal('total_vat', 15, 2)->default(0)->after('total_discount');
        });

        // =====================
        // META_AD_ACCOUNTS TABLE
        // =====================
        $this->addColumnIfNotExists('meta_ad_accounts', 'client_id', function($table) {
            $table->unsignedBigInteger('client_id')->nullable()->after('id');
        });
        $this->addColumnIfNotExists('meta_ad_accounts', 'ad_account_id', function($table) {
            $table->string('ad_account_id', 100)->after('client_id');
        });
        $this->addColumnIfNotExists('meta_ad_accounts', 'account_name', function($table) {
            $table->string('account_name', 100)->nullable()->after('ad_account_id');
        });
        $this->addColumnIfNotExists('meta_ad_accounts', 'access_token', function($table) {
            $table->text('access_token')->after('account_name');
        });
        $this->addColumnIfNotExists('meta_ad_accounts', 'token_expires_at', function($table) {
            $table->timestamp('token_expires_at')->nullable()->after('access_token');
        });
        $this->addColumnIfNotExists('meta_ad_accounts', 'is_active', function($table) {
            $table->boolean('is_active')->default(true)->after('token_expires_at');
        });
        $this->addColumnIfNotExists('meta_ad_accounts', 'last_synced_at', function($table) {
            $table->timestamp('last_synced_at')->nullable()->after('is_active');
        });

        // =====================
        // PAYMENT_ACCOUNTS TABLE (Create if not exists)
        // =====================
        if (!Schema::hasTable('payment_accounts')) {
            Schema::create('payment_accounts', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('type')->default('bank');
                $table->string('account_number')->nullable();
                $table->string('bank_name')->nullable();
                $table->decimal('amount', 15, 2)->default(0);
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // =====================
        // TRANSACTIONS TABLE (Create if not exists)
        // =====================
        if (!Schema::hasTable('transactions')) {
            Schema::create('transactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('payment_account_id')->nullable();
                $table->string('type')->default('expense');
                $table->decimal('amount', 15, 2);
                $table->text('description')->nullable();
                $table->string('reference_type')->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->string('category')->default('other');
                $table->date('transaction_date');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('attachment')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // DO NOT DROP ANYTHING in down()
        // This is a safe migration - we only add, never remove
    }

    /**
     * Helper: Add column if not exists
     */
    protected function addColumnIfNotExists(string $table, string $column, callable $callback): void
    {
        if (!Schema::hasColumn($table, $column)) {
            Schema::table($table, $callback);
        }
    }

    /**
     * Helper: Rename column if exists
     */
    protected function renameColumnIfExists(string $table, string $from, string $to): void
    {
        if (Schema::hasColumn($table, $from) && !Schema::hasColumn($table, $to)) {
            DB::statement("ALTER TABLE {$table} CHANGE COLUMN {$from} {$to} TEXT");
        }
    }
};
