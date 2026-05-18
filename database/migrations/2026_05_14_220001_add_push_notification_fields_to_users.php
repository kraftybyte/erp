<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Push notification fields
            $table->json('push_subscription')->nullable()->after('remember_token');
            $table->boolean('push_enabled')->default(false)->after('push_subscription');

            // Mobile settings
            $table->string('device_type')->nullable()->after('push_enabled');
            $table->string('device_token')->nullable()->after('device_type');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['push_subscription', 'push_enabled', 'device_type', 'device_token']);
        });
    }
};