<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->integer('duration_months')->nullable()->after('expiration_date');
            $table->decimal('total_value', 15, 2)->nullable()->after('duration_months');
            $table->decimal('down_payment_percent', 5, 2)->default(50)->after('total_value');
            $table->integer('max_revisions')->default(3)->after('down_payment_percent');
            $table->string('platform')->nullable()->after('max_revisions');
            $table->integer('content_count')->nullable()->after('platform');
            $table->text('scope_of_work')->nullable()->after('content_count');
            $table->text('client_name_full')->nullable()->after('scope_of_work');
            $table->text('client_address')->nullable()->after('client_name_full');
            $table->string('client_phone')->nullable()->after('client_address');
            $table->string('client_email')->nullable()->after('client_phone');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $columns = [
                'duration_months', 'total_value', 'down_payment_percent',
                'max_revisions', 'platform', 'content_count',
                'scope_of_work', 'client_name_full', 'client_address',
                'client_phone', 'client_email'
            ];
            foreach ($columns as $col) {
                if (Schema::hasColumn('contracts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
