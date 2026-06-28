<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Sender Information
            $table->string('sender_name')->nullable()->after('notes');
            $table->text('sender_address')->nullable()->after('sender_name');
            $table->string('sender_phone')->nullable()->after('sender_address');

            // Recipient Information
            $table->string('recipient_name')->nullable()->after('sender_phone');
            $table->text('recipient_address')->nullable()->after('recipient_name');
            $table->string('recipient_phone')->nullable()->after('recipient_address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'sender_name',
                'sender_address',
                'sender_phone',
                'recipient_name',
                'recipient_address',
                'recipient_phone',
            ]);
        });
    }
};
