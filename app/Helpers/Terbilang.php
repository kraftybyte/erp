<?php

namespace App\Helpers;

/**
 * Angka bulat dalam kata bahasa Indonesia, untuk teks perjanjian:
 * 12 → "dua belas", 30 → "tiga puluh", 1500 → "seribu lima ratus".
 */
final class Terbilang
{
    private const UNITS = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];

    public static function make(int $number): string
    {
        if ($number === 0) {
            return 'nol';
        }

        return trim(preg_replace('/\s+/', ' ', ($number < 0 ? 'minus ' : '') . self::words(abs($number))));
    }

    /** "12 (dua belas)". */
    public static function withNumber(int $number): string
    {
        return $number . ' (' . self::make($number) . ')';
    }

    private static function words(int $n): string
    {
        return match (true) {
            $n < 12             => self::UNITS[$n],
            $n < 20             => self::words($n - 10) . ' belas',
            $n < 100            => self::words(intdiv($n, 10)) . ' puluh ' . self::words($n % 10),
            $n < 200            => 'seratus ' . self::words($n - 100),
            $n < 1000           => self::words(intdiv($n, 100)) . ' ratus ' . self::words($n % 100),
            $n < 2000           => 'seribu ' . self::words($n - 1000),
            $n < 1_000_000      => self::words(intdiv($n, 1000)) . ' ribu ' . self::words($n % 1000),
            $n < 1_000_000_000  => self::words(intdiv($n, 1_000_000)) . ' juta ' . self::words($n % 1_000_000),
            default             => self::words(intdiv($n, 1_000_000_000)) . ' miliar ' . self::words($n % 1_000_000_000),
        };
    }
}
