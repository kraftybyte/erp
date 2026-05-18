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
        Schema::table('financial_ai_reports', function (Blueprint $table) {
$table->json('metrics')->nullable();
$table->date('period_start')->nullable();
$table->date('period_end')->nullable();
$table->string('version')->default('v1');
});
    }
    
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
