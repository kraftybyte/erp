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
        Schema::create('realisasi_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('realisasi_item_id');
            $table->string('original_name');
            $table->string('filepath');
            $table->unsignedBigInteger('filesize')->default(0);
            $table->string('mime_type')->nullable();
            $table->timestamps();

            $table->index('realisasi_item_id');
            $table->foreign('realisasi_item_id')->references('id')->on('realisasi_items')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('realisasi_files');
    }
};