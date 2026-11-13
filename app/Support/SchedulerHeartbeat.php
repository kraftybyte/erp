<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * Tanda bahwa cron `schedule:run` berjalan. Tanpa cron, ringkasan pagi,
 * pengingat, antrean Finance, laporan mingguan, dan slip gaji otomatis tidak
 * pernah terkirim, dan tidak ada yang tahu. Disimpan sebagai waktu ubah file
 * (bukan cache) supaya tidak hilang oleh cache:clear saat deploy.
 */
final class SchedulerHeartbeat
{
    /** Lebih lama dari ini tanpa jalan: jadwal dianggap berhenti. */
    public const STALE_MINUTES = 15;

    public static function beat(): void
    {
        @touch(self::path());
    }

    public static function lastRun(): ?Carbon
    {
        clearstatcache(true, self::path());

        return is_file(self::path()) ? Carbon::createFromTimestamp(filemtime(self::path()), config('app.timezone')) : null;
    }

    public static function isRunning(): bool
    {
        return (bool) self::lastRun()?->gt(now()->subMinutes(self::STALE_MINUTES));
    }

    /** Baris cron yang perlu dipasang di server (tiap menit). */
    public static function cronLine(): string
    {
        return '* * * * * bash ' . base_path('scripts/schedule-run.sh') . ' >/dev/null 2>&1';
    }

    public static function status(): string
    {
        $last = self::lastRun();

        return match (true) {
            $last === null     => 'Belum pernah berjalan di server ini.',
            self::isRunning()  => 'Berjalan, terakhir ' . $last->diffForHumans() . '.',
            default            => 'Berhenti sejak ' . $last->translatedFormat('j M Y H:i') . ' (' . $last->diffForHumans() . ').',
        };
    }

    /**
     * Peringatan di atas setiap halaman untuk yang boleh membuka Settings →
     * Integrasi, bila jadwal tidak berjalan (tidak di laptop/tes, yang memang tanpa cron).
     */
    public static function warning(): ?HtmlString
    {
        if (app()->environment('local', 'testing') || self::isRunning() || ! auth()->user()?->can('page_ManageIntegrations')) {
            return null;
        }

        return new HtmlString('<div style="margin-bottom:1rem;padding:.75rem 1rem;border-radius:.5rem;background:#fef3c7;color:#78350f;font-size:.875rem;line-height:1.4">'
            . '<strong>Jadwal otomatis tidak berjalan.</strong> ' . e(self::status())
            . ' Ringkasan pagi, pengingat, antrean Finance, dan slip gaji otomatis tidak terkirim. '
            . 'Pasang cron tiap menit di server: <code style="word-break:break-all">' . e(self::cronLine()) . '</code>'
            . '</div>');
    }

    private static function path(): string
    {
        return storage_path('framework/scheduler-heartbeat');
    }
}
