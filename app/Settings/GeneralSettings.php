<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public mixed $app_name = 'ERP Digitalk';
    public mixed $brand_name;
    public mixed $brand_logo = null;
    public mixed $brand_logoHeight;
    public mixed $site_active;
    public mixed $site_favicon = null;
    public mixed $site_theme = [];
    public bool $dark_mode_enabled = false;

    // Primary Gradient Colors
    public mixed $primary_gradient_from = '#90c646';
    public mixed $primary_gradient_to = '#6fa335';

    // Event categories with colors
    public mixed $event_categories = [];

    // Quotation Template Settings - Individual Properties
    public mixed $quot_show_cover_pages = true;
    public mixed $quot_cover_page_1 = null;
    public mixed $quot_cover_page_2 = null;
    public mixed $quot_cover_page_3 = null;
    public mixed $quot_theme_color = '#f04f29';
    public mixed $quot_logo = null;
    public mixed $quot_company_name = 'Digitalk Creative';
    public mixed $quot_company_email = 'hubungi@digitalkcreative.id';
    public mixed $quot_company_phone = '085186028747';
    public mixed $quot_signature_name = 'Indra Purwanto';
    public mixed $quot_signature_title = 'Director';
    public mixed $quot_signature_image = null;
    public mixed $quot_terms = '';
    public mixed $quot_currency = 'IDR';
    public mixed $quot_currency_symbol = 'Rp';

    // Invoice Template Settings - Individual Properties
    public mixed $inv_theme_color = '#f04f29';
    public mixed $inv_logo = null;
    public mixed $inv_company_name = 'PT. Digital Catha Sangkara';
    public mixed $inv_company_address = 'Gedung EduCenter Lt.2A Unit VO-24088 Jl. Sekolah Foresta.8 BSD City, Lengkong Kulon, Pagedangan, Tangerang Regency, Banten 15331';
    public mixed $inv_company_phone = '085186028747';
    public mixed $inv_bank_name = 'BCA';
    public mixed $inv_bank_account = '5385141120';
    public mixed $inv_bank_account_name = 'PT DIGITAL CATHA';
    public mixed $inv_signature_name = 'Madita Heidy';
    public mixed $inv_signature_image = null;
    public mixed $inv_currency = 'IDR';
    public mixed $inv_currency_symbol = 'Rp';

    // Packing Slip Settings
    public mixed $packing_title = 'PACKING SLIP'; // Custom title for packing slip print
    public mixed $packing_qr_url_prefix = null; // Custom URL prefix for QR code (e.g., https://track.example.com/project/)

    // Contract Template Settings
    public mixed $contract_logo = null;
    public mixed $contract_company_name = 'PT. Nama Perusahaan';
    public mixed $contract_company_address = '';
    public mixed $contract_company_phone = '';
    public mixed $contract_company_email = '';
    public mixed $contract_bank_name = 'Bank';
    public mixed $contract_bank_account = '';
    public mixed $contract_bank_account_name = '';
    public mixed $contract_signature_name = '';
    public mixed $contract_signature_title = '';
    public mixed $contract_signature_image = null;
    public mixed $contract_font_size = '11pt';
    public mixed $contract_line_height = '1.6';

    // Project Categories
    public mixed $project_categories = [];

    // Karyawan (user id) yang pengajuan expense & advance-nya langsung Disetujui
    public mixed $auto_approve_user_ids = [];

    // ID Prefix Settings
    public mixed $quotation_prefix = 'DG';
    public mixed $invoice_prefix = 'INV';

    // Navigation Label Settings
    public mixed $nav_leads = 'Leads';
    public mixed $nav_client = 'Client';
    public mixed $nav_product = 'Product';
    public mixed $nav_quotation = 'Quotation';
    public mixed $nav_contract = 'Contract';
    public mixed $nav_project = 'Project';
    public mixed $nav_task_kanban = 'Task Kanban';
    public mixed $nav_task_list = 'Task List';
    public mixed $nav_timeline = 'Timeline';
    public mixed $nav_expense = 'Expense';
    public mixed $nav_advance = 'Advance';
    public mixed $nav_payment_account = 'Payment Account';
    public mixed $nav_invoice = 'Invoice';
    public mixed $nav_analytics = 'Analytics';
    public mixed $nav_employee = 'Employee';
    public mixed $nav_absence = 'Absence';
    public mixed $nav_event = 'Event';
    public mixed $nav_user_management = 'User Management';
    public mixed $nav_general_settings = 'General Settings';
    public mixed $nav_mail_settings = 'Mail Settings';
    public mixed $nav_pwa_settings = 'PWA Settings';

    // Footer Settings
    public mixed $footer_show_brand = true;
    public mixed $footer_brand_text = '';
    public mixed $footer_show_version = true;
    public mixed $footer_show_copyright = true;
    public mixed $footer_copyright_text = '';
    public mixed $footer_custom_text = '';

    public static function group(): string
    {
        return 'general';
    }

    public static function defaults(): array
    {
        return [
            'app_name' => 'ERP Digitalk',
            'dark_mode_enabled' => false,
            'primary_gradient_from' => '#90c646',
            'primary_gradient_to' => '#6fa335',
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
            'quot_currency' => 'IDR',
            'quot_currency_symbol' => 'Rp',
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
            'inv_currency' => 'IDR',
            'inv_currency_symbol' => 'Rp',
            // Packing Slip Defaults
            'packing_title' => 'PACKING SLIP',
            'packing_qr_url_prefix' => null,
            // Contract Template Defaults
            'contract_logo' => null,
            'contract_company_name' => 'PT. Nama Perusahaan',
            'contract_company_address' => '',
            'contract_company_phone' => '',
            'contract_company_email' => '',
            'contract_bank_name' => 'Bank',
            'contract_bank_account' => '',
            'contract_bank_account_name' => '',
            'contract_signature_name' => '',
            'contract_signature_title' => '',
            'contract_signature_image' => null,
            'contract_font_size' => '11pt',
            'contract_line_height' => '1.6',
            // Project Categories
            'project_categories' => [
                'Social Media Management',
                'Video Production',
                'SEO',
                'Tiktok Handling',
                'Website Development',
                'Live Tiktok',
                'Photo Product',
                'Lain-lain',
            ],
            // ID Prefix Settings
            'quotation_prefix' => 'DG',
            'invoice_prefix' => 'INV',
            // Navigation Labels
            'nav_leads' => 'Leads',
            'nav_client' => 'Client',
            'nav_product' => 'Product',
            'nav_quotation' => 'Quotation',
            'nav_contract' => 'Contract',
            'nav_project' => 'Project',
            'nav_task_kanban' => 'Task Kanban',
            'nav_task_list' => 'Task List',
            'nav_timeline' => 'Timeline',
            'nav_expense' => 'Expense',
            'nav_advance' => 'Advance',
            'nav_payment_account' => 'Payment Account',
            'nav_invoice' => 'Invoice',
            'nav_analytics' => 'Analytics',
            'nav_employee' => 'Employee',
            'nav_absence' => 'Absence',
            'nav_event' => 'Event',
            'nav_user_management' => 'User Management',
            'nav_general_settings' => 'General Settings',
            'nav_mail_settings' => 'Mail Settings',
            'nav_pwa_settings' => 'PWA Settings',
            // Footer Settings
            'footer_show_brand' => true,
            'footer_brand_text' => '',
            'footer_show_version' => true,
            'footer_show_copyright' => true,
            'footer_copyright_text' => 'All Rights Reserved.',
            'footer_custom_text' => '',
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

        // Handle array input (when mixed type returns array)
        if (is_array($terms)) {
            return array_filter($terms);
        }

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

    /**
     * Get navigation label by key
     *
     * @param string $key The navigation key (e.g., 'leads', 'client', 'expense')
     * @return string The custom label or default
     */
    public static function getNavigationLabel(string $key): string
    {
        $settings = app(self::class);
        $property = 'nav_' . $key;

        return $settings->{$property} ?? self::getDefaultNavigationLabel($key);
    }

    /**
     * Get default navigation label
     */
    private static function getDefaultNavigationLabel(string $key): string
    {
        $defaults = [
            'leads' => 'Leads',
            'client' => 'Client',
            'product' => 'Product',
            'quotation' => 'Quotation',
            'contract' => 'Contract',
            'project' => 'Project',
            'task_kanban' => 'Task Kanban',
            'task_list' => 'Task List',
            'timeline' => 'Timeline',
            'expense' => 'Expense',
            'advance' => 'Advance',
            'payment_account' => 'Payment Account',
            'invoice' => 'Invoice',
            'analytics' => 'Analytics',
            'employee' => 'Employee',
            'absence' => 'Absence',
            'event' => 'Event',
            'user_management' => 'User Management',
            'general_settings' => 'General Settings',
            'mail_settings' => 'Mail Settings',
            'pwa_settings' => 'PWA Settings',
        ];

        return $defaults[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    /**
     * Get all navigation labels as array
     */
    public static function getNavigationLabels(): array
    {
        $settings = app(self::class);

        return [
            'leads' => $settings->nav_leads,
            'client' => $settings->nav_client,
            'product' => $settings->nav_product,
            'quotation' => $settings->nav_quotation,
            'contract' => $settings->nav_contract,
            'project' => $settings->nav_project,
            'task_kanban' => $settings->nav_task_kanban,
            'task_list' => $settings->nav_task_list,
            'timeline' => $settings->nav_timeline,
            'expense' => $settings->nav_expense,
            'advance' => $settings->nav_advance,
            'payment_account' => $settings->nav_payment_account,
            'invoice' => $settings->nav_invoice,
            'analytics' => $settings->nav_analytics,
            'employee' => $settings->nav_employee,
            'absence' => $settings->nav_absence,
            'event' => $settings->nav_event,
            'user_management' => $settings->nav_user_management,
            'general_settings' => $settings->nav_general_settings,
            'mail_settings' => $settings->nav_mail_settings,
            'pwa_settings' => $settings->nav_pwa_settings,
        ];
    }
}