<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advances', function (Blueprint $table) {
            if (Schema::hasColumn('advances', 'refrence') && !Schema::hasColumn('advances', 'reference')) {
                $table->renameColumn('refrence', 'reference');
            }
        });
    }

    public function down(): void
    {
        Schema::table('advances', function (Blueprint $table) {
            if (Schema::hasColumn('advances', 'reference') && !Schema::hasColumn('advances', 'refrence')) {
                $table->renameColumn('reference', 'refrence');
            }
        });
    }
};
