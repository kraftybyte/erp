<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\DB;

/**
 * Mengenali struk yang sudah pernah diajukan tapi difoto ulang, sehingga
 * file_unique_id Telegram-nya berbeda dan penjagaan struk ganda lolos.
 *
 * Sinyalnya: nominal dan tanggal sama (dari Gemini), dikuatkan nama toko
 * atau kemiripan gambar (dHash); atau gambarnya hampir identik walau
 * nominalnya tidak terbaca. Hanya peringatan: dua struk parkir bernominal
 * sama di hari yang sama memang bisa terjadi.
 */
class ReceiptFingerprint
{
    private const DAYS = 180;

    // Jarak Hamming dHash: ≤ 5 hampir pasti gambar yang sama; ≤ 16 cukup untuk menguatkan.
    private const SAME_IMAGE = 5;

    private const SIMILAR_IMAGE = 16;

    /**
     * dHash 64-bit (hex) dari gambar; null untuk PDF atau gambar yang tidak terbaca.
     */
    public static function hash(string $data): ?string
    {
        if (! function_exists('imagecreatefromstring') || str_starts_with($data, '%PDF')) {
            return null;
        }

        $image = @imagecreatefromstring($data);

        if (! $image) {
            return null;
        }

        $small = imagecreatetruecolor(9, 8);
        imagecopyresampled($small, $image, 0, 0, 0, 0, 9, 8, imagesx($image), imagesy($image));
        imagedestroy($image);

        $bits = '';

        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $bits .= self::gray($small, $x, $y) > self::gray($small, $x + 1, $y) ? '1' : '0';
            }
        }

        imagedestroy($small);

        return str_pad(base_convert(substr($bits, 0, 32), 2, 16), 8, '0', STR_PAD_LEFT)
            . str_pad(base_convert(substr($bits, 32), 2, 16), 8, '0', STR_PAD_LEFT);
    }

    public static function distance(string $a, string $b): int
    {
        $distance = 0;

        foreach ([0, 8] as $offset) {
            $distance += substr_count(decbin(hexdec(substr($a, $offset, 8)) ^ hexdec(substr($b, $offset, 8))), '1');
        }

        return $distance;
    }

    /**
     * Keterangan struk yang sudah diajukan dan mirip dengan struk ini, atau null.
     */
    public static function similar(?float $amount, ?string $date, ?string $shop, ?string $phash, ?string $unique): ?string
    {
        $rows = DB::table('telegram_receipts')
            ->where('created_at', '>=', now()->subDays(self::DAYS))
            ->when($unique, fn ($query) => $query->where('file_unique_id', '!=', $unique))
            ->where(function ($query) use ($amount, $date, $phash) {
                $query->when($amount && $date, fn ($query) => $query->orWhere(fn ($query) => $query->whereBetween('amount', [$amount - 1, $amount + 1])->where('receipt_date', $date)))
                    ->when($phash, fn ($query) => $query->orWhereNotNull('phash'));
            })
            ->when(! ($amount && $date) && ! $phash, fn ($query) => $query->whereRaw('1 = 0'))
            ->orderByDesc('id')
            ->limit(3000)
            ->get();

        foreach ($rows as $row) {
            $sameFacts = $amount && $date && $row->amount !== null && abs((float) $row->amount - $amount) <= 1 && $row->receipt_date === $date;
            $image     = $phash && $row->phash ? self::distance($phash, $row->phash) : null;
            $shopMatch = $shop && $row->shop ? self::sameShop($shop, $row->shop) : null;

            $match = match (true) {
                $image !== null && $image <= self::SAME_IMAGE => true,
                $sameFacts                                   => $shopMatch !== false || ($image !== null && $image <= self::SIMILAR_IMAGE),
                default                                      => false,
            };

            if ($match) {
                return $row->reference
                    . ($row->amount !== null ? ' · Rp ' . number_format((float) $row->amount, 0, ',', '.') : '')
                    . ' · dikirim ' . \Illuminate\Support\Carbon::parse($row->created_at)->locale('id')->translatedFormat('j M Y');
            }
        }

        return null;
    }

    private static function sameShop(string $a, string $b): bool
    {
        $words = fn (string $text) => array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)), fn ($word) => mb_strlen($word) >= 3);

        return (bool) array_intersect($words($a), $words($b));
    }

    private static function gray(\GdImage $image, int $x, int $y): int
    {
        $rgb = imagecolorat($image, $x, $y);

        return (int) ((($rgb >> 16) & 0xFF) * 0.299 + (($rgb >> 8) & 0xFF) * 0.587 + ($rgb & 0xFF) * 0.114);
    }
}
