<?php

namespace App\Services\Telegram;

/**
 * Teks pendek untuk pesan dan tombol bot Telegram.
 */
class Text
{
    /**
     * Dipotong di batas kata, bukan di tengah kata: "Signage & Wayfinding"
     * menjadi "Signage…", bukan "Signage & Wayf...".
     */
    public static function short(?string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut   = mb_substr($text, 0, $max);
        $space = mb_strrpos($cut, ' ');

        // Satu kata yang sangat panjang tetap dipotong supaya tidak melebihi batas.
        if ($space !== false && $space >= intdiv($max, 2)) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " ,.;:-–|·&/") . '…';
    }
}
