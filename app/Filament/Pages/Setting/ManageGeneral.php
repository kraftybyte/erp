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

        // Convert terms array back to newlines for textarea
        $termsArray = $settings->getTermsArray();
        $data['quot_terms'] = implode("\n", $termsArray);

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
                            ])
                            ->columns(3),
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

    public static function getNavigationLabel(): string
    {
        return __("page.general_settings.navigationLabel");
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