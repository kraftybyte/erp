<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('integrations.finance_bot_token')) {
            $this->migrator->addEncrypted('integrations.finance_bot_token', config('services.telegram.finance_bot_token'));
        }

        if (! $this->migrator->exists('integrations.finance_webhook_secret')) {
            $this->migrator->addEncrypted('integrations.finance_webhook_secret', config('services.telegram.finance_webhook_secret'));
        }
    }
};
