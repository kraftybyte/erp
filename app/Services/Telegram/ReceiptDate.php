<?php

namespace App\Services\Telegram;

use Illuminate\Support\Carbon;

/**
 * Tanggal di struk ditulis hari/bulan (umumnya di Indonesia) atau bulan/hari
 * (slip EDC dan ATM BCA, mesin kasir berformat Amerika), dan Gemini tidak
 * selalu menebak urutannya dengan benar. Gemini menyalin tanggalnya persis
 * seperti tercetak; urutannya diputuskan di sini.
 */
final class ReceiptDate
{
    /**
     * @param  ?string  $printed  tanggal seperti tercetak, mis. "11/03/26 14:22"
     * @param  ?string  $read     tanggal YYYY-MM-DD menurut Gemini
     * @return ?string  YYYY-MM-DD; tanggal masa depan disaring SubmissionParser
     */
    public static function resolve(?string $printed, ?string $read, ?Carbon $today = null): ?string
    {
        $candidates = self::candidates((string) $printed);

        // Tanggal Gemini bukan dari angka tercetak ini (mis. "kemarin" di pesan,
        // atau tanggal dengan nama bulan): dipakai apa adanya.
        if (! $candidates || ($read !== null && ! in_array($read, $candidates, true))) {
            return $read;
        }

        // Struk diajukan beberapa hari setelah transaksi, bukan berbulan-bulan:
        // dari tafsiran yang mungkin, dipilih yang terbaru dan tidak di masa depan.
        $todayString = ($today ?? today())->toDateString();
        $past        = array_filter($candidates, fn (string $date) => $date <= $todayString);

        return $past ? max($past) : $read;
    }

    /**
     * Tafsiran "a/b/yy" sebagai hari/bulan dan sebagai bulan/hari yang valid.
     *
     * @return array<int, string>
     */
    private static function candidates(string $printed): array
    {
        if (! preg_match('/(?<!\d)(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4}|\d{2})(?!\d)/', $printed, $match)) {
            return [];
        }

        $year  = strlen($match[3]) === 2 ? 2000 + (int) $match[3] : (int) $match[3];
        $dates = [];

        foreach ([[(int) $match[1], (int) $match[2]], [(int) $match[2], (int) $match[1]]] as [$day, $month]) {
            if (checkdate($month, $day, $year)) {
                $dates[] = sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        return array_values(array_unique($dates));
    }
}
