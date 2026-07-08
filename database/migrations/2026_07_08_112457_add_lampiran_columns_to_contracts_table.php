<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('lampiran_title')->nullable()->after('installment_months');
            $table->text('lampiran_description')->nullable()->after('lampiran_title');
            $table->string('table_a_title')->nullable()->after('lampiran_description');
            $table->string('table_b_title')->nullable()->after('table_a_title');
            $table->text('table_b_description')->nullable()->after('table_b_title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn([
                'lampiran_title',
                'lampiran_description',
                'table_a_title',
                'table_b_title',
                'table_b_description',
            ]);
        });
    }
};
