<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add quotation_prefix setting
        DB::table('settings')->updateOrInsert(
            ['group' => 'general', 'name' => 'quotation_prefix'],
            [
                'payload' => json_encode('DG'),
                'locked' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Add invoice_prefix setting
        DB::table('settings')->updateOrInsert(
            ['group' => 'general', 'name' => 'invoice_prefix'],
            [
                'payload' => json_encode('INV'),
                'locked' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('group', 'general')
            ->whereIn('name', ['quotation_prefix', 'invoice_prefix'])
            ->delete();
    }
};
