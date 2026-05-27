<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('laravel-settings.tables.settings', 'settings');

        // Check if dark_mode_enabled row already exists
        $exists = DB::table($table)
            ->where('group', 'general')
            ->where('name', 'dark_mode_enabled')
            ->exists();

        if (!$exists) {
            // Remove any incorrectly added dark_mode_enabled from other rows' payloads
            DB::table($table)
                ->where('group', 'general')
                ->update([
                    'payload' => DB::raw("JSON_REMOVE(payload, '\$.dark_mode_enabled')")
                ]);

            // Insert new row for dark_mode_enabled (Spatie Laravel Settings uses separate rows)
            DB::table($table)->insert([
                'group' => 'general',
                'name' => 'dark_mode_enabled',
                'locked' => false,
                'payload' => json_encode(false),
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
    }

    public function down(): void
    {
        $table = config('laravel-settings.tables.settings', 'settings');

        // Remove the dark_mode_enabled row
        DB::table($table)
            ->where('group', 'general')
            ->where('name', 'dark_mode_enabled')
            ->delete();
    }
};