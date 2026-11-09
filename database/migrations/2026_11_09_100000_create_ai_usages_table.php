<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pemakaian Gemini per hari (panggilan, gagal, token), untuk laporan
 * mingguan bot dan perkiraan biayanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_usages')) {
            return;
        }

        Schema::create('ai_usages', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedInteger('calls')->default(0);
            $table->unsignedInteger('failures')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usages');
    }
};
