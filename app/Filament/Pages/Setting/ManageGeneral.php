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
use Illuminate\Support\Facades\Storage;
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

        $data = $this->mutateFormDataBeforeFill($settings->toArray());

        $fileService = new FileService;

        $data['theme-editor'] = $fileService->readfile($this->themePath);

        $data['tw-config-editor'] = $fileService->readfile($this->twConfigPath);

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
                                ->image()  // Menjamin file yang diupload adalah gambar
                                ->directory('sites')  // Menyimpan file di storage/app/public/sites
                                ->visibility('public')  // File akan bisa diakses secara publik
                                ->moveFiles()  // Memindahkan file setelah diupload
                                ->required(),

                            FileUpload::make('site_favicon')
                                ->label(fn () => __('page.general_settings.fields.site_favicon'))
                                ->image()  // Menjamin file yang diupload adalah gambar
                                ->directory('sites')  // Menyimpan file di storage/app/public/sites
                                ->visibility('public')  // File akan bisa diakses secara publik
                                ->acceptedFileTypes(['image/x-icon', 'image/vnd.microsoft.icon'])  // Menentukan tipe file yang diterima
                                ->moveFiles()  // Memindahkan file setelah diupload
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
            $data = $this->mutateFormDataBeforeSave($this->form->getState());

            $settings = app(static::getSettings());

            $settings->fill($data);
            $settings->save();

            $fileService = new FileService;
            $fileService->writeFile($this->themePath, $data['theme-editor']);
            $fileService->writeFile($this->twConfigPath, $data['tw-config-editor']);

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
