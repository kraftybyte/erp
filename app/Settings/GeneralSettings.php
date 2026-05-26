<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public string $brand_name;
    public ?string $brand_logo;
    public string $brand_logoHeight;
    public bool $site_active;
    public ?string $site_favicon;
    public array $site_theme;

    // Event categories with colors
    public array $event_categories = [];

    // Quotation Template Settings - Individual Properties
    public bool $quot_show_cover_pages = true;
    public ?string $quot_cover_page_1 = null;
    public ?string $quot_cover_page_2 = null;
    public ?string $quot_cover_page_3 = null;
    public string $quot_theme_color = '#f04f29';
    public ?string $quot_logo = null;
    public string $quot_company_name = 'Digitalk Creative';
    public string $quot_company_email = 'hubungi@digitalkcreative.id';
    public string $quot_company_phone = '085186028747';
    public string $quot_signature_name = 'Indra Purwanto';
    public string $quot_signature_title = 'Director';
    public ?string $quot_signature_image = null;
    public string $quot_terms = '';

    // Invoice Template Settings - Individual Properties
    public string $inv_theme_color = '#f04f29';
    public ?string $inv_logo = null;
    public string $inv_company_name = 'PT. Digital Catha Sangkara';
    public string $inv_company_address = 'Gedung EduCenter Lt.2A Unit VO-24088 Jl. Sekolah Foresta.8 BSD City, Lengkong Kulon, Pagedangan, Tangerang Regency, Banten 15331';
    public string $inv_company_phone = '085186028747';
    public string $inv_bank_name = 'BCA';
    public string $inv_bank_account = '5385141120';
    public string $inv_bank_account_name = 'PT DIGITAL CATHA';
    public string $inv_signature_name = 'Madita Heidy';
    public ?string $inv_signature_image = null;

    public static function group(): string
    {
        return 'general';
    }

    public static function defaults(): array
    {
        return [
            'event_categories' => [
                ['color' => '#3bbeff', 'label' => 'Shooting Visit'],
                ['color' => '#347b9e', 'label' => 'Shooting Studio'],
                ['color' => '#ff5915', 'label' => 'Agenda Digitalk'],
                ['color' => '#fcc530', 'label' => 'Meeting'],
                ['color' => '#e53935', 'label' => 'Libur'],
            ],
            'quot_show_cover_pages' => true,
            'quot_cover_page_1' => null,
            'quot_cover_page_2' => null,
            'quot_cover_page_3' => null,
            'quot_theme_color' => '#f04f29',
            'quot_logo' => null,
            'quot_company_name' => 'Digitalk Creative',
            'quot_company_email' => 'hubungi@digitalkcreative.id',
            'quot_company_phone' => '085186028747',
            'quot_signature_name' => 'Indra Purwanto',
            'quot_signature_title' => 'Director',
            'quot_signature_image' => null,
            'quot_terms' => "Price above is NET.\nThis quotation is valid for 14 (fourteen) days from the quotation date.\nTerm of Payment: Full payment before project starts, or two terms (50-50) for minimum 30 million.",
            // Invoice Settings Defaults
            'inv_theme_color' => '#f04f29',
            'inv_logo' => null,
            'inv_company_name' => 'PT. Digital Catha Sangkara',
            'inv_company_address' => 'Gedung EduCenter Lt.2A Unit VO-24088 Jl. Sekolah Foresta.8 BSD City, Lengkong Kulon, Pagedangan, Tangerang Regency, Banten 15331',
            'inv_company_phone' => '085186028747',
            'inv_bank_name' => 'BCA',
            'inv_bank_account' => '5385141120',
            'inv_bank_account_name' => 'PT DIGITAL CATHA',
            'inv_signature_name' => 'Madita Heidy',
            'inv_signature_image' => null,
        ];
    }

    // Get quotation settings as array for view
    public function getQuotationSettings(): array
    {
        return [
            'show_cover_pages' => $this->quot_show_cover_pages,
            'cover_page_1' => $this->quot_cover_page_1,
            'cover_page_2' => $this->quot_cover_page_2,
            'cover_page_3' => $this->quot_cover_page_3,
            'theme_color' => $this->quot_theme_color,
            'logo' => $this->quot_logo,
            'company_info' => [
                'name' => $this->quot_company_name,
                'email' => $this->quot_company_email,
                'phone' => $this->quot_company_phone,
            ],
            'signature_name' => $this->quot_signature_name,
            'signature_title' => $this->quot_signature_title,
            'signature_image' => $this->quot_signature_image,
            'terms' => $this->getTermsArray(),
        ];
    }

    // Get invoice settings as array for view
    public function getInvoiceSettings(): array
    {
        return [
            'theme_color' => $this->inv_theme_color,
            'logo' => $this->inv_logo,
            'company_info' => [
                'name' => $this->inv_company_name,
                'address' => $this->inv_company_address,
                'phone' => $this->inv_company_phone,
            ],
            'bank_info' => [
                'name' => $this->inv_bank_name,
                'account' => $this->inv_bank_account,
                'account_name' => $this->inv_bank_account_name,
            ],
            'signature_name' => $this->inv_signature_name,
            'signature_image' => $this->inv_signature_image,
        ];
    }

    public function getTermsArray(): array
    {
        $terms = $this->quot_terms ?? '';
        return array_filter(array_map('trim', explode("\n", $terms)));
    }

    public function getEventCategoryLabel(string $color): string
    {
        foreach ($this->event_categories as $cat) {
            if ($cat['color'] === $color) {
                return $cat['label'];
            }
        }
        return 'Agenda';
    }

    public function getEventCategoryOptions(): array
    {
        $options = [];
        foreach ($this->event_categories as $cat) {
            $options[$cat['color']] = $cat['label'];
        }
        return $options;
    }

    public function getEventCategoryLabels(): array
    {
        $labels = [];
        foreach ($this->event_categories as $cat) {
            $labels[$cat['color']] = $cat['label'];
        }
        return $labels;
    }
}