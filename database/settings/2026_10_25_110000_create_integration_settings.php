<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Nilai awal diambil dari .env (lewat config, supaya tetap terbaca saat
        // config:cache aktif), sehingga server yang sudah memakai .env langsung
        // tampil terisi di halaman Settings → Integrasi.
        $this->migrator->addEncrypted('integrations.gemini_api_key', config('services.gemini.key'));
        $this->migrator->add('integrations.gemini_model', config('services.gemini.model'));
        $this->migrator->addEncrypted('integrations.telegram_bot_token', config('services.telegram.bot_token'));
        $this->migrator->addEncrypted('integrations.telegram_webhook_secret', config('services.telegram.webhook_secret'));
    }
};
