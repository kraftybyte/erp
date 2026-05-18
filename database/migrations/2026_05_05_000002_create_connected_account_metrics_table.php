<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connected_account_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connected_account_id')->constrained('connected_accounts')->cascadeOnDelete();
            $table->date('date');

            // Profile metrics
            $table->bigInteger('followers')->default(0);
            $table->bigInteger('following')->default(0)->nullable();
            $table->bigInteger('media_count')->default(0)->nullable(); // Total posts

            // Reach & impressions
            $table->bigInteger('reach')->default(0)->nullable();
            $table->bigInteger('impressions')->default(0)->nullable();

            // Engagement
            $table->bigInteger('likes')->default(0)->nullable();
            $table->bigInteger('comments')->default(0)->nullable();
            $table->bigInteger('saves')->default(0)->nullable();
            $table->bigInteger('shares')->default(0)->nullable();
            $table->bigInteger('engagement')->default(0)->nullable(); // Total engagement

            // Profile activity
            $table->bigInteger('profile_visits')->default(0)->nullable();
            $table->bigInteger('website_clicks')->default(0)->nullable();

            // Calculated
            $table->decimal('engagement_rate', 8, 4)->nullable();

            $table->timestamps();

            $table->unique(['connected_account_id', 'date'], 'unique_account_date');
            $table->index(['connected_account_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connected_account_metrics');
    }
};