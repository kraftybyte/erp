<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_campaign_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_ad_account_id')->constrained()->cascadeOnDelete();
            $table->string('campaign_id');
            $table->string('campaign_name');
            $table->string('status')->nullable();
            $table->string('objective')->nullable();
            $table->date('date');
            $table->decimal('spend', 15, 2)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('reach')->default(0);
            $table->unsignedInteger('conversions')->default(0);
            $table->decimal('ctr', 8, 4)->default(0);
            $table->decimal('cpc', 10, 4)->default(0);
            $table->decimal('cpm', 10, 4)->default(0);
            $table->decimal('roas', 8, 4)->default(0);
            $table->timestamps();

            $table->unique(['meta_ad_account_id', 'campaign_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_campaign_metrics');
    }
};