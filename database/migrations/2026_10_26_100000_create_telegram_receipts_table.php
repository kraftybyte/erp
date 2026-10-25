<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Struk yang sudah masuk ERP lewat bot Telegram. file_unique_id sama
        // untuk file yang persis sama walau dikirim ulang/di-forward, jadi
        // struk yang sama tidak bisa diajukan dua kali.
        Schema::create('telegram_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('file_unique_id', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reference');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_receipts');
    }
};
