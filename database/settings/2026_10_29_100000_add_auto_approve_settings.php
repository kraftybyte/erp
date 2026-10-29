<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Karyawan yang pengajuan expense & advance-nya langsung Disetujui.
        if (! $this->migrator->exists('general.auto_approve_user_ids')) {
            $this->migrator->add('general.auto_approve_user_ids', []);
        }
    }
};
