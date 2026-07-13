<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();

        $settings = [
            // App Settings
            ['group' => 'general', 'name' => 'app_name', 'payload' => json_encode('ERP Digitalk'), 'locked' => false],

            // Contract Template Settings
            ['group' => 'general', 'name' => 'contract_logo', 'payload' => json_encode(null), 'locked' => false],
            ['group' => 'general', 'name' => 'contract_company_name', 'payload' => json_encode('PT. Nama Perusahaan'), 'locked' => false],
            ['group' => 'general', 'name' => 'contract_company_address', 'payload' => json_encode(''), 'locked' => false],
            ['group' => 'general', 'name' => 'contract_company_phone', 'payload' => json_encode(''), 'locked' => false],
            ['group' => 'general', 'name' => 'contract_company_email', 'payload' => json_encode(''), 'locked' => false],
            ['group' => 'general', 'name' => 'contract_bank_name', 'payload' => json_encode('Bank'), 'locked' => false],
            ['group' => 'general', 'name' => 'contract_bank_account', 'payload' => json_encode(''), 'locked' => false],
            ['group' => 'general', 'name' => 'contract_bank_account_name', 'payload' => json_encode(''), 'locked' => false],
            ['group' => 'general', 'name' => 'contract_signature_name', 'payload' => json_encode(''), 'locked' => false],
            ['group' => 'general', 'name' => 'contract_signature_title', 'payload' => json_encode(''), 'locked' => false],
            ['group' => 'general', 'name' => 'contract_signature_image', 'payload' => json_encode(null), 'locked' => false],
            ['group' => 'general', 'name' => 'contract_font_size', 'payload' => json_encode('11pt'), 'locked' => false],
            ['group' => 'general', 'name' => 'contract_line_height', 'payload' => json_encode('1.6'), 'locked' => false],
        ];

        // Insert only if not exists using raw SQL
        foreach ($settings as $setting) {
            $exists = DB::table('settings')
                ->where('group', $setting['group'])
                ->where('name', $setting['name'])
                ->exists();

            if (!$exists) {
                DB::table('settings')->insert([
                    'group' => $setting['group'],
                    'name' => $setting['name'],
                    'payload' => $setting['payload'],
                    'locked' => $setting['locked'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $settings = [
            'app_name',
            'contract_logo',
            'contract_company_name',
            'contract_company_address',
            'contract_company_phone',
            'contract_company_email',
            'contract_bank_name',
            'contract_bank_account',
            'contract_bank_account_name',
            'contract_signature_name',
            'contract_signature_title',
            'contract_signature_image',
            'contract_font_size',
            'contract_line_height',
        ];

        DB::table('settings')
            ->where('group', 'general')
            ->whereIn('name', $settings)
            ->delete();
    }
};
