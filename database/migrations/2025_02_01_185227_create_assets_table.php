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
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('nama_barang');
            $table->date('tanggal_pembelian');
            $table->text('deskripsi')->nullable();
            $table->enum('pembelian_dari', ['Marketplace', 'Langsung']);
            $table->enum('kondisi', ['Baru', 'Bekas']);
            $table->date('maintenance_date')->nullable();
            $table->foreignId('penanggung_jawab_id')->constrained('users')->onDelete('cascade');
            $table->string('bon_invoice')->nullable();
            $table->decimal('harga_barang', 15, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
