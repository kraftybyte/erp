<?php

namespace App\Settings;

use Spatie\LaravelSettings\Exceptions\MissingSettings;
use Spatie\LaravelSettings\Settings;

/**
 * Kunci layanan luar (Gemini, bot Telegram), diatur dari Settings → Integrasi.
 *
 * Isian kosong jatuh ke .env lewat config/services.php, supaya server yang
 * masih memakai .env tetap jalan. Selalu baca lewat helper statis di bawah,
 * bukan propertinya langsung, agar aturan cadangan itu berlaku di semua tempat.
 */
class IntegrationSettings extends Settings
{
    public const DEFAULT_GEMINI_MODEL = 'gemini-3.5-flash-lite';

    public ?string $gemini_api_key;

    public ?string $gemini_model;

    public ?string $telegram_bot_token;

    // Dibuat otomatis setiap webhook didaftarkan; tidak tampil di form.
    public ?string $telegram_webhook_secret;

    public static function group(): string
    {
        return 'integrations';
    }

    public static function encrypted(): array
    {
        return ['gemini_api_key', 'telegram_bot_token', 'telegram_webhook_secret'];
    }

    public static function geminiApiKey(): ?string
    {
        return self::resolve('gemini_api_key', 'services.gemini.key');
    }

    public static function geminiModel(): string
    {
        return self::resolve('gemini_model', 'services.gemini.model') ?: self::DEFAULT_GEMINI_MODEL;
    }

    public static function telegramBotToken(): ?string
    {
        return self::resolve('telegram_bot_token', 'services.telegram.bot_token');
    }

    public static function telegramWebhookSecret(): ?string
    {
        return self::resolve('telegram_webhook_secret', 'services.telegram.webhook_secret');
    }

    private static function resolve(string $property, string $configKey): ?string
    {
        try {
            $value = app(self::class)->{$property};
        } catch (MissingSettings) {
            // Settings migration belum dijalankan, mis. tepat setelah deploy.
            $value = null;
        }

        return filled($value) ? $value : config($configKey);
    }
}
