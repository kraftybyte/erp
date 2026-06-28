<?php

namespace App\Filament\Pages\Setting;

use App\Services\FileService;
use App\Settings\GeneralSettings;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Filament\Support\Facades\FilamentView;
use Illuminate\Contracts\Support\Htmlable;
use Riodwanto\FilamentAceEditor\AceEditor;
use Filament\Forms\Components\FileUpload;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;


use function Filament\Support\is_app_url;

class ManageGeneral extends SettingsPage
{
    use HasPageShield;
    protected static string $settings = GeneralSettings::class;

    protected static ?int $navigationSort = 99;
    protected static ?string $navigationIcon = 'fluentui-settings-20';

    public static function getNavigationLabel(): string
    {
        return \App\Helpers\NavigationHelper::getLabel('general_settings', 'General Settings');
    }

    /**
     * @var array<string, mixed> | null
     */
    public ?array $data = [];

    public string $themePath = '';

    public string $twConfigPath = '';

    public function mount(): void
    {
        $this->themePath = resource_path('css/filament/admin/theme.css');
        $this->twConfigPath = resource_path('css/filament/admin/tailwind.config.js');

        $this->fillForm();
    }

    protected function fillForm(): void
    {
        $settings = app(static::getSettings());
        $data = $settings->toArray();

        $fileService = new FileService;

        $data['theme-editor'] = $fileService->readfile($this->themePath);
        $data['tw-config-editor'] = $fileService->readfile($this->twConfigPath);

        // Convert terms to string for textarea - handle both string and array
        $terms = $settings->quot_terms;
        if (is_array($terms) && !empty($terms)) {
            $data['quot_terms'] = implode("\n", $terms);
        } elseif (is_string($terms)) {
            $data['quot_terms'] = $terms;
        } else {
            $data['quot_terms'] = '';
        }

        $this->form->fill($data);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Site')
                    ->label(fn () => __('page.general_settings.sections.site'))
                    ->description(fn () => __('page.general_settings.sections.site.description'))
                    ->icon('fluentui-web-asset-24-o')
                    ->schema([
                        Forms\Components\Grid::make()->schema([
                            Forms\Components\TextInput::make('brand_name')
                                ->label(fn () => __('page.general_settings.fields.brand_name'))
                                ->required(),
                            Forms\Components\Select::make('site_active')
                                ->label(fn () => __('page.general_settings.fields.site_active'))
                                ->options([
                                    0 => "Not Active",
                                    1 => "Active",
                                ])
                                ->native(false)
                                ->required(),
                        ]),
                        Forms\Components\Grid::make()->schema([
                            Forms\Components\TextInput::make('brand_logoHeight')
                                ->label(fn () => __('page.general_settings.fields.brand_logoHeight'))
                                ->required()
                                ->columnSpanFull()
                                ->maxWidth('w-1/2'),
                            Forms\Components\Grid::make()->schema([
                                FileUpload::make('brand_logo')
                                ->label(fn () => __('page.general_settings.fields.brand_logo'))
                                ->image()
                                ->directory('sites')
                                ->visibility('public')
                                ->moveFiles()
                                ->required(),

                            FileUpload::make('site_favicon')
                                ->label(fn () => __('page.general_settings.fields.site_favicon'))
                                ->image()
                                ->directory('sites')
                                ->visibility('public')
                                ->acceptedFileTypes(['image/x-icon', 'image/vnd.microsoft.icon'])
                                ->moveFiles()
                                ->required(),
                            ]),
                        ])->columns(4),
                    ]),
                Forms\Components\Tabs::make('Tabs')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Color Palette')
                            ->schema([
                                Forms\Components\Toggle::make('dark_mode_enabled')
                                    ->label('Dark Mode')
                                    ->hint('Enable dark theme for the admin panel')
                                    ->reactive(),
                                Forms\Components\Section::make('Primary Gradient')
                                    ->description('Warna gradient utama untuk dashboard cards')
                                    ->schema([
                                        Forms\Components\Grid::make(2)->schema([
                                            Forms\Components\ColorPicker::make('primary_gradient_from')
                                                ->label('Gradient From')
                                                ->default('#3b82f6'),
                                            Forms\Components\ColorPicker::make('primary_gradient_to')
                                                ->label('Gradient To')
                                                ->default('#1d4ed8'),
                                        ]),
                                    ]),
                                Forms\Components\Section::make('Theme Colors')
                                    ->schema([
                                        Forms\Components\Grid::make(3)->schema([
                                            Forms\Components\ColorPicker::make('site_theme.primary')
                                                ->label(fn () => __('page.general_settings.fields.primary')),
                                            Forms\Components\ColorPicker::make('site_theme.secondary')
                                                ->label(fn () => __('page.general_settings.fields.secondary')),
                                            Forms\Components\ColorPicker::make('site_theme.gray')
                                                ->label(fn () => __('page.general_settings.fields.gray')),
                                            Forms\Components\ColorPicker::make('site_theme.success')
                                                ->label(fn () => __('page.general_settings.fields.success')),
                                            Forms\Components\ColorPicker::make('site_theme.danger')
                                                ->label(fn () => __('page.general_settings.fields.danger')),
                                            Forms\Components\ColorPicker::make('site_theme.info')
                                                ->label(fn () => __('page.general_settings.fields.info')),
                                            Forms\Components\ColorPicker::make('site_theme.warning')
                                                ->label(fn () => __('page.general_settings.fields.warning')),
                                        ]),
                                    ]),
                            ]),
                        Forms\Components\Tabs\Tab::make('Event Categories')
                            ->schema([
                                Forms\Components\Section::make('Agenda Categories')
                                    ->description('Tambah atau edit kategori agenda event')
                                    ->schema([
                                        Forms\Components\Repeater::make('event_categories')
                                            ->label('')
                                            ->schema([
                                                Forms\Components\TextInput::make('label')
                                                    ->label('Nama Category')
                                                    ->required(),
                                                Forms\Components\ColorPicker::make('color')
                                                    ->label('Warna')
                                                    ->required(),
                                            ])
                                            ->columns(2)
                                            ->defaultItems(5)
                                            ->minItems(1)
                                            ->addActionLabel('Tambah Category'),
                                    ]),
                            ]),
                        Forms\Components\Tabs\Tab::make('Quotation Template')
                            ->schema([
                                Forms\Components\Section::make('ID Settings')
                                    ->description('Pengaturan format ID quotation')
                                    ->schema([
                                        Forms\Components\TextInput::make('quotation_prefix')
                                            ->label('Prefix Quotation ID')
                                            ->placeholder('DG')
                                            ->maxLength(10)
                                            ->helperText('Contoh: DG → #DG/2605/00001'),
                                    ]),
                                Forms\Components\Section::make('Cover Pages')
                                    ->description('Atur tampilan cover page quotation')
                                    ->schema([
                                        Forms\Components\Toggle::make('quot_show_cover_pages')
                                            ->label('Tampilkan Cover Pages')
                                            ->default(true),
                                        Forms\Components\Grid::make(3)
                                            ->schema([
                                                FileUpload::make('quot_cover_page_1')
                                                    ->label('Cover Page 1 (Header)')
                                                    ->image()
                                                    ->directory('quotation/covers')
                                                    ->visibility('public')
                                                    ->nullable(),
                                                FileUpload::make('quot_cover_page_2')
                                                    ->label('Cover Page 2 (Company Profile)')
                                                    ->image()
                                                    ->directory('quotation/covers')
                                                    ->visibility('public')
                                                    ->nullable(),
                                                FileUpload::make('quot_cover_page_3')
                                                    ->label('Cover Page 3 (Footer)')
                                                    ->image()
                                                    ->directory('quotation/covers')
                                                    ->visibility('public')
                                                    ->nullable(),
                                            ]),
                                    ]),
                                Forms\Components\Section::make('Theme Color')
                                    ->description('Pilih warna utama untuk quotation')
                                    ->schema([
                                        Forms\Components\ColorPicker::make('quot_theme_color')
                                            ->label('Primary Color')
                                            ->default('#f04f29'),
                                        FileUpload::make('quot_logo')
                                            ->label('Logo untuk Header Quotation')
                                            ->image()
                                            ->directory('quotation')
                                            ->visibility('public')
                                            ->nullable()
                                            ->helperText('Upload logo khusus quotation (optional)'),
                                    ]),
                                Forms\Components\Section::make('Company Information')
                                    ->description('Informasi perusahaan yang muncul di quotation')
                                    ->schema([
                                        Forms\Components\TextInput::make('quot_company_name')
                                            ->label('Nama Perusahaan'),
                                        Forms\Components\TextInput::make('quot_company_email')
                                            ->label('Email'),
                                        Forms\Components\TextInput::make('quot_company_phone')
                                            ->label('Telepon'),
                                    ]),
                                Forms\Components\Section::make('Signature')
                                    ->description('Informasi tanda tangan di quotation')
                                    ->schema([
                                        Forms\Components\TextInput::make('quot_signature_name')
                                            ->label('Nama Penanda Tangan'),
                                        Forms\Components\TextInput::make('quot_signature_title')
                                            ->label('Jabatan'),
                                        FileUpload::make('quot_signature_image')
                                            ->label('Upload Tanda Tangan')
                                            ->image()
                                            ->directory('quotation/signatures')
                                            ->visibility('public')
                                            ->nullable(),
                                    ]),
                                Forms\Components\Section::make('Terms & Conditions')
                                    ->description('Syarat dan ketentuan quotation (satu baris per ketentuan)')
                                    ->schema([
                                        Forms\Components\Textarea::make('quot_terms')
                                            ->label('Ketentuan')
                                            ->rows(5)
                                            ->helperText('Masukkan satu ketentuan per baris'),
                                    ]),
                            ]),
                        Forms\Components\Tabs\Tab::make('Invoice Template')
                            ->schema([
                                Forms\Components\Section::make('ID Settings')
                                    ->description('Pengaturan format ID invoice')
                                    ->schema([
                                        Forms\Components\TextInput::make('invoice_prefix')
                                            ->label('Prefix Invoice ID')
                                            ->placeholder('INV')
                                            ->maxLength(10)
                                            ->helperText('Contoh: INV → #INV/2605/00001'),
                                    ]),
                                Forms\Components\Section::make('Theme Color')
                                    ->description('Pilih warna utama untuk invoice')
                                    ->schema([
                                        Forms\Components\ColorPicker::make('inv_theme_color')
                                            ->label('Primary Color')
                                            ->default('#f04f29'),
                                        FileUpload::make('inv_logo')
                                            ->label('Logo untuk Header Invoice')
                                            ->image()
                                            ->directory('invoice')
                                            ->visibility('public')
                                            ->nullable()
                                            ->helperText('Upload logo khusus invoice (optional)'),
                                    ]),
                                Forms\Components\Section::make('Company Information')
                                    ->description('Informasi perusahaan yang muncul di invoice')
                                    ->schema([
                                        Forms\Components\TextInput::make('inv_company_name')
                                            ->label('Nama Perusahaan'),
                                        Forms\Components\Textarea::make('inv_company_address')
                                            ->label('Alamat')
                                            ->rows(2),
                                        Forms\Components\TextInput::make('inv_company_phone')
                                            ->label('Telepon'),
                                    ]),
                                Forms\Components\Section::make('Bank Information')
                                    ->description('Informasi rekening untuk pembayaran')
                                    ->schema([
                                        Forms\Components\Grid::make(3)->schema([
                                            Forms\Components\TextInput::make('inv_bank_name')
                                                ->label('Nama Bank'),
                                            Forms\Components\TextInput::make('inv_bank_account')
                                                ->label('Nomor Rekening'),
                                            Forms\Components\TextInput::make('inv_bank_account_name')
                                                ->label('Nama Penerima'),
                                        ]),
                                    ]),
                                Forms\Components\Section::make('Signature')
                                    ->description('Informasi tanda tangan di invoice')
                                    ->schema([
                                        Forms\Components\Grid::make(2)->schema([
                                            Forms\Components\TextInput::make('inv_signature_name')
                                                ->label('Nama Penanda Tangan'),
                                            FileUpload::make('inv_signature_image')
                                                ->label('Upload Tanda Tangan')
                                                ->image()
                                                ->directory('invoice/signatures')
                                                ->visibility('public')
                                                ->nullable(),
                                        ]),
                                    ]),
                            ]),
                        Forms\Components\Tabs\Tab::make('Project Categories')
                            ->label('Project Categories')
                            ->icon('heroicon-o-folder')
                            ->schema([
                                Forms\Components\Section::make('Kategori Project')
                                    ->description('Atur kategori/project type yang tersedia saat membuat project')
                                    ->schema([
                                        Forms\Components\Repeater::make('project_categories')
                                            ->label('')
                                            ->schema([
                                                Forms\Components\TextInput::make('category_name')
                                                    ->label('Nama Kategori')
                                                    ->required(),
                                            ])
                                            ->defaultItems(8)
                                            ->minItems(1)
                                            ->addActionLabel('Tambah Kategori'),
                                    ]),
                                Forms\Components\Section::make('Packing Slip')
                                    ->description('Pengaturan untuk print packing slip thermal')
                                    ->schema([
                                        Forms\Components\TextInput::make('packing_title')
                                            ->label('Judul Packing Slip')
                                            ->placeholder('PACKING SLIP')
                                            ->helperText('Judul yang muncul di print thermal'),
                                        Forms\Components\TextInput::make('packing_qr_url_prefix')
                                            ->label('URL Prefix QR Code')
                                            ->placeholder('https://erp.example.com/admin')
                                            ->helperText('QR akan mengarah ke /projects/{id}'),
                                    ]),
                            ]),
                        Forms\Components\Tabs\Tab::make('Code Editor')
                            ->schema([
                                Forms\Components\Grid::make()->schema([
                                    AceEditor::make('theme-editor')
                                        ->label('theme.css')
                                        ->mode('css')
                                        ->height('24rem'),
                                    AceEditor::make('tw-config-editor')
                                        ->label('tailwind.config.js')
                                        ->height('24rem')
                                ])
                            ]),
                        Forms\Components\Tabs\Tab::make('Navigation Labels')
                            ->label('Navigation')
                            ->icon('heroicon-o-bars-3')
                            ->schema([
                                Forms\Components\Section::make('CRM Navigation')
                                    ->description('Custom label untuk menu CRM')
                                    ->schema([
                                        Forms\Components\Grid::make(3)->schema([
                                            Forms\Components\TextInput::make('nav_leads')
                                                ->label('Leads')
                                                ->placeholder('Leads'),
                                            Forms\Components\TextInput::make('nav_client')
                                                ->label('Client')
                                                ->placeholder('Client'),
                                            Forms\Components\TextInput::make('nav_product')
                                                ->label('Product')
                                                ->placeholder('Product'),
                                        ]),
                                        Forms\Components\Grid::make(2)->schema([
                                            Forms\Components\TextInput::make('nav_quotation')
                                                ->label('Quotation')
                                                ->placeholder('Quotation'),
                                            Forms\Components\TextInput::make('nav_contract')
                                                ->label('Contract')
                                                ->placeholder('Contract'),
                                        ]),
                                    ]),
                                Forms\Components\Section::make('Project Navigation')
                                    ->description('Custom label untuk menu Project')
                                    ->schema([
                                        Forms\Components\Grid::make(3)->schema([
                                            Forms\Components\TextInput::make('nav_project')
                                                ->label('Project')
                                                ->placeholder('Project'),
                                            Forms\Components\TextInput::make('nav_task_kanban')
                                                ->label('Task Kanban')
                                                ->placeholder('Task Kanban'),
                                            Forms\Components\TextInput::make('nav_task_list')
                                                ->label('Task List')
                                                ->placeholder('Task List'),
                                        ]),
                                        Forms\Components\TextInput::make('nav_timeline')
                                            ->label('Timeline')
                                            ->placeholder('Timeline')
                                            ->columnSpanFull(),
                                    ]),
                                Forms\Components\Section::make('Finance Navigation')
                                    ->description('Custom label untuk menu Finance')
                                    ->schema([
                                        Forms\Components\Grid::make(3)->schema([
                                            Forms\Components\TextInput::make('nav_expense')
                                                ->label('Expense')
                                                ->placeholder('Expense'),
                                            Forms\Components\TextInput::make('nav_advance')
                                                ->label('Advance')
                                                ->placeholder('Advance'),
                                            Forms\Components\TextInput::make('nav_payment_account')
                                                ->label('Payment Account')
                                                ->placeholder('Payment Account'),
                                        ]),
                                        Forms\Components\Grid::make(2)->schema([
                                            Forms\Components\TextInput::make('nav_invoice')
                                                ->label('Invoice')
                                                ->placeholder('Invoice'),
                                            Forms\Components\TextInput::make('nav_analytics')
                                                ->label('Analytics')
                                                ->placeholder('Analytics'),
                                        ]),
                                    ]),
                                Forms\Components\Section::make('HR Navigation')
                                    ->description('Custom label untuk menu HR')
                                    ->schema([
                                        Forms\Components\Grid::make(3)->schema([
                                            Forms\Components\TextInput::make('nav_employee')
                                                ->label('Employee')
                                                ->placeholder('Employee'),
                                            Forms\Components\TextInput::make('nav_absence')
                                                ->label('Absence')
                                                ->placeholder('Absence'),
                                            Forms\Components\TextInput::make('nav_event')
                                                ->label('Event')
                                                ->placeholder('Event'),
                                        ]),
                                    ]),
                                Forms\Components\Section::make('Tools Navigation')
                                    ->description('Custom label untuk menu Tools')
                                    ->schema([
                                        Forms\Components\Grid::make(3)->schema([
                                            Forms\Components\TextInput::make('nav_social_account')
                                                ->label('Social Account')
                                                ->placeholder('Social Account'),
                                            Forms\Components\TextInput::make('nav_connected_account')
                                                ->label('Connected Account')
                                                ->placeholder('Connected Account'),
                                            Forms\Components\TextInput::make('nav_meta_ad')
                                                ->label('Meta Ad Account')
                                                ->placeholder('Meta Ad Account'),
                                        ]),
                                    ]),
                                Forms\Components\Section::make('Access & Settings Navigation')
                                    ->description('Custom label untuk menu Access dan Settings')
                                    ->schema([
                                        Forms\Components\Grid::make(3)->schema([
                                            Forms\Components\TextInput::make('nav_user_management')
                                                ->label('User Management')
                                                ->placeholder('User Management'),
                                            Forms\Components\TextInput::make('nav_general_settings')
                                                ->label('General Settings')
                                                ->placeholder('General Settings'),
                                            Forms\Components\TextInput::make('nav_mail_settings')
                                                ->label('Mail Settings')
                                                ->placeholder('Mail Settings'),
                                        ]),
                                        Forms\Components\TextInput::make('nav_pwa_settings')
                                            ->label('PWA Settings')
                                            ->placeholder('PWA Settings'),
                                    ]),
                            ]),
                        Forms\Components\Tabs\Tab::make('Footer')
                            ->label('Footer')
                            ->icon('heroicon-o-identification')
                            ->schema([
                                Forms\Components\Section::make('Footer Options')
                                    ->description('Pengaturan tampilan footer aplikasi')
                                    ->schema([
                                        Forms\Components\Grid::make(2)->schema([
                                            Forms\Components\Toggle::make('footer_show_brand')
                                                ->label('Tampilkan Brand Name')
                                                ->default(true),
                                            Forms\Components\Toggle::make('footer_show_version')
                                                ->label('Tampilkan Version')
                                                ->default(true),
                                            Forms\Components\Toggle::make('footer_show_copyright')
                                                ->label('Tampilkan Copyright')
                                                ->default(true),
                                        ]),
                                    ]),
                                Forms\Components\Section::make('Custom Text')
                                    ->description('Teks kustom untuk footer')
                                    ->schema([
                                        Forms\Components\TextInput::make('footer_brand_text')
                                            ->label('Brand Text Override')
                                            ->placeholder('Kosongkan untuk gunakan app name default')
                                            ->helperText('Teks yang ditampilkan di sisi kiri footer'),
                                        Forms\Components\TextInput::make('footer_copyright_text')
                                            ->label('Copyright Text')
                                            ->placeholder('All Rights Reserved.')
                                            ->helperText('Teks copyright di sisi kanan footer'),
                                        Forms\Components\TextInput::make('footer_custom_text')
                                            ->label('Custom Text')
                                            ->placeholder('e.g. Developed by Team')
                                            ->helperText('Teks tambahan di tengah footer'),
                                    ]),
                            ]),
                    ])
                    ->persistTabInQueryString()
                    ->columnSpanFull(),
            ])
            ->columns(2)
            ->statePath('data');
    }

    public function save(): void
    {
        try {
            $data = $this->form->getState();

            $settings = app(static::getSettings());
            $settings->fill($data);
            $settings->save();

            $fileService = new FileService;
            $fileService->writeFile($this->themePath, $data['theme-editor'] ?? '');
            $fileService->writeFile($this->twConfigPath, $data['tw-config-editor'] ?? '');

            Notification::make()
                ->title('Settings updated.')
                ->success()
                ->send();

            $this->redirect(static::getUrl(), navigate: FilamentView::hasSpaMode() && is_app_url(static::getUrl()));
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    public static function getNavigationGroup(): ?string
    {
        return __("menu.nav_group.settings");
    }

    public function getTitle(): string|Htmlable
    {
        return __("page.general_settings.title");
    }

    public function getHeading(): string|Htmlable
    {
        return __("page.general_settings.heading");
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __("page.general_settings.subheading");
    }
}