<?php
// database/migrations/xxxx_fix_failed_jobs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('failed_jobs', function (Blueprint $table) {
            // Tambahkan kolom yang hilang
            if (!Schema::hasColumn('failed_jobs', 'uuid')) {
                $table->uuid('uuid')->unique()->after('id')->nullable();
            }
            if (!Schema::hasColumn('failed_jobs', 'failed_at')) {
                $table->timestamp('failed_at')->useCurrent()->after('exception');
            }
            // Jika Laravel mencari created_at, tambahkan juga
            if (!Schema::hasColumn('failed_jobs', 'created_at')) {
                $table->timestamp('created_at')->nullable()->after('failed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('failed_jobs', function (Blueprint $table) {
            $table->dropColumn(['uuid', 'failed_at', 'created_at']);
        });
    }
};