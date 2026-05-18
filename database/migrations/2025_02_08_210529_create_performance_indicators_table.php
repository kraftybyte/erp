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
        Schema::create('performance_indicators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Creator yang menilai
            $table->integer('nilai')->comment('Nilai task berdasarkan kinerja');
            $table->string('deskripsi_pekerjaan')->comment('Detail pekerjaan yang dinilai');
            $table->timestamps();

            // Unik: satu task hanya bisa dinilai sekali
            $table->unique('task_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('performance_indicators');
    }
};
