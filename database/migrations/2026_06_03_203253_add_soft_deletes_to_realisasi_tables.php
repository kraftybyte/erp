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
        Schema::table('realizasis', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('realisasi_items', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('realisasi_files', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('realizasis', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('realisasi_items', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('realisasi_files', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};