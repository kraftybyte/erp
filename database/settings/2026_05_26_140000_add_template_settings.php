<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Only add if not exists - check before adding
        if (!$this->migrator->exists('general.event_categories')) {
            $this->migrator->add('general.event_categories', [
                ['color' => '#3bbeff', 'label' => 'Shooting Visit'],
                ['color' => '#347b9e', 'label' => 'Shooting Studio'],
                ['color' => '#ff5915', 'label' => 'Agenda Digitalk'],
                ['color' => '#fcc530', 'label' => 'Meeting'],
                ['color' => '#e53935', 'label' => 'Libur'],
            ]);
        }

        // Quotation Template Settings
        $quotationSettings = [
            'general.quot_show_cover_pages' => true,
            'general.quot_cover_page_1' => null,
            'general.quot_cover_page_2' => null,
            'general.quot_cover_page_3' => null,
            'general.quot_theme_color' => '#f04f29',
            'general.quot_logo' => null,
            'general.quot_company_name' => 'Digitalk Creative',
            'general.quot_company_email' => 'hubungi@digitalkcreative.id',
            'general.quot_company_phone' => '085186028747',
            'general.quot_signature_name' => 'Indra Purwanto',
            'general.quot_signature_title' => 'Director',
            'general.quot_signature_image' => null,
            'general.quot_terms' => "Price above is NET.\nThis quotation is valid for 14 (fourteen) days from the quotation date.\nTerm of Payment: Full payment before project starts, or two terms (50-50) for minimum 30 million.",
        ];

        foreach ($quotationSettings as $key => $value) {
            if (!$this->migrator->exists($key)) {
                $this->migrator->add($key, $value);
            }
        }

        // Invoice Template Settings
        $invoiceSettings = [
            'general.inv_theme_color' => '#f04f29',
            'general.inv_logo' => null,
            'general.inv_company_name' => 'PT. Digital Catha Sangkara',
            'general.inv_company_address' => 'Gedung EduCenter Lt.2A Unit VO-24088 Jl. Sekolah Foresta.8 BSD City, Lengkong Kulon, Pagedangan, Tangerang Regency, Banten 15331',
            'general.inv_company_phone' => '085186028747',
            'general.inv_bank_name' => 'BCA',
            'general.inv_bank_account' => '5385141120',
            'general.inv_bank_account_name' => 'PT DIGITAL CATHA',
            'general.inv_signature_name' => 'Madita Heidy',
            'general.inv_signature_image' => null,
        ];

        foreach ($invoiceSettings as $key => $value) {
            if (!$this->migrator->exists($key)) {
                $this->migrator->add($key, $value);
            }
        }
    }

    public function down(): void
    {
        // Nothing to remove in down
    }
};