<?php

namespace App\Filament\Pages\Setting;

use App\Services\GeminiService;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramWebhookManager;
use App\Settings\IntegrationSettings;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use RuntimeException;

/**
 * Settings → Integrasi: kunci Gemini, bot pengajuan, dan bot laporan keuangan.
 *
 * Kunci yang tersimpan tidak pernah dikirim ke browser. Isiannya tampil kosong
 * dan hanya mengganti nilai lama bila diisi.
 */
class ManageIntegrations extends SettingsPage
{
    use HasPageShield;

    protected static string $settings = IntegrationSettings::class;

    protected static ?int $navigationSort = 99;

    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';

    private const SECRETS = ['gemini_api_key', 'telegram_bot_token', 'finance_bot_token'];

    /**
     * Kolom token per bot: 'main' = bot pengajuan, 'finance' = bot laporan.
     */
    private const BOT_TOKENS = ['main' => 'telegram_bot_token', 'finance' => 'finance_bot_token'];

    /**
     * Per bot: hasil TelegramWebhookManager::status(), ['error' => pesan], atau null.
     *
     * @var array<string, array|null>
     */
    public array $botStatus = ['main' => null, 'finance' => null];

    /** @var array<string, ?string> */
    private array $previousTokens = [];

    public static function getNavigationLabel(): string
    {
        return \App\Helpers\NavigationHelper::getLabel('integration_settings', 'Integrasi');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('menu.nav_group.settings');
    }

    public function getTitle(): string|Htmlable
    {
        return 'Integrasi';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Kunci API untuk Gemini AI dan bot Telegram. Semua kunci disimpan terenkripsi.';
    }

    public function mount(): void
    {
        parent::mount();

        $this->loadStatus('main');
        $this->loadStatus('finance');
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Gemini AI')
                    ->description('Membaca pesan bebas di bot Telegram dan menyusun analisis keuangan.')
                    ->icon('heroicon-o-sparkles')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('gemini_api_key')
                            ->label('API key')
                            ->password()
                            ->autocomplete('off')
                            ->placeholder(fn () => $this->secretPlaceholder(IntegrationSettings::geminiApiKey()))
                            ->helperText('Dari aistudio.google.com → API keys. Kosongkan bila tidak ingin mengganti.'),
                        Forms\Components\TextInput::make('gemini_model')
                            ->label('Model')
                            ->required()
                            ->datalist(['gemini-3.5-flash-lite', 'gemini-3.5-flash', 'gemini-3.8-flash'])
                            ->helperText('gemini-3.5-flash-lite paling hemat dan cukup untuk bot.'),
                        Forms\Components\Actions::make([
                            Action::make('testGemini')
                                ->label('Tes koneksi')
                                ->icon('heroicon-o-signal')
                                ->color('gray')
                                ->action(fn (Get $get) => $this->testGemini($get('gemini_api_key'), $get('gemini_model'))),
                        ])->columnSpanFull(),
                    ]),

                $this->botSection(
                    'main',
                    'Bot Telegram — pengajuan',
                    'Karyawan mengajukan expense, advance, dan realisasi lewat chat. Masing-masing menghubungkan akunnya dari halaman Profil.',
                    'heroicon-o-chat-bubble-left-right',
                    IntegrationSettings::telegramBotToken(),
                ),

                $this->botSection(
                    'finance',
                    'Bot Telegram — laporan keuangan',
                    'Bot terpisah, hanya membaca: ringkasan advance, expense, dan invoice untuk Director, Finance, dan super_admin. Akun yang sudah terhubung di bot pengajuan otomatis dikenali.',
                    'heroicon-o-chart-bar',
                    IntegrationSettings::financeBotToken(),
                ),
            ])
            ->statePath('data');
    }

    private function botSection(string $bot, string $title, string $description, string $icon, ?string $token): Forms\Components\Section
    {
        return Forms\Components\Section::make($title)
            ->description($description)
            ->icon($icon)
            ->schema([
                Forms\Components\TextInput::make(self::BOT_TOKENS[$bot])
                    ->label('Token bot')
                    ->password()
                    ->autocomplete('off')
                    ->placeholder(fn () => $this->secretPlaceholder($token))
                    ->helperText('Dari @BotFather' . ($bot === 'finance' ? ', bot yang berbeda dari bot pengajuan' : '') . '. Setelah token baru disimpan, webhook langsung didaftarkan.'),
                Forms\Components\Placeholder::make("{$bot}_status")
                    ->label('Status')
                    ->content(fn () => $this->statusView($bot))
                    ->visible(fn () => $this->botStatus[$bot] !== null),
                Forms\Components\Actions::make([
                    Action::make("{$bot}Check")
                        ->label('Cek status')
                        ->icon('heroicon-o-arrow-path')
                        ->color('gray')
                        ->action(fn () => $this->loadStatus($bot)),
                    Action::make("{$bot}Register")
                        ->label('Daftarkan ulang webhook')
                        ->icon('heroicon-o-link')
                        ->color('gray')
                        ->action(fn () => $this->registerWebhook($bot)),
                    Action::make("{$bot}Remove")
                        ->label('Matikan bot')
                        ->icon('heroicon-o-power')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('Bot berhenti menerima pesan sampai webhook didaftarkan lagi. Token tetap tersimpan.')
                        ->action(fn () => $this->removeWebhook($bot)),
                ]),
            ]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return Arr::except($data, [...self::SECRETS, 'telegram_webhook_secret', 'finance_webhook_secret']);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Isian rahasia yang dibiarkan kosong berarti "tidak diubah".
        foreach (self::SECRETS as $field) {
            if (blank($data[$field] ?? null)) {
                unset($data[$field]);
            }
        }

        return $data;
    }

    protected function beforeSave(): void
    {
        foreach (array_keys(self::BOT_TOKENS) as $bot) {
            $this->previousTokens[$bot] = $this->client($bot)->token();
        }
    }

    protected function afterSave(): void
    {
        foreach (self::SECRETS as $field) {
            $this->data[$field] = null;
        }

        foreach (array_keys(self::BOT_TOKENS) as $bot) {
            if ($this->client($bot)->token() !== ($this->previousTokens[$bot] ?? null)) {
                $this->client($bot)->forgetBotUsername();
                $this->registerWebhook($bot);
            }
        }
    }

    private function client(string $bot): TelegramClient
    {
        return $bot === 'finance' ? TelegramClient::forFinance() : app(TelegramClient::class);
    }

    private function manager(string $bot): TelegramWebhookManager
    {
        return $bot === 'finance' ? TelegramWebhookManager::forFinance() : app(TelegramWebhookManager::class);
    }

    private function testGemini(?string $key, ?string $model): void
    {
        $error = app(GeminiService::class)->testConnection($key, $model);

        if ($error === null) {
            Notification::make()
                ->success()
                ->title('Gemini siap dipakai')
                ->body('Model ' . ($model ?: IntegrationSettings::geminiModel()) . ' merespons.')
                ->send();

            return;
        }

        Notification::make()->danger()->title('Gemini belum bisa dipakai')->body($error)->persistent()->send();
    }

    private function registerWebhook(string $bot): void
    {
        $name = $bot === 'finance' ? 'Bot laporan keuangan' : 'Bot pengajuan';

        try {
            $url = $this->manager($bot)->register();

            Notification::make()->success()->title("{$name} aktif")->body("Webhook terdaftar di {$url}")->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title("Webhook {$name} belum terdaftar")->body($e->getMessage())->persistent()->send();
        }

        $this->loadStatus($bot);
    }

    private function removeWebhook(string $bot): void
    {
        try {
            $this->manager($bot)->remove();

            Notification::make()->success()->title('Bot dimatikan')->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Gagal mematikan bot')->body($e->getMessage())->send();
        }

        $this->loadStatus($bot);
    }

    private function loadStatus(string $bot): void
    {
        if (! $this->client($bot)->isConfigured()) {
            $this->botStatus[$bot] = null;

            return;
        }

        try {
            $this->botStatus[$bot] = $this->manager($bot)->status();
        } catch (RuntimeException $e) {
            $this->botStatus[$bot] = ['error' => $e->getMessage()];
        }
    }

    private function statusView(string $bot): HtmlString
    {
        $status = $this->botStatus[$bot];

        if (isset($status['error'])) {
            return new HtmlString('<span class="text-danger-600 dark:text-danger-400">' . e($status['error']) . '</span>');
        }

        $rows = [
            'Bot'             => e($status['bot']),
            'Webhook'         => $status['active'] ? '✅ Aktif' : '⚠️ Belum terdaftar ke server ini. Tekan <b>Daftarkan ulang webhook</b>.',
            'Update tertunda' => (int) $status['pending'],
            'Error terakhir'  => e($status['last_error'] ?? '-'),
        ];

        return new HtmlString(collect($rows)
            ->map(fn ($value, $label) => "<div><span class=\"text-gray-500 dark:text-gray-400\">{$label}:</span> {$value}</div>")
            ->implode(''));
    }

    private function secretPlaceholder(?string $value): string
    {
        return filled($value) ? 'Tersimpan (…' . substr($value, -4) . '). Isi untuk mengganti.' : 'Belum diisi';
    }
}
