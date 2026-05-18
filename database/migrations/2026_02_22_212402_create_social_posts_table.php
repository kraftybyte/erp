<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('platform_post_id')->unique();
            $table->enum('media_type', ['IMAGE', 'VIDEO', 'CAROUSEL_ALBUM', 'REELS', 'STORY', 'LINK', 'STATUS'])->nullable();
            $table->text('caption')->nullable();
            $table->string('permalink')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->string('media_url')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
    }
};