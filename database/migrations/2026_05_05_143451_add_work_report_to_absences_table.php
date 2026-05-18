<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('absences', function (Blueprint $table) {
            if (!Schema::hasColumn('absences', 'work_report')) {
                $table->text('work_report')->nullable()->after('rejection_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('absences', function (Blueprint $table) {
            $table->dropColumn('work_report');
        });
    }
};
