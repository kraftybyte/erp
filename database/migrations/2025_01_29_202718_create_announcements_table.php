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
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->text('content'); // Isi pengumuman, bisa berisi teks atau link
            $table->dateTime('start_date'); // Tanggal mulai pengumuman
            $table->dateTime('end_date'); // Tanggal berakhir pengumuman
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Pembuat pengumuman
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
