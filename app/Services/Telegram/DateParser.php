<?php

namespace App\Services\Telegram;

use Illuminate\Support\Carbon;

/**
 * Membaca tanggal yang diketik user saat mengubah tanggal pengajuan:
 * "3/11", "03/11/2026", "3 Nov", "3 november 2026", "2026-11-03", "3",
 * "hari ini", "kemarin", "2 hari lalu". Angka dibaca hari/bulan, seperti
 * kebiasaan menulis di Indonesia. Tanpa tahun, bulan yang belum datang
 * berarti tahun lalu ("28/12" di bulan Januari). Hasilnya bisa di masa
 * depan ("10/11" pada 3 November); bot yang menolaknya, bukan diam-diam
 * menggesernya ke tahun lalu.
 */
final class DateParser
{
    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'mei' => 5, 'may' => 5,
        'jun' => 6, 'jul' => 7, 'agu' => 8, 'agt' => 8, 'aug' => 8, 'sep' => 9,
        'okt' => 10, 'oct' => 10, 'nov' => 11, 'des' => 12, 'dec' => 12,
    ];

    /**
     * @return ?string YYYY-MM-DD, bisa di masa depan bila tahunnya ditulis; null bila tidak terbaca
     */
    public static function parse(string $text, ?Carbon $today = null): ?string
    {
        $today = ($today ?? today())->copy()->startOfDay();
        $text  = mb_strtolower(trim($text));

        if (preg_match('/^(hari ini|today|sekarang)$/', $text)) {
            return $today->toDateString();
        }

        if (preg_match('/^kemarin$/', $text)) {
            return $today->subDay()->toDateString();
        }

        if (preg_match('/^(\d{1,2}) hari (yang )?(lalu|kemarin)$/', $text, $m)) {
            return $today->subDays((int) $m[1])->toDateString();
        }

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $text, $m)) {
            return self::date((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('/^(?:tgl\.?\s*|tanggal\s+)?(\d{1,2})$/', $text, $m)) {
            return self::dayOnly($today, (int) $m[1]);
        }

        if (preg_match('/^(?:tgl\.?\s*|tanggal\s+)?(\d{1,2})[\/.\-](\d{1,2})(?:[\/.\-](\d{4}|\d{2}))?$/', $text, $m)) {
            return isset($m[3])
                ? self::date(self::year($m[3]), (int) $m[2], (int) $m[1])
                : self::withoutYear($today, (int) $m[1], (int) $m[2]);
        }

        if (preg_match('/^(?:tgl\.?\s*|tanggal\s+)?(\d{1,2})\s+([a-z]{3,9})\.?(?:\s+(\d{4}|\d{2}))?$/', $text, $m)) {
            $month = self::MONTHS[substr($m[2], 0, 3)] ?? null;

            if (! $month) {
                return null;
            }

            return isset($m[3])
                ? self::date(self::year($m[3]), $month, (int) $m[1])
                : self::withoutYear($today, (int) $m[1], $month);
        }

        return null;
    }

    private static function withoutYear(Carbon $today, int $day, int $month): ?string
    {
        return self::date($month > $today->month ? $today->year - 1 : $today->year, $month, $day);
    }

    /**
     * Hanya tanggalnya: bulan ini, atau bulan lalu bila tanggal itu belum
     * datang ("28" pada 3 November = 28 Oktober).
     */
    private static function dayOnly(Carbon $today, int $day): ?string
    {
        $month = $day > $today->day ? $today->copy()->startOfMonth()->subMonth() : $today;

        return self::date($month->year, $month->month, $day);
    }

    private static function date(int $year, int $month, int $day): ?string
    {
        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    private static function year(string $year): int
    {
        return strlen($year) === 2 ? 2000 + (int) $year : (int) $year;
    }
}
