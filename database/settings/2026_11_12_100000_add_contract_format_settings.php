<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Settings → General → Contract Template: format isi kontrak baru, format
 * nomor kontrak, kota penandatanganan, dan dasar kewenangan Pihak Pertama.
 * Bawaannya sama dengan perilaku selama ini (format standar, nomor 001/SPK/0726).
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $defaults = [
            'contract_default_format'     => 'standar',
            'contract_number_format'      => '{no}/SPK/{bulan}{tahun2}',
            'contract_city'               => '',
            'contract_agency_legal_basis' => '',
        ];

        foreach ($defaults as $key => $value) {
            if (! $this->migrator->exists("general.{$key}")) {
                $this->migrator->add("general.{$key}", $value);
            }
        }
    }
};
