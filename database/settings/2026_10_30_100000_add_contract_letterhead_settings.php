<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Kop dan kaki surat default untuk semua kontrak; kosong = storage/sites/header.png dan footer.png.
        foreach (['general.contract_header', 'general.contract_footer'] as $name) {
            if (! $this->migrator->exists($name)) {
                $this->migrator->add($name, null);
            }
        }
    }
};
