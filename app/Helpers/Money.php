<?php

namespace App\Helpers;

class Money
{
    /**
     * Nilai awal untuk input angka (->numeric(), type=number): tanpa pemisah
     * ribuan dan tanpa nol desimal di belakang, mis. "780490.9" atau "5000000".
     *
     * Jangan tampilkan "780.491" di input angka: browser dan validator
     * membacanya sebagai 780,491, sehingga nominal tersimpan seperseribu.
     */
    public static function inputValue(mixed $amount): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return rtrim(rtrim(number_format((float) $amount, 2, '.', ''), '0'), '.');
    }

    /** "Rp 1.250.000"; tanda minus dibuang, labelnya yang menjelaskan arah. */
    public static function rupiah(mixed $amount): string
    {
        return 'Rp ' . number_format(abs((float) $amount), 0, ',', '.');
    }

    /** Jumlah barang: 2.00 → "2", 1.50 → "1,5". */
    public static function qty(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
    }
}
