<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Prefix nomor invoice dan status situs di Settings → General dulu tidak
 * dibaca kode mana pun: invoice selalu bernomor #SC/#PI dan situs selalu
 * aktif. Kini keduanya berlaku, jadi nilainya disamakan dulu dengan perilaku
 * selama ini supaya tidak ada yang berubah saat deploy. Nilai lama (mis.
 * bawaan "INV") tidak pernah dipakai.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $values = [
            'invoice_prefix'  => 'SC',
            'proforma_prefix' => 'PI',
            'site_active'     => 1,
        ];

        foreach ($values as $key => $value) {
            $this->migrator->exists("general.{$key}")
                ? $this->migrator->update("general.{$key}", fn () => $value)
                : $this->migrator->add("general.{$key}", $value);
        }
    }
};
