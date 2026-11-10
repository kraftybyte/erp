<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Nilai awal diambil dari .env (lewat config, supaya tetap terbaca saat
        // config:cache aktif), sehingga server yang sudah memakai .env langsung
        // tampil terisi di halaman Settings → Integrasi.
        // Hanya yang belum ada (database lama bisa sudah punya sebagian).
        foreach ([
            'integrations.gemini_api_key'          => [config('services.gemini.key'), true],
            'integrations.gemini_model'            => [config('services.gemini.model'), false],
            'integrations.telegram_bot_token'      => [config('services.telegram.bot_token'), true],
            'integrations.telegram_webhook_secret' => [config('services.telegram.webhook_secret'), true],
        ] as $name => [$value, $encrypted]) {
            if (! $this->migrator->exists($name)) {
                $encrypted ? $this->migrator->addEncrypted($name, $value) : $this->migrator->add($name, $value);
            }
        }
    }
};
