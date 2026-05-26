<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Only add if not exists
        $this->migrator->addIfMissing('general.event_categories', [
            ['color' => '#3bbeff', 'label' => 'Shooting Visit'],
            ['color' => '#347b9e', 'label' => 'Shooting Studio'],
            ['color' => '#ff5915', 'label' => 'Agenda Digitalk'],
            ['color' => '#fcc530', 'label' => 'Meeting'],
            ['color' => '#e53935', 'label' => 'Libur'],
        ]);

        // Quotation Template Settings
        $this->migrator->addIfMissing('general.quot_show_cover_pages', true);
        $this->migrator->addIfMissing('general.quot_cover_page_1', null);
        $this->migrator->addIfMissing('general.quot_cover_page_2', null);
        $this->migrator->addIfMissing('general.quot_cover_page_3', null);
        $this->migrator->addIfMissing('general.quot_theme_color', '#f04f29');
        $this->migrator->addIfMissing('general.quot_logo', null);
        $this->migrator->addIfMissing('general.quot_company_name', 'Digitalk Creative');
        $this->migrator->addIfMissing('general.quot_company_email', 'hubungi@digitalkcreative.id');
        $this->migrator->addIfMissing('general.quot_company_phone', '085186028747');
        $this->migrator->addIfMissing('general.quot_signature_name', 'Indra Purwanto');
        $this->migrator->addIfMissing('general.quot_signature_title', 'Director');
        $this->migrator->addIfMissing('general.quot_signature_image', null);
        $this->migrator->addIfMissing('general.quot_terms', "Price above is NET.\nThis quotation is valid for 14 (fourteen) days from the quotation date.\nTerm of Payment: Full payment before project starts, or two terms (50-50) for minimum 30 million.");

        // Invoice Template Settings
        $this->migrator->addIfMissing('general.inv_theme_color', '#f04f29');
        $this->migrator->addIfMissing('general.inv_logo', null);
        $this->migrator->addIfMissing('general.inv_company_name', 'PT. Digital Catha Sangkara');
        $this->migrator->addIfMissing('general.inv_company_address', 'Gedung EduCenter Lt.2A Unit VO-24088 Jl. Sekolah Foresta.8 BSD City, Lengkong Kulon, Pagedangan, Tangerang Regency, Banten 15331');
        $this->migrator->addIfMissing('general.inv_company_phone', '085186028747');
        $this->migrator->addIfMissing('general.inv_bank_name', 'BCA');
        $this->migrator->addIfMissing('general.inv_bank_account', '5385141120');
        $this->migrator->addIfMissing('general.inv_bank_account_name', 'PT DIGITAL CATHA');
        $this->migrator->addIfMissing('general.inv_signature_name', 'Madita Heidy');
        $this->migrator->addIfMissing('general.inv_signature_image', null);
    }

    public function down(): void
    {
        // Nothing to remove in down
    }
};