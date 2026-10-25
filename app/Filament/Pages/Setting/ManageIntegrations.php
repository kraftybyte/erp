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
 * Settings → Integrasi: kunci Gemini dan bot Telegram.
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

    private const SECRETS = ['gemini_api_key', 'telegram_bot_token'];

    /**
     * Hasil TelegramWebhookManager::status(), atau ['error' => pesan].
     */
    public ?array $telegramStatus = null;

    private ?string $previousToken = null;

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

        $this->loadTelegramStatus();
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

                Forms\Components\Section::make('Bot Telegram')
                    ->description('Karyawan mengajukan expense & advance lewat chat. Masing-masing menghubungkan akunnya dari halaman Profil.')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->schema([
                        Forms\Components\TextInput::make('telegram_bot_token')
                            ->label('Token bot')
                            ->password()
                            ->autocomplete('off')
                            ->placeholder(fn () => $this->secretPlaceholder(IntegrationSettings::telegramBotToken()))
                            ->helperText('Dari @BotFather. Setelah token baru disimpan, webhook langsung didaftarkan.'),
                        Forms\Components\Placeholder::make('telegram_status')
                            ->label('Status')
                            ->content(fn () => $this->telegramStatusView())
                            ->visible(fn () => $this->telegramStatus !== null),
                        Forms\Components\Actions::make([
                            Action::make('checkTelegram')
                                ->label('Cek status')
                                ->icon('heroicon-o-arrow-path')
                                ->color('gray')
                                ->action(fn () => $this->loadTelegramStatus()),
                            Action::make('registerWebhook')
                                ->label('Daftarkan ulang webhook')
                                ->icon('heroicon-o-link')
                                ->color('gray')
                                ->action(fn () => $this->registerWebhook()),
                            Action::make('removeWebhook')
                                ->label('Matikan bot')
                                ->icon('heroicon-o-power')
                                ->color('danger')
                                ->requiresConfirmation()
                                ->modalDescription('Bot berhenti menerima pesan sampai webhook didaftarkan lagi. Token tetap tersimpan.')
                                ->action(fn () => $this->removeWebhook()),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return Arr::except($data, [...self::SECRETS, 'telegram_webhook_secret']);
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
        $this->previousToken = IntegrationSettings::telegramBotToken();
    }

    protected function afterSave(): void
    {
        foreach (self::SECRETS as $field) {
            $this->data[$field] = null;
        }

        if (IntegrationSettings::telegramBotToken() !== $this->previousToken) {
            app(TelegramClient::class)->forgetBotUsername();
            $this->registerWebhook();
        }
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

    private function registerWebhook(): void
    {
        try {
            $url = app(TelegramWebhookManager::class)->register();

            Notification::make()->success()->title('Bot Telegram aktif')->body("Webhook terdaftar di {$url}")->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Webhook belum terdaftar')->body($e->getMessage())->persistent()->send();
        }

        $this->loadTelegramStatus();
    }

    private function removeWebhook(): void
    {
        try {
            app(TelegramWebhookManager::class)->remove();

            Notification::make()->success()->title('Bot dimatikan')->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Gagal mematikan bot')->body($e->getMessage())->send();
        }

        $this->loadTelegramStatus();
    }

    private function loadTelegramStatus(): void
    {
        if (! app(TelegramClient::class)->isConfigured()) {
            $this->telegramStatus = null;

            return;
        }

        try {
            $this->telegramStatus = app(TelegramWebhookManager::class)->status();
        } catch (RuntimeException $e) {
            $this->telegramStatus = ['error' => $e->getMessage()];
        }
    }

    private function telegramStatusView(): HtmlString
    {
        $status = $this->telegramStatus;

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
