<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('deals')->cascadeOnDelete();
            $table->enum('platform', ['instagram', 'facebook']);
            $table->string('platform_account_id');
            $table->string('username')->nullable();
            $table->string('page_name')->nullable();
            $table->string('profile_picture_url')->nullable();
            $table->unsignedBigInteger('followers_count')->default(0);
            $table->unsignedBigInteger('following_count')->default(0);
            $table->unsignedBigInteger('media_count')->default(0);
            $table->unsignedBigInteger('fan_count')->default(0);
            $table->text('access_token');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'platform_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};