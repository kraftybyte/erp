<?php

namespace App\Helpers;

use Filament\Support\Colors\Color;

/**
 * Palet warna tema (Settings → site_theme) yang tetap terbaca.
 *
 * Filament memakai shade 600 sebagai warna teks badge dan link di atas latar
 * terang, dan sebagai latar tombol berteks putih. Dari warna dasar yang muda,
 * mis. warning #f5de8d, shade 600-nya hanya 1,6:1 terhadap putih sehingga badge
 * "Pending" nyaris tak terbaca. Shade 600 ke atas digelapkan sampai minimal
 * 4,5:1 (WCAG AA); warna dasar (500) dan shade yang lebih muda tidak diubah.
 */
class ThemePalette
{
    private const MIN_CONTRAST = 4.5;

    private const DARK_SHADES = [600, 700, 800, 900, 950];

    /**
     * @param  array<string, mixed>  $theme  nama => hex, mis. ['warning' => '#f5de8d']
     * @return array<string, mixed>
     */
    public static function readable(array $theme): array
    {
        return collect($theme)
            ->map(fn ($color) => is_string($color) && preg_match('/^#?[0-9a-f]{6}$/i', $color)
                ? self::palette('#' . ltrim($color, '#'))
                : $color)
            ->all();
    }

    /**
     * @return array<int, string> shade => "r, g, b"
     */
    public static function palette(string $hex): array
    {
        $palette = Color::hex($hex);
        // Badge memakai latar shade 50, sedikit lebih gelap dari putih; lolos
        // terhadap shade 50 berarti lolos juga untuk teks putih di tombol.
        $badgeBg = self::rgb($palette[50]);
        $prev    = null;

        foreach (self::DARK_SHADES as $shade) {
            $rgb = self::rgb($palette[$shade]);

            if ($shade === 600) {
                while (self::contrast($rgb, $badgeBg) < self::MIN_CONTRAST) {
                    $rgb = self::darken($rgb, 0.04);
                }
            } elseif (self::luminance($rgb) > self::luminance($prev) * 0.8) {
                // Shade berikutnya tetap lebih gelap dari 600 yang sudah digelapkan.
                $rgb = self::darken($prev, 0.18);
            }

            $palette[$shade] = implode(', ', $rgb);
            $prev            = $rgb;
        }

        return $palette;
    }

    /** @param array<int, int> $a  @param array<int, int> $b */
    public static function contrast(array $a, array $b): float
    {
        [$light, $dark] = [max(self::luminance($a), self::luminance($b)), min(self::luminance($a), self::luminance($b))];

        return ($light + 0.05) / ($dark + 0.05);
    }

    /** @param array<int, int> $rgb */
    private static function luminance(array $rgb): float
    {
        [$r, $g, $b] = array_map(function (int $channel) {
            $c = $channel / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /**
     * @param  array<int, int>  $rgb
     * @return array<int, int>
     */
    private static function darken(array $rgb, float $amount): array
    {
        return array_map(fn (int $channel) => (int) round($channel * (1 - $amount)), $rgb);
    }

    /** @return array<int, int> */
    private static function rgb(string $value): array
    {
        return array_map('intval', explode(',', $value));
    }
}
