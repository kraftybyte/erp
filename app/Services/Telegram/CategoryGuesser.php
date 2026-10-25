<?php

namespace App\Services\Telegram;

use Illuminate\Support\Str;

/**
 * Menebak kategori dari kata kunci di pesan: "grab", "bensin" → Transport,
 * "kuota" → Komunikasi, dan seterusnya. Dipakai tanpa AI, dan untuk mengisi
 * kategori yang tidak diisi AI.
 *
 * Hanya menjawab bila satu kategori jelas unggul; bila seri atau tidak ada
 * yang cocok, kategori ditanyakan ke user lewat tombol.
 */
final class CategoryGuesser
{
    private const KEYWORDS = [
        'Transport' => [
            'grab', 'gojek', 'gocar', 'goride', 'grabcar', 'grabbike', 'maxim', 'indrive', 'uber',
            'taksi', 'taxi', 'bluebird', 'ojek', 'ojol', 'bensin', 'bbm', 'pertalite', 'pertamax',
            'solar', 'tol', 'e-toll', 'etoll', 'parkir', 'kereta', 'krl', 'mrt', 'lrt', 'whoosh',
            'transjakarta', 'busway', 'bus', 'pesawat', 'tiket pesawat', 'damri', 'travel',
            'sewa mobil', 'rental mobil', 'transport', 'transportasi',
        ],
        'Komunikasi' => [
            'pulsa', 'kuota', 'paket data', 'internet', 'wifi', 'indihome', 'biznet',
            'telkomsel', 'telepon', 'telpon', 'komunikasi',
        ],
        'Software' => [
            'langganan', 'subscription', 'canva', 'adobe', 'figma', 'capcut', 'chatgpt', 'openai',
            'claude', 'zoom', 'gsuite', 'google workspace', 'notion', 'hosting', 'domain', 'vps',
            'lisensi', 'license', 'software', 'aplikasi', 'midjourney', 'envato', 'freepik',
        ],
        'Marketing' => [
            'iklan', 'ads', 'meta ads', 'fb ads', 'ig ads', 'google ads', 'tiktok ads', 'boost',
            'boosting', 'endorse', 'endorsement', 'kol', 'influencer', 'promosi', 'brosur',
            'banner', 'spanduk', 'baliho', 'flyer', 'giveaway', 'marketing',
        ],
        'Entertainment' => [
            'entertain', 'entertainment', 'jamuan', 'traktir', 'makan klien', 'makan bareng klien',
            'makan dengan klien', 'dinner klien', 'lunch klien', 'ngopi klien', 'kopi klien', 'karaoke',
        ],
        'Operasional Kantor' => [
            'atk', 'alat tulis', 'kertas', 'tinta', 'toner', 'galon', 'air mineral', 'kebersihan',
            'sabun', 'tisu', 'listrik', 'token listrik', 'pln', 'sewa kantor', 'perlengkapan kantor',
            'keperluan kantor', 'snack kantor', 'pantry',
        ],
        'Project Support' => [
            'properti', 'property', 'props', 'shooting', 'syuting', 'talent', 'crew', 'kru',
            'sewa alat', 'sewa kamera', 'kamera', 'lighting', 'wardrobe', 'makeup', 'mua',
            'dekorasi', 'lokasi shooting', 'konsumsi shooting',
        ],
    ];

    // Pesan antar makanan menyebut "grab"/"gojek" tapi bukan biaya transport.
    private const FOOD_DELIVERY = ['grab food', 'grabfood', 'gofood', 'go food', 'shopeefood', 'shopee food'];

    public static function guess(string $text): ?string
    {
        $haystack = self::normalize($text);

        foreach (self::FOOD_DELIVERY as $phrase) {
            if (str_contains($haystack, self::normalize($phrase))) {
                return null;
            }
        }

        $scores = [];
        foreach (self::KEYWORDS as $category => $keywords) {
            foreach ($keywords as $keyword) {
                $needle = self::normalize($keyword);

                if (str_contains($haystack, $needle)) {
                    // Frasa lebih dari satu kata lebih spesifik daripada satu kata.
                    $scores[$category] = ($scores[$category] ?? 0) + substr_count(trim($needle), ' ') + 1;
                }
            }
        }

        if (! $scores) {
            return null;
        }

        arsort($scores);
        $top = array_slice($scores, 0, 2, true);

        if (count($top) === 2 && reset($top) === next($top)) {
            return null;
        }

        return array_key_first($top);
    }

    /**
     * Huruf kecil, tanda baca jadi spasi, dan diapit spasi supaya pencocokan
     * selalu per kata utuh ("tol" tidak cocok dengan "toleransi").
     */
    private static function normalize(string $text): string
    {
        $words = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', Str::lower($text)));

        return " {$words} ";
    }
}
