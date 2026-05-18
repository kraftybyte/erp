<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_post_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_post_id')->constrained()->cascadeOnDelete();

            // Umum
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->unsignedBigInteger('shares')->default(0);
            $table->unsignedBigInteger('reach')->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->decimal('engagement_rate', 8, 4)->default(0);

            // Instagram
            $table->unsignedBigInteger('saved')->default(0);
            $table->unsignedBigInteger('video_views')->default(0);
            $table->unsignedBigInteger('profile_visits')->default(0);

            // Facebook
            $table->unsignedBigInteger('reactions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('negative_feedback')->default(0);

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_post_metrics');
    }
};