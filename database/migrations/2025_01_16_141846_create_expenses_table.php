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
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Nama Karyawan
            $table->date('submission_date'); // Tanggal Pengajuan
            $table->string('category'); // Kategori Pengeluaran
            $table->decimal('amount', 15, 2); // Nominal
            $table->string('attachment')->nullable(); // Lampiran Bukti
            $table->timestamps();
        });
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
