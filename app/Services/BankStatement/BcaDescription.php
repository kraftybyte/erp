<?php

namespace App\Services\BankStatement;

/**
 * Mengurai keterangan mutasi BCA menjadi jenis transaksi, kode bank lawan,
 * nominal yang diulang di keterangan, dan "lawan transaksi" (berita +
 * nama pengirim/penerima) tanpa kode-kode BCA.
 *
 *   TRSF E-BANKING CR 0311/FTSCY/WS95051 15000000.00 INV 12 PT NESTORY INDO
 *   BI-FAST CR BIF TRANSFER DR 014 BUDI SANTOSO
 *   SWITCHING CR TRANSFER DR 008 CV ABADI
 *   TRF LLG CR / KLIRING / RTGS CR ... , SETORAN TUNAI, KR OTOMATIS ...
 *
 * Kode seperti "0311/FTSCY/WS95051" dulu ikut dibaca sebagai angka/kata
 * berita dan bisa membuat nomor invoice atau nama "cocok" secara kebetulan.
 */
final class BcaDescription
{
    // Kata dari bank dan kata umum di berita transfer: bukan nama pengirim.
    private const NOT_A_NAME = [
        'trsf', 'trf', 'transfer', 'banking', 'cr', 'db', 'bi', 'fast', 'bif', 'dr', 'ke', 'dari', 'llg', 'skn', 'rtgs', 'kliring',
        'switching', 'setoran', 'setor', 'tunai', 'kr', 'otomatis', 'cdm', 'atm', 'va', 'virtual', 'account', 'mybca', 'klikbca',
        'pembayaran', 'pemb', 'bayar', 'byr', 'payment', 'invoice', 'inv', 'dp', 'termin', 'pelunasan', 'lunas', 'untuk', 'utk',
        'proyek', 'project', 'tagihan', 'no', 'nomor', 'ref', 'po', 'down', 'pt', 'cv', 'tbk', 'ud', 'bpk', 'ibu', 'bapak', 'sdr',
    ];

    /**
     * @return array{kind: string, bank: ?string, echo: ?float, counterparty: string}
     */
    public static function parse(string $description): array
    {
        $text = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $description)));

        $kind = match (true) {
            (bool) preg_match('/^TRSF E-?BANKING (CR|DB)\b/', $text)    => 'transfer BCA',
            (bool) preg_match('/^BI-?FAST (CR|DB)\b/', $text)            => 'BI-FAST',
            (bool) preg_match('/^SWITCHING (CR|DB)\b/', $text)           => 'transfer ATM antarbank',
            (bool) preg_match('/\bRTGS\b/', $text)                       => 'RTGS',
            (bool) preg_match('/\b(LLG|SKN|KLIRING)\b/', $text)          => 'kliring',
            (bool) preg_match('/^(SETORAN|SETOR)\b/', $text)             => 'setoran tunai',
            (bool) preg_match('/^(KR|DB) OTOMATIS\b/', $text)            => 'otomatis',
            (bool) preg_match('/^TARIK TUNAI\b/', $text)                 => 'tarik tunai',
            default                                                     => 'lainnya',
        };

        $bank = preg_match('/\b(?:DR|KE|DARI)\s+(\d{3})\b/', $text, $match) ? $match[1] : null;
        $echo = preg_match('/(?<![\d.,])(\d{4,13}\.\d{2})(?![\d])/', $text, $match) ? (float) $match[1] : null;

        $counterparty = preg_replace([
            '/\b(?:DR|KE|DARI)\s+\d{3}\b/',
            '/^(TRSF E-?BANKING|BI-?FAST|SWITCHING|KR OTOMATIS|DB OTOMATIS|SETORAN(?: TUNAI| VIA CDM)?|TARIK TUNAI)\b/',
            '/\b(CR|DB|BIF|TRANSFER|TRF|LLG|SKN|RTGS|KLIRING)\b/',
            '/\S*\/\S*/',
            '/\bWS\d{5}\b/',
            '/\bFT[A-Z]{3}\b/',
            '/(?<![\d.,])\d{4,13}\.\d{2}(?![\d])/',
        ], ' ', $text);

        return [
            'kind'         => $kind,
            'bank'         => $bank,
            'echo'         => $echo,
            'counterparty' => trim(preg_replace('/\s+/u', ' ', $counterparty)),
        ];
    }

    /**
     * Kata nama dari lawan transaksi, untuk mengenali pengirim yang sama di
     * mutasi berikutnya ("pernah membayar dari rekening ini").
     *
     * @return array<int, string>
     */
    public static function nameWords(string $counterparty): array
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($counterparty), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter($words, fn (string $word) => mb_strlen($word) >= 3 && ! in_array($word, self::NOT_A_NAME, true))));
    }

    /**
     * Pengirim yang sama: dua kata nama sama, atau satu kata panjang (≥ 6 huruf)
     * bila salah satunya hanya punya satu kata. Nama di BCA sering terpotong,
     * jadi awalan ≥ 4 huruf dihitung sama.
     *
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     */
    public static function sameSender(array $a, array $b): bool
    {
        if ($a === [] || $b === []) {
            return false;
        }

        $same = 0;
        $long = false;

        foreach ($a as $word) {
            foreach ($b as $other) {
                if ($word === $other || (mb_strlen($word) >= 4 && mb_strlen($other) >= 4 && (str_starts_with($word, $other) || str_starts_with($other, $word)))) {
                    $same++;
                    $long = $long || min(mb_strlen($word), mb_strlen($other)) >= 6;
                    break;
                }
            }
        }

        return $same >= 2 || ($same === 1 && $long && min(count($a), count($b)) === 1);
    }
}
