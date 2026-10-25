<?php

namespace App\Services\Telegram;

use App\Settings\IntegrationSettings;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Mendaftarkan, memeriksa, dan mencabut webhook bot. Dipakai halaman
 * Settings → Integrasi dan perintah `php artisan telegram:webhook`.
 *
 * Setiap method melempar RuntimeException berisi pesan yang siap ditampilkan
 * ke admin.
 */
class TelegramWebhookManager
{
    public const COMMANDS = [
        ['command' => 'expense', 'description' => 'Ajukan expense (reimburse)'],
        ['command' => 'advance', 'description' => 'Ajukan advance (uang muka)'],
        ['command' => 'realisasi', 'description' => 'Laporkan pemakaian advance'],
        ['command' => 'status',  'description' => 'Pengajuan terakhir & progres advance'],
        ['command' => 'batal',   'description' => 'Batalkan draft'],
        ['command' => 'bantuan', 'description' => 'Cara pakai'],
    ];

    public function __construct(private TelegramClient $telegram) {}

    /**
     * @return string URL webhook yang didaftarkan
     */
    public function register(): string
    {
        $this->ensureConfigured();

        $url = $this->webhookUrl();

        if (! str_starts_with($url, 'https://')) {
            throw new RuntimeException("Telegram hanya mau mengirim ke alamat HTTPS publik, sedangkan APP_URL sekarang {$url}.");
        }

        // Secret baru setiap pendaftaran, dan baru disimpan setelah Telegram
        // menerimanya, supaya keduanya tidak pernah berbeda.
        $secret = Str::random(64);

        $ok = $this->telegram->call('setWebhook', [
            'url'                  => $url,
            'secret_token'         => $secret,
            'allowed_updates'      => ['message', 'callback_query'],
            'drop_pending_updates' => true,
        ]);

        if (! $ok) {
            throw new RuntimeException('Telegram menolak pendaftaran webhook. Pastikan token bot benar; detailnya ada di storage/logs/laravel.log.');
        }

        $settings = app(IntegrationSettings::class);
        $settings->telegram_webhook_secret = $secret;
        $settings->save();

        // Menu perintah di Telegram, supaya tidak perlu diatur manual di BotFather.
        $this->telegram->call('setMyCommands', ['commands' => self::COMMANDS]);
        $this->telegram->forgetBotUsername();

        return $url;
    }

    /**
     * @return array{bot: string, webhook: ?string, active: bool, pending: int, last_error: ?string}
     */
    public function status(): array
    {
        $this->ensureConfigured();

        $me   = $this->telegram->call('getMe');
        $info = $this->telegram->call('getWebhookInfo');

        if (! $me || ! $info) {
            throw new RuntimeException('Tidak bisa menghubungi Telegram. Pastikan token bot benar.');
        }

        return [
            'bot'        => '@' . $me['username'],
            'webhook'    => ($info['url'] ?? '') ?: null,
            'active'     => ($info['url'] ?? '') === $this->webhookUrl(),
            'pending'    => (int) ($info['pending_update_count'] ?? 0),
            'last_error' => isset($info['last_error_date'])
                ? date('Y-m-d H:i', $info['last_error_date']) . ' — ' . ($info['last_error_message'] ?? '')
                : null,
        ];
    }

    public function remove(): void
    {
        $this->ensureConfigured();

        if (! $this->telegram->call('deleteWebhook')) {
            throw new RuntimeException('Gagal mencabut webhook. Detailnya ada di storage/logs/laravel.log.');
        }
    }

    /**
     * Dari APP_URL, bukan dari request yang sedang berjalan: admin bisa saja
     * membuka Settings lewat alamat lain (www, IP) dan webhook ikut ke sana.
     */
    public function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/') . route('telegram.webhook', absolute: false);
    }

    private function ensureConfigured(): void
    {
        if (! $this->telegram->isConfigured()) {
            throw new RuntimeException('Token bot Telegram belum diisi di Settings → Integrasi.');
        }
    }
}
