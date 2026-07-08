<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // Contract Number
            $table->string('contract_number')->nullable()->after('deal_id');

            // Extended Fields
            $table->text('project_description')->nullable()->after('scope_of_work');
            $table->text('additional_terms')->nullable()->after('project_description');
            $table->boolean('force_majeure_extended')->default(false)->after('additional_terms');
            $table->integer('termination_notice_days')->default(30)->after('force_majeure_extended');
            $table->text('penalty_clause')->nullable()->after('termination_notice_days');

            // Make file_path nullable as we can have generated contracts without files
            $table->string('file_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn([
                'contract_number',
                'project_description',
                'additional_terms',
                'force_majeure_extended',
                'termination_notice_days',
                'penalty_clause',
            ]);
        });
    }
};
