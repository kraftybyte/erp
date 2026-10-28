<?php

namespace App\Helpers;

use App\Models\Invoice;
use App\Models\Project;

/**
 * Nama klien di bot Telegram dan laporannya: "PIC - Perusahaan", misalnya
 * "Budi Santoso - PT Nestory Indonesia". Bagian yang kosong dilewati.
 */
class ClientLabel
{
    public static function of(?string $pic, ?string $company): string
    {
        $pic     = self::clean($pic);
        $company = self::clean($company);

        // Klien perorangan kadang diisi nama yang sama di kedua kolom.
        if ($pic !== null && $company !== null && strcasecmp($pic, $company) === 0) {
            $pic = null;
        }

        return implode(' - ', array_filter([$pic, $company])) ?: '-';
    }

    public static function invoice(Invoice $invoice): string
    {
        return self::of($invoice->nama_pic, $invoice->nama_perusahaan);
    }

    /**
     * "PIC - Perusahaan | Nama project". PIC dan perusahaan diambil dari project,
     * atau dari client (deal)-nya bila di project kosong.
     */
    public static function project(?Project $project): string
    {
        if (! $project) {
            return 'Tanpa project';
        }

        $client = self::of(
            $project->nama_pic ?: $project->deal?->nama_pic,
            $project->nama_perusahaan ?: $project->deal?->nama_perusahaan,
        );

        return implode(' | ', array_filter([$client === '-' ? null : $client, self::clean($project->nama_project)]))
            ?: "Project #{$project->id}";
    }

    /**
     * Spasi dan "-" dianggap kosong (form lama mengisi "-" bila tidak ada).
     */
    private static function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || $value === '-' ? null : $value;
    }
}
