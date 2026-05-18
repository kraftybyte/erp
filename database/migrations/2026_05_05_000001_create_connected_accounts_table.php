<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connected_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('provider'); // 'instagram', 'tiktok', 'twitter', 'facebook'
            $table->string('account_id'); // Platform's user ID
            $table->string('username');
            $table->string('profile_picture')->nullable();

            $table->text('access_token');
            $table->timestamp('token_expires_at')->nullable();

            $table->string('account_type')->default('personal'); // 'personal', 'business', 'creator'
            $table->string('name')->nullable(); // Display name / full name

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();

            $table->json('metadata')->nullable(); // Extra data from API

            $table->timestamps();

            $table->unique(['user_id', 'provider', 'account_id']);
            $table->index(['provider', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connected_accounts');
    }
};