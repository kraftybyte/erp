<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Membaca item quotation dari file Excel/CSV budget, mis. format
 * NO | ITEMS | PRICE/QTY | QTY | UNIT | DUR | UNIT | TOTAL (kolom lain seperti
 * USD diabaikan).
 *
 * - Baris berharga = item; qty = QTY × DUR.
 * - Baris HURUF BESAR tanpa harga = judul bagian (mis. PARTITION → RIGHT PARTS),
 *   dicantumkan di deskripsi item.
 * - Baris lain tanpa harga = spesifikasi item di atasnya.
 * - SUB TOTAL / GRAND TOTAL tidak diimpor, tapi dicocokkan dengan jumlah item.
 */
class QuotationItemImport
{
    private const HEADERS = [
        'item'        => '/^(items?|nama( item| barang| pekerjaan)?|uraian( pekerjaan)?|pekerjaan|description)$/',
        'price'       => '/^(price\/?qty|price|unit price|harga( satuan)?|harga\/?qty)$/',
        'qty'         => '/^(qty|quantity|jumlah|jml|vol(ume)?)$/',
        'dur'         => '/^(dur|durasi|duration)$/',
        'unit'        => '/^(unit|satuan|sat)$/',
        'total'       => '/^(total|jumlah harga|total harga|amount)$/',
        'spec'        => '/^(deskripsi|spesifikasi|spec|keterangan|detail)$/',
        'no'          => '/^no\.?$/',
    ];

    /**
     * @return array{
     *     items: array<int, array{item: string, description_lines: array<int, string>, qty: float, price: float, total: float}>,
     *     warnings: array<int, string>,
     *     total: float,
     *     expected_total: ?float
     * }
     */
    public function parse(string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet();

        [$headerRow, $columns, $units] = $this->findHeader($sheet);

        $rows = [];
        for ($r = $headerRow + 1, $last = $sheet->getHighestDataRow(); $r <= $last; $r++) {
            $rows[] = [
                'no'    => $this->text($sheet, $columns['no'] ?? null, $r),
                'text'  => $this->text($sheet, $columns['item'], $r),
                'spec'  => $this->text($sheet, $columns['spec'] ?? null, $r),
                'price' => $this->number($sheet, $columns['price'] ?? null, $r),
                'qty'   => $this->number($sheet, $columns['qty'] ?? null, $r),
                'unit'  => $this->text($sheet, $units[0] ?? null, $r),
                'dur'   => $this->number($sheet, $columns['dur'] ?? null, $r),
                'dunit' => $this->text($sheet, $units[1] ?? null, $r),
                'total' => $this->number($sheet, $columns['total'] ?? null, $r),
            ];
        }

        return $this->build($rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function build(array $rows): array
    {
        // Tanpa baris SUB TOTAL, setiap judul dianggap bagian baru.
        $hasSubtotals = collect($rows)->contains(fn ($row) => $this->isSubtotal($row['text']));

        $items       = [];
        $warnings    = [];
        $expected    = null;
        $pending     = [];
        $section     = null;
        $subsection  = null;
        $sectionOpen = false;
        $running     = 0.0;

        foreach ($rows as $row) {
            $text = $row['text'];

            if ($this->isGrandTotal($text)) {
                $expected = $row['total'] ?? $row['price'];

                continue;
            }

            if ($this->isSubtotal($text)) {
                $value = $row['total'] ?? $row['price'];

                if ($value !== null && abs($value - $running) > 1) {
                    $warnings[] = 'Sub total ' . ($section ?? '') . ' di file ' . $this->rupiah($value)
                        . ', jumlah itemnya ' . $this->rupiah($running) . '.';
                }

                $running     = 0.0;
                $sectionOpen = false;

                continue;
            }

            $hasAmount = ($row['price'] ?? 0) > 0 || ($row['total'] ?? 0) > 0;

            if (! $hasAmount) {
                if ($text === '') {
                    continue;
                }

                // Baris bernomor tanpa harga (mis. "1  Main Flagship Store"): judul dokumen.
                if ($row['no'] !== '') {
                    $pending = [];
                } elseif ($this->isHeading($text)) {
                    $pending[] = $text;
                } elseif ($items && ! $pending) {
                    $items[array_key_last($items)]['description_lines'][] = ltrim($text, "- \t");
                }

                continue;
            }

            if ($pending) {
                if (! $hasSubtotals || ! $sectionOpen) {
                    $section    = $pending[0];
                    $subsection = $pending[1] ?? null;
                } else {
                    $subsection = end($pending);
                }

                $pending     = [];
                $sectionOpen = true;
            }

            $items[] = $this->item($row, $section, $subsection, $warnings);
            $running += $items[array_key_last($items)]['total'];
        }

        $total = round(array_sum(array_column($items, 'total')), 2);

        if ($expected !== null && abs($expected - $total) > 1) {
            $warnings[] = 'Grand total di file ' . $this->rupiah($expected) . ', jumlah item yang terbaca ' . $this->rupiah($total) . '.';
        }

        return ['items' => $items, 'warnings' => $warnings, 'total' => $total, 'expected_total' => $expected];
    }

    private function item(array $row, ?string $section, ?string $subsection, array &$warnings): array
    {
        $qty   = ($row['qty'] ?? 0) > 0 ? $row['qty'] : 1.0;
        $dur   = ($row['dur'] ?? 0) > 0 ? $row['dur'] : 1.0;
        $count = $qty * $dur;
        $price = $row['price'] ?? 0.0;
        $total = $row['total'] ?? round($price * $count, 2);

        $lines = array_values(array_filter([
            implode(' · ', array_filter([$section, $subsection])),
            $row['spec'],
            $dur > 1 ? $this->format($qty) . ' ' . ($row['unit'] ?: 'x') . ' × ' . $this->format($dur) . ' ' . ($row['dunit'] ?: '') : '',
        ]));

        if ($price <= 0) {
            [$price, $count] = [round($total / $count, 2), $count];
        } elseif (abs($price * $count - $total) > 1) {
            // Total di file yang dipakai (itu yang masuk sub total); dijadikan satu paket.
            $warnings[] = "\"{$row['text']}\": " . $this->rupiah($price) . ' × ' . $this->format($count)
                . ' = ' . $this->rupiah($price * $count) . ', tapi total di file ' . $this->rupiah($total) . '. Dipakai ' . $this->rupiah($total) . ' (qty 1).';

            if ($dur > 1 || $qty > 1) {
                $lines[] = $this->format($qty) . ' ' . ($row['unit'] ?: '') . ' × ' . $this->format($dur) . ' ' . ($row['dunit'] ?: '');
            }

            [$price, $count] = [$total, 1.0];
        }

        return [
            'item'              => mb_substr($row['text'], 0, 255),
            'description_lines' => array_values(array_unique(array_map('trim', $lines))),
            'qty'               => $count,
            'price'             => round($price, 2),
            'total'             => round($total, 2),
        ];
    }

    /**
     * Baris judul kolom dan letak setiap kolom. Judul yang digabung (merge)
     * mencakup beberapa kolom, mis. "PRICE/QTY" di atas kolom "Rp" dan angkanya.
     *
     * @return array{0: int, 1: array<string, array<int, int>>, 2: array<int, array<int, int>>}
     */
    private function findHeader(Worksheet $sheet): array
    {
        $spans = [];
        foreach ($sheet->getMergeCells() as $range) {
            [[$c1, $r1], [$c2]] = Coordinate::rangeBoundaries($range);
            $spans[$r1][$c1] = range($c1, $c2);
        }

        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($r = 1, $max = min($sheet->getHighestDataRow(), 40); $r <= $max; $r++) {
            $columns = [];
            $units   = [];

            for ($c = 1; $c <= $lastColumn; $c++) {
                $label = strtolower(trim(preg_replace('/\s+/', ' ', (string) $sheet->getCell([$c, $r])->getFormattedValue())));
                $span  = $spans[$r][$c] ?? [$c];

                foreach (self::HEADERS as $field => $pattern) {
                    if ($label !== '' && preg_match($pattern, $label)) {
                        if ($field === 'unit') {
                            $units[] = $span;
                        } elseif (! isset($columns[$field])) {
                            $columns[$field] = $span;
                        }

                        break;
                    }
                }
            }

            if (isset($columns['item']) && (isset($columns['price']) || isset($columns['total']))) {
                return [$r, $columns, $units];
            }
        }

        throw new RuntimeException('Judul kolom tidak ditemukan. File harus punya kolom ITEMS (nama item) dan PRICE/QTY atau TOTAL.');
    }

    /**
     * @param  array<int, int>|null  $columns
     */
    private function text(Worksheet $sheet, ?array $columns, int $row): string
    {
        foreach ($columns ?? [] as $column) {
            $value = trim(preg_replace('/\s+/u', ' ', (string) $this->value($sheet->getCell([$column, $row]))));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Angka pertama di kolom (atau gabungan kolom) itu: "Rp 40.000.000", 40000000, "-".
     *
     * @param  array<int, int>|null  $columns
     */
    private function number(Worksheet $sheet, ?array $columns, int $row): ?float
    {
        foreach ($columns ?? [] as $column) {
            $value = $this->value($sheet->getCell([$column, $row]));

            if (is_int($value) || is_float($value)) {
                return (float) $value;
            }

            if ($parsed = $this->parseNumber((string) $value)) {
                return $parsed;
            }
        }

        return null;
    }

    private function value(Cell $cell): mixed
    {
        // Nilai tersimpan dari Excel dipakai lebih dulu, supaya rumus tidak perlu dihitung ulang.
        return $cell->isFormula()
            ? ($cell->getOldCalculatedValue() ?? $cell->getCalculatedValue())
            : $cell->getValue();
    }

    private function parseNumber(string $text): ?float
    {
        $text = preg_replace('/[^\d.,]/', '', $text);

        if ($text === '' || ! preg_match('/\d/', $text)) {
            return null;
        }

        // "40.000.000" / "40.000.000,50" (Indonesia) atau "40,000,000.50".
        $lastDot   = strrpos($text, '.');
        $lastComma = strrpos($text, ',');
        $decimal   = match (true) {
            $lastDot !== false && $lastComma !== false => $lastDot > $lastComma ? '.' : ',',
            $lastComma !== false                       => preg_match('/,\d{3}$/', $text) ? null : ',',
            $lastDot !== false                         => preg_match('/\.\d{3}$/', $text) ? null : '.',
            default                                    => null,
        };

        $thousands = $decimal === '.' ? ',' : '.';
        $text      = str_replace($decimal === null ? ['.', ','] : [$thousands], '', $text);

        return (float) str_replace(',', '.', $text);
    }

    private function isHeading(string $text): bool
    {
        return preg_match('/\p{L}/u', $text) && mb_strtoupper($text) === $text;
    }

    private function isSubtotal(string $text): bool
    {
        return (bool) preg_match('/^sub\s*-?\s*total\b/i', $text);
    }

    private function isGrandTotal(string $text): bool
    {
        return (bool) preg_match('/^(grand\s*total|total keseluruhan)\b/i', $text);
    }

    private function format(float $number): string
    {
        return rtrim(rtrim(number_format($number, 2, ',', '.'), '0'), ',');
    }

    private function rupiah(float $amount): string
    {
        return 'Rp ' . number_format($amount, 0, ',', '.');
    }
}
