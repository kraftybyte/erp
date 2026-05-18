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
        Schema::create('purchase_requests', function (Blueprint $table) {
                $table->id();
                $table->string('item_name'); // Nama barang
                $table->integer('quantity'); // Jumlah barang
                $table->text('description')->nullable(); // Deskripsi barang
                $table->string('reference_link')->nullable(); // Link referensi
                $table->decimal('price', 10, 2)->nullable(); // Harga barang
                $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending'); // Status request
                $table->text('reason')->nullable(); // Alasan approve/reject
                $table->foreignId('requested_by')->constrained('users')->onDelete('cascade'); // User yang request
                $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_requests');
    }
};
