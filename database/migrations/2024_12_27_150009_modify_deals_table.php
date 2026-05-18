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
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->string('nama_pic');
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade'); // Relasi ke tabel products
            $table->string('nama_perusahaan');
            $table->string('email');
            $table->string('telephone');
            $table->string('source');
            $table->string('alamat');
            $table->string('NPWP');
            $table->string('layanan');
            $table->string('notes');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
