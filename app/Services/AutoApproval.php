<?php

namespace App\Services;

use App\Models\Advance;
use App\Settings\GeneralSettings;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelSettings\Exceptions\MissingSettings;

/**
 * Karyawan yang pengajuan expense & advance-nya langsung Disetujui, berapa pun
 * nominalnya (Settings → General → Persetujuan). Pembayarannya tetap dicatat
 * Finance seperti biasa.
 */
class AutoApproval
{
    /**
     * @return array<int, int>
     */
    public static function userIds(): array
    {
        try {
            $ids = app(GeneralSettings::class)->auto_approve_user_ids ?? [];
        } catch (MissingSettings) {
            return []; // Setting belum dimigrasi: semua pengajuan menunggu persetujuan.
        }

        return array_values(array_unique(array_map('intval', (array) $ids)));
    }

    public static function appliesTo(?int $userId): bool
    {
        return $userId !== null && in_array($userId, self::userIds(), true);
    }

    /**
     * Dipanggil saat expense/advance dibuat (ERP, bot Telegram, atau jalur lain):
     * pengajuan Pending dari karyawan di daftar langsung berstatus Disetujui.
     */
    public static function apply(Model $submission): void
    {
        if ($submission->status === Advance::STATUS_PENDING && self::appliesTo($submission->user_id)) {
            $submission->status = Advance::STATUS_DISETUJUI;
        }
    }

    public static function wasApplied(Model $submission): bool
    {
        return $submission->status === Advance::STATUS_DISETUJUI && self::appliesTo($submission->user_id);
    }
}
