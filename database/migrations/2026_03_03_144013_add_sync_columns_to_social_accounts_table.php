<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            // Tambahkan kolom sync_status
            if (!Schema::hasColumn('social_accounts', 'sync_status')) {
                $table->string('sync_status')->default('pending')->after('is_active');
            }
            
            // Tambahkan kolom last_sync_started_at
            if (!Schema::hasColumn('social_accounts', 'last_sync_started_at')) {
                $table->timestamp('last_sync_started_at')->nullable()->after('last_synced_at');
            }
            
            // Tambahkan kolom last_sync_error
            if (!Schema::hasColumn('social_accounts', 'last_sync_error')) {
                $table->text('last_sync_error')->nullable()->after('last_sync_started_at');
            }
            
            // Tambahkan kolom last_sync_attempt
            if (!Schema::hasColumn('social_accounts', 'last_sync_attempt')) {
                $table->timestamp('last_sync_attempt')->nullable()->after('last_sync_error');
            }
            
            // Tambahkan kolom sync_metadata
            if (!Schema::hasColumn('social_accounts', 'sync_metadata')) {
                $table->json('sync_metadata')->nullable()->after('last_sync_attempt');
            }
            
            // Tambahkan soft deletes (deleted_at)
            if (!Schema::hasColumn('social_accounts', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'sync_status',
                'last_sync_started_at',
                'last_sync_error',
                'last_sync_attempt',
                'sync_metadata',
            ]);
            $table->dropSoftDeletes();
        });
    }
};