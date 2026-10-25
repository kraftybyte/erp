<?php

namespace App\Services\Telegram;

/**
 * Membaca nominal rupiah dari teks bebas: "150rb", "1,5jt", "Rp 150.000",
 * "2 juta", "85000". Dipakai saat AI tidak tersedia dan saat bot menanyakan
 * nominal secara langsung.
 */
final class AmountParser
{
    // Batas kolom amount decimal(15,2).
    public const MAX = 9_999_999_999_999;

    private const PATTERN = '/(?<![\p{L}\d])(?<rp>rp\.?\s*)?(?<num>\d{1,3}(?:[.,]\d{3})+(?:,\d{1,2})?|\d+(?:[.,]\d+)?)\s*(?<unit>rb|ribu|k|jt|juta)?(?![\p{L}\d])/iu';

    private const MULTIPLIERS = [
        'rb'   => 1_000,
        'ribu' => 1_000,
        'k'    => 1_000,
        'jt'   => 1_000_000,
        'juta' => 1_000_000,
    ];

    public static function parse(string $text): ?int
    {
        $candidates = self::candidates($text);

        // Angka bersatuan atau berawalan "Rp" jelas sebuah nominal. Angka polos
        // bisa saja jumlah orang, tanggal, atau tahun, jadi hanya dipakai bila
        // >= 1.000 dan bukan tahun.
        $explicit = array_values(array_filter($candidates, fn ($c) => $c['explicit']));
        $plain    = array_filter($candidates, fn ($c) => ! $c['explicit'] && $c['value'] >= 1_000 && ! $c['year']);

        $amount = $explicit
            ? $explicit[0]['value']
            : max([0, ...array_column($plain, 'value')]);

        return $amount > 0 && $amount <= self::MAX ? $amount : null;
    }

    /**
     * Hapus ungkapan nominal dari teks, untuk menyisakan keterangannya.
     */
    public static function strip(string $text): string
    {
        return preg_replace(self::PATTERN, ' ', $text);
    }

    /**
     * @return array<int, array{value: int, explicit: bool, year: bool}>
     */
    private static function candidates(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches, PREG_SET_ORDER);

        return array_map(function (array $match) {
            $unit = strtolower($match['unit'] ?? '');

            return [
                'value'    => (int) round(self::number($match['num']) * (self::MULTIPLIERS[$unit] ?? 1)),
                'explicit' => $unit !== '' || trim($match['rp'] ?? '') !== '',
                'year'     => (bool) preg_match('/^(19|20)\d{2}$/', $match['num']),
            ];
        }, $matches);
    }

    private static function number(string $num): float
    {
        // Satu pemisah diikuti 1–2 digit adalah desimal: 1,5 jt · 2.5 juta.
        if (preg_match('/^(\d+)[.,](\d{1,2})$/', $num, $m)) {
            return (float) "{$m[1]}.{$m[2]}";
        }

        // Selain itu pemisah ribuan; buang dulu sen di belakang: 1.250.000,00.
        $num = preg_replace('/[.,]\d{1,2}$/', '', $num);

        return (float) str_replace(['.', ','], '', $num);
    }
}
