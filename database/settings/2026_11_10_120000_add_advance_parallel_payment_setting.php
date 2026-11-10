<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Advance bayar paralel (petty cash): realisasi boleh sebelum dibayar.
        if (! $this->migrator->exists('general.advance_parallel_payment')) {
            $this->migrator->add('general.advance_parallel_payment', false);
        }
    }
};
