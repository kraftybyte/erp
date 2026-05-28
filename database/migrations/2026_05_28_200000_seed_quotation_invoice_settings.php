<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $settings = [
            // Quotation Settings
            ['name' => 'quot_show_cover_pages', 'payload' => json_encode(true)],
            ['name' => 'quot_cover_page_1', 'payload' => json_encode(null)],
            ['name' => 'quot_cover_page_2', 'payload' => json_encode(null)],
            ['name' => 'quot_cover_page_3', 'payload' => json_encode(null)],
            ['name' => 'quot_theme_color', 'payload' => json_encode('#f04f29')],
            ['name' => 'quot_logo', 'payload' => json_encode(null)],
            ['name' => 'quot_company_name', 'payload' => json_encode('Nama Perusahaan')],
            ['name' => 'quot_company_email', 'payload' => json_encode('email@company.com')],
            ['name' => 'quot_company_phone', 'payload' => json_encode('021123456')],
            ['name' => 'quot_signature_name', 'payload' => json_encode('Nama Penanda Tangan')],
            ['name' => 'quot_signature_title', 'payload' => json_encode('Jabatan')],
            ['name' => 'quot_signature_image', 'payload' => json_encode(null)],
            ['name' => 'quot_terms', 'payload' => json_encode("Price above is NET.\nThis quotation is valid for 14 (fourteen) days from the quotation date.\nTerm of Payment: Full payment before project starts, or two terms (50-50) for minimum 30 million.")],

            // Invoice Settings
            ['name' => 'inv_theme_color', 'payload' => json_encode('#f04f29')],
            ['name' => 'inv_logo', 'payload' => json_encode(null)],
            ['name' => 'inv_company_name', 'payload' => json_encode('Nama Perusahaan')],
            ['name' => 'inv_company_address', 'payload' => json_encode('Alamat Perusahaan')],
            ['name' => 'inv_company_phone', 'payload' => json_encode('021123456')],
            ['name' => 'inv_bank_name', 'payload' => json_encode('BCA')],
            ['name' => 'inv_bank_account', 'payload' => json_encode('123456789')],
            ['name' => 'inv_bank_account_name', 'payload' => json_encode('Nama Penerima')],
            ['name' => 'inv_signature_name', 'payload' => json_encode('Nama Penanda Tangan')],
            ['name' => 'inv_signature_image', 'payload' => json_encode(null)],

            // ID Prefix Settings
            ['name' => 'quotation_prefix', 'payload' => json_encode('DG')],
            ['name' => 'invoice_prefix', 'payload' => json_encode('INV')],
        ];

        foreach ($settings as $setting) {
            DB::table('settings')->updateOrInsert(
                ['group' => 'general', 'name' => $setting['name']],
                [
                    'payload' => $setting['payload'],
                    'locked' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        $settings = [
            'quot_show_cover_pages', 'quot_cover_page_1', 'quot_cover_page_2', 'quot_cover_page_3',
            'quot_theme_color', 'quot_logo', 'quot_company_name', 'quot_company_email',
            'quot_company_phone', 'quot_signature_name', 'quot_signature_title', 'quot_signature_image', 'quot_terms',
            'inv_theme_color', 'inv_logo', 'inv_company_name', 'inv_company_address',
            'inv_company_phone', 'inv_bank_name', 'inv_bank_account', 'inv_bank_account_name',
            'inv_signature_name', 'inv_signature_image',
            'quotation_prefix', 'invoice_prefix',
        ];

        DB::table('settings')
            ->where('group', 'general')
            ->whereIn('name', $settings)
            ->delete();
    }
};
