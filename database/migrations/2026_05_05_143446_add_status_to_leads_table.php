<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (!Schema::hasColumn('leads', 'status')) {
                $table->string('status')->default('Dalam Komunikasi')->after('source');
            }
            if (!Schema::hasColumn('leads', 'is_converted')) {
                $table->boolean('is_converted')->default(false)->after('status');
            }
            if (!Schema::hasColumn('leads', 'product_id')) {
                $table->foreignId('product_id')->nullable()->after('is_converted');
            }
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['status', 'is_converted', 'product_id']);
        });
    }
};
