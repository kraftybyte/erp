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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('nama_layanan', 255)->index(); // Menambahkan indeks untuk pencarian lebih cepat
            $table->text('detail_layanan'); // Tetap gunakan tipe `text` jika data biasanya panjang
            $table->decimal('harga_layanan', 15, 2); // Skala dan presisi yang sesuai untuk angka desimal
            $table->json('pajak')->nullable(); // Fleksibilitas untuk menyimpan multiple pajak
            $table->decimal('total_harga', 15, 0)->nullable(); // Menggunakan angka bulat tanpa desimal
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
