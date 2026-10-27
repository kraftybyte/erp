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
}
