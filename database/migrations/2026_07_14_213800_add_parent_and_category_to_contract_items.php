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
        // Database lama bisa sudah punya kolomnya (diubah di luar migrasi).
        $addParent = ! Schema::hasColumn('contract_items', 'parent_id');
        $addHeader = ! Schema::hasColumn('contract_items', 'is_category_header');

        Schema::table('contract_items', function (Blueprint $table) use ($addParent, $addHeader) {
            if ($addParent) {
                $table->unsignedBigInteger('parent_id')->nullable()->after('contract_id');
            }

            if ($addHeader) {
                $table->boolean('is_category_header')->default(false)->after('parent_id');
            }

            if ($addParent) {
                $table->foreign('parent_id')
                      ->references('id')
                      ->on('contract_items')
                      ->onDelete('cascade');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contract_items', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn(['parent_id', 'is_category_header']);
        });
    }
};
