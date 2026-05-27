<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('google_event_id')->nullable()->after('color');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->string('google_event_id')->nullable()->after('done_note');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('google_event_id');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('google_event_id');
        });
    }
};