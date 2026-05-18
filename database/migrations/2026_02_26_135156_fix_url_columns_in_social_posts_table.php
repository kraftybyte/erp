<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->text('thumbnail_url')->nullable()->change();
            $table->text('media_url')->nullable()->change();
            $table->text('permalink')->nullable()->change();
            $table->text('caption')->nullable()->change(); // caption juga bisa panjang
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->string('thumbnail_url')->nullable()->change();
            $table->string('media_url')->nullable()->change();
            $table->string('permalink')->nullable()->change();
            $table->string('caption')->nullable()->change();
        });
    }
};