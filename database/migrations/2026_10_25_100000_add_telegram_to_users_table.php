<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Satu akun Telegram hanya boleh terhubung ke satu user ERP.
            $table->string('telegram_chat_id', 32)->nullable()->unique()->after('phone');
            $table->timestamp('telegram_linked_at')->nullable()->after('telegram_chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['telegram_chat_id']);
            $table->dropColumn(['telegram_chat_id', 'telegram_linked_at']);
        });
    }
};
