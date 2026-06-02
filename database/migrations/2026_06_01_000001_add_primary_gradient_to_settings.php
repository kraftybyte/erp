<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Check if settings table exists (for Spatie package)
        if (!Schema::hasTable('settings')) {
            return;
        }

        // Add primary gradient colors if they don't exist
        $settingsToAdd = [
            'primary_gradient_from' => '#90c646',
            'primary_gradient_to' => '#6fa335',
        ];

        foreach ($settingsToAdd as $name => $default) {
            // Check if setting already exists
            $exists = \DB::table('settings')
                ->where('group', 'general')
                ->where('name', $name)
                ->exists();

            if (!$exists) {
                \DB::table('settings')->insert([
                    'group' => 'general',
                    'name' => $name,
                    'payload' => json_encode($default),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('settings')) {
            return;
        }

        \DB::table('settings')
            ->where('group', 'general')
            ->whereIn('name', ['primary_gradient_from', 'primary_gradient_to'])
            ->delete();
    }
};