<?php
// database/migrations/2024_01_01_000005_create_social_sync_logs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->onDelete('cascade');
            $table->string('sync_type'); // profile|insights|posts|all
            $table->string('status'); // started|completed|failed
            $table->text('error_message')->nullable();
            $table->json('response_summary')->nullable();
            $table->integer('records_processed')->default(0);
            $table->integer('execution_time_ms')->nullable();
            $table->timestamps();
            
            $table->index(['social_account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_sync_logs');
    }
};