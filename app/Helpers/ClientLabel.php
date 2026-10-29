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

        return implode(' | ', array_filter(self::projectParts($project))) ?: "Project #{$project->id}";
    }

    /**
     * Klien ("PIC - Perusahaan") dan nama project secara terpisah, untuk pesan
     * yang menaruh keduanya di baris sendiri.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function projectParts(Project $project): array
    {
        $client = self::of(
            $project->nama_pic ?: $project->deal?->nama_pic,
            $project->nama_perusahaan ?: $project->deal?->nama_perusahaan,
        );

        return [$client === '-' ? null : $client, self::clean($project->nama_project)];
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
