<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Slip gaji bulanan (Settings → General → Gaji).
        $defaults = [
            'payroll_auto_generate'   => false,
            'payroll_generate_day'    => 25,
            'payroll_review'          => true,
            'payroll_send_telegram'   => true,
            'payroll_signature_name'  => null,
            'payroll_signature_title' => 'HRD',
            'payroll_slip_note'       => 'Slip gaji ini dibuat oleh sistem dan bersifat rahasia.',
        ];

        foreach ($defaults as $key => $value) {
            if (! $this->migrator->exists("general.{$key}")) {
                $this->migrator->add("general.{$key}", $value);
            }
        }
    }
};
