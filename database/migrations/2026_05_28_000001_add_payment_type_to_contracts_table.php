<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->enum('payment_type', ['full', 'dp_monthly', 'monthly'])
                ->default('full')
                ->after('down_payment_percent');

            $table->unsignedTinyInteger('installment_months')
                ->nullable()
                ->after('payment_type');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['payment_type', 'installment_months']);
        });
    }
};
