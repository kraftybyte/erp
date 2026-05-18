<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Rename typo column: telphone -> telephone
            if (Schema::hasColumn('leads', 'telphone') && !Schema::hasColumn('leads', 'telephone')) {
                $table->renameColumn('telphone', 'telephone');
            }
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'telephone') && !Schema::hasColumn('leads', 'telphone')) {
                $table->renameColumn('telephone', 'telphone');
            }
        });
    }
};