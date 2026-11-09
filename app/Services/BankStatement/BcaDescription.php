<?php

namespace App\Services\BankStatement;

/**
 * Mengurai keterangan mutasi BCA menjadi jenis transaksi, kode bank lawan,
 * nominal yang diulang, nomor referensi, berita, dan nama pengirim/penerima.
 *
 *   TRSF E-BANKING CR 0411/FTSCY/WS95051 2500000.00 · DP Logo X PI/0130105/00002 · BUDI KARYA MANDIRI
 *   TRSF E-BANKING CR 0411/ATSCY/WS95321 BTR261104100000001 · TL NEON BOX · SINAR TERANG ABADI
 *   BI-FAST CR BIF TRANSFER DR 014 BUDI SANTOSO
 *
 * KlikBCA Bisnis memisahkan kode, berita, dan nama dengan dua spasi atau
 * lebih (StatementReader menuliskannya sebagai " · "); nama pengirim selalu
 * bagian terakhir, terpotong 18 huruf. Tanpa pemisah (KlikBCA perorangan,
 * PDF), berita dan nama digabung sebagai lawan transaksi.
 *
 * Nomor invoice di berita (mis. "PI/0130105/00002") dipertahankan; yang
 * dibuang hanya kode BCA ("0411/FTSCY/WS95051"), referensi ("BTR2611041…"),
 * dan nominal yang diulang.
 */
final class BcaDescription
{
    // BCA memotong nama pengirim menjadi 18 huruf.
    public const NAME_LENGTH = 18;

    // Kata dari bank dan kata umum di berita transfer: bukan nama pengirim.
    private const NOT_A_NAME = [
        'trsf', 'trf', 'transfer', 'banking', 'cr', 'db', 'bi', 'fast', 'bif', 'dr', 'ke', 'dari', 'llg', 'skn', 'rtgs', 'kliring',
        'switching', 'setoran', 'setor', 'tunai', 'kr', 'otomatis', 'cdm', 'atm', 'va', 'virtual', 'account', 'mybca', 'klikbca',
        'pembayaran', 'pemb', 'bayar', 'byr', 'payment', 'invoice', 'inv', 'dp', 'termin', 'pelunasan', 'lunas', 'untuk', 'utk',
        'proyek', 'project', 'tagihan', 'no', 'nomor', 'ref', 'po', 'down', 'pt', 'cv', 'tbk', 'ud', 'bpk', 'ibu', 'bapak', 'sdr',
        'pengembalian', 'dana', 'refund', 'salah', 'tf',
    ];

    private const BCA_CODE = '/\b\d{4}\/[A-Z]{5}\/WS\d{5}\b/';

    private const REFERENCE_BODY = '(?:BTR|BCA|BIF|TRF|FT)\d{10,}';

    private const REFERENCE = '/\b' . self::REFERENCE_BODY . '\b/';

    private const ECHO_AMOUNT = '/(?<![\d.,])(\d{4,13}\.\d{2})(?![\d])/';

    /**
     * @return array{kind: string, bank: ?string, echo: ?float, reference: ?string, remark: string, sender: string, counterparty: string, purpose: ?string}
     */
    public static function parse(string $description): array
    {
        $raw  = mb_strtoupper(trim($description));
        $text = trim(preg_replace('/\s+/u', ' ', str_replace('·', ' ', $raw)));

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

        $bank      = preg_match('/\b(?:DR|KE|DARI)\s+(\d{3})\b/', $text, $match) ? $match[1] : null;
        $echo      = preg_match(self::ECHO_AMOUNT, $text, $match) ? (float) $match[1] : null;
        $reference = preg_match(self::REFERENCE, $text, $match) ? $match[0] : null;

        [$remark, $sender] = self::split($raw);

        return [
            'kind'         => $kind,
            'bank'         => $bank,
            'echo'         => $echo,
            'reference'    => $reference,
            'remark'       => $remark,
            'sender'       => $sender,
            'counterparty' => trim($remark . ' ' . $sender),
            // Dari seluruh keterangan: "Salah TF" bisa terpotong ke bagian nama.
            'purpose'      => self::purpose(self::clean($text)),
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

    /**
     * Berita dan nama pengirim. Dengan pemisah (" · " / dua spasi): bagian
     * pertama kode, terakhir nama, di tengah berita. Bila hanya ada satu
     * bagian setelah kode, nama adalah 18 huruf terakhirnya.
     *
     * @return array{0: string, 1: string}
     */
    private static function split(string $raw): array
    {
        $segments = array_values(array_filter(array_map('trim', preg_split('/\s*·\s*|\s{2,}/u', $raw)), fn ($segment) => $segment !== ''));

        if (count($segments) >= 2) {
            $rest = array_slice($segments, 1);

            // Nominal/referensi kadang terpisah dari kodenya.
            while ($rest && preg_match('/^(' . self::REFERENCE_BODY . '|\d{4,13}\.\d{2})$/', $rest[0])) {
                array_shift($rest);
            }

            if (count($rest) >= 2) {
                $sender = array_pop($rest);

                return [self::clean(implode(' ', $rest)), self::clean($sender)];
            }

            if (count($rest) === 1) {
                $only = self::clean($rest[0]);

                return mb_strlen($only) > self::NAME_LENGTH
                    ? [trim(mb_substr($only, 0, -self::NAME_LENGTH)), trim(mb_substr($only, -self::NAME_LENGTH))]
                    : ['', $only];
            }
        }

        return [self::clean($raw), ''];
    }

    /**
     * Buang kode transaksi BCA, referensi, nominal yang diulang, dan kata
     * jenis transaksi; nomor invoice tetap.
     */
    private static function clean(string $text): string
    {
        $text = preg_replace([
            '/\b(?:DR|KE|DARI)\s+\d{3}\b/',
            '/^\s*(TRSF E-?BANKING|BI-?FAST|SWITCHING|KR OTOMATIS|DB OTOMATIS|SETORAN(?: TUNAI| VIA CDM)?|TARIK TUNAI)(\s+(CR|DB))?\b/',
            '/^\s*(CR|DB)\b/',
            '/\b(BIF TRANSFER|TRANSFER|TRF|LLG|SKN|RTGS|KLIRING)\b/',
            self::BCA_CODE,
            '/\bWS\d{5}\b/',
            '/\bFT[A-Z]{3}\b/',
            self::REFERENCE,
            self::ECHO_AMOUNT,
        ], ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Tujuan transfer dari beritanya: pengembalian dana dan salah transfer
     * bukan pembayaran invoice; DP dan pelunasan menguatkan pola nominalnya.
     */
    private static function purpose(string $text): ?string
    {
        return match (true) {
            (bool) preg_match('/\b(PENGEMBALIAN|REFUND|RETUR|RETURN|KEMBALI(KAN)? DANA)\b/', $text) => 'refund',
            (bool) preg_match('/\bSALAH\s*(TF|TRF|TRANSFER|KIRIM|TRANSAKSI)\b/', $text)            => 'wrong',
            (bool) preg_match('/\b(DP|UANG MUKA|DOWN ?PAYMENT|TERMIN)\b/', $text)                  => 'dp',
            (bool) preg_match('/\b(PELUNASAN|LUNAS|SISA PEMBAYARAN|FINAL PAYMENT)\b/', $text)       => 'settlement',
            default                                                                                => null,
        };
    }
}
