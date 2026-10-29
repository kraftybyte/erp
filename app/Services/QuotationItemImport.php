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
 * USD diabaikan), atau NO | JENIS PEKERJAAN | QTY | Satuan | Harga Satuan |
 * Total Harga dengan penanda di depan nama (A / 1 / -).
 *
 * - Baris berharga = item; qty = QTY × DUR. Harga berupa teks ("By Owner")
 *   tetap menjadi item, harga 0, dengan teks itu sebagai keterangan.
 * - Baris HURUF BESAR tanpa harga, atau berpenanda bagian ("A  Pekerjaan
 *   Lantai") = judul bagian / kategori (mis. PARTITION → RIGHT PARTS).
 * - Baris lain tanpa harga = spesifikasi item di atasnya; di format berpenanda,
 *   baris tanpa harga setelah judul atau baris kosong = sub-bagian ("Sisi Kanan").
 * - SUB TOTAL / Total Harga Pekerjaan … / GRAND TOTAL tidak diimpor, tapi
 *   dicocokkan dengan jumlah item.
 *
 * parse() mengembalikan baris mentah; susunannya untuk quotation lewat lines()
 * (kategori sebagai nama item, setiap baris satu item) atau grouped() (satu
 * item per kategori, rinciannya di deskripsi).
 */
class QuotationItemImport
{
    private const HEADERS = [
        'item'        => '/^(items?|nama( item| barang| pekerjaan)?|uraian( pekerjaan)?|(jenis )?pekerjaan|jenis barang|description)$/',
        'price'       => '/^(price\/?qty|price|unit price|harga( satuan)?|harga\/?qty)$/',
        'qty'         => '/^(qty|quantity|kts|kuantitas|jumlah|jml|vol(ume)?)$/',
        'dur'         => '/^(dur|durasi|duration)$/',
        'unit'        => '/^(unit|satuan|sat)$/',
        'total'       => '/^(total|jumlah harga|total harga|amount)$/',
        'spec'        => '/^(deskripsi|spesifikasi|spec|keterangan|detail)$/',
        'no'          => '/^no\.?$/',
    ];

    /**
     * @return array{
     *     items: array<int, array{name: string, section: ?string, subsection: ?string, details: array<int, string>, note: ?string, quantity: ?string, qty: float, price: float, total: float}>,
     *     warnings: array<int, string>,
     *     total: float,
     *     expected_total: ?float
     * }
     */
    public function parse(string $path): array
    {
        $book  = IOFactory::load($path);
        $sheet = $book->getActiveSheet();

        try {
            $rows = $this->readRows($sheet);
        } finally {
            // Spreadsheet saling mereferensi; tanpa ini memorinya tidak dibebaskan.
            $book->disconnectWorksheets();
        }

        return $this->build($rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readRows(Worksheet $sheet): array
    {
        [$headerRow, $columns, $units] = $this->findHeader($sheet);

        $rows = [];
        for ($r = $headerRow + 1, $last = $sheet->getHighestDataRow(); $r <= $last; $r++) {
            [$marker, $depth, $text] = $this->itemText($sheet, $columns['item'], $r);

            $rows[] = [
                'no'     => $this->text($sheet, $columns['no'] ?? null, $r),
                'text'   => $text,
                'marker' => $marker,
                'depth'  => $depth,
                'remark' => $this->remark($sheet, [...($columns['price'] ?? []), ...($columns['total'] ?? [])], $r),
                'spec'  => $this->textLines($sheet, $columns['spec'] ?? null, $r),
                'price' => $this->number($sheet, $columns['price'] ?? null, $r),
                'qty'   => $this->number($sheet, $columns['qty'] ?? null, $r),
                'unit'  => $this->text($sheet, $units[0] ?? null, $r),
                'dur'   => $this->number($sheet, $columns['dur'] ?? null, $r),
                'dunit' => $this->text($sheet, $units[1] ?? null, $r),
                'total' => $this->number($sheet, $columns['total'] ?? null, $r),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function build(array $rows): array
    {
        // Tanpa baris SUB TOTAL, setiap judul dianggap bagian baru.
        $hasSubtotals = collect($rows)->contains(fn ($row) => $this->isSubtotal($row['text']));
        $usesMarkers  = collect($rows)->contains(fn ($row) => $row['marker'] !== null);

        $items       = [];
        $warnings    = [];
        $skipped     = [];
        $expected    = null;
        $pending     = [];
        $section     = null;
        $subsection  = null;
        $sectionOpen = false;
        $running     = 0.0;
        $previous    = 'blank';

        foreach ($rows as $row) {
            $text = $row['text'];
            // Label jumlah bisa ada di kolom NO, mis. "Grand Total".
            $label = $text !== '' ? $text : $row['no'];

            if ($this->isGrandTotal($label)) {
                $expected = $row['total'] ?? $row['price'];
                $running  = 0.0;
                $previous = 'total';

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
                $previous    = 'total';

                continue;
            }

            $hasAmount = ($row['price'] ?? 0) > 0 || ($row['total'] ?? 0) > 0;

            // Baris jumlah tanpa nama item: "TOTAL" di kolom lain, atau "Total Harga
            // Pekerjaan Lantai" di kolom NO. Sama dengan semua item = total keseluruhan;
            // selain itu sub total bagian yang baru selesai.
            if ($text === '' && $hasAmount) {
                $value = $row['total'] ?? $row['price'];

                if (abs($value - array_sum(array_column($items, 'total'))) <= 1) {
                    $expected = $value;
                } elseif (abs($value - $running) > 1) {
                    $warnings[] = ($label !== '' ? $label : 'Sub total ' . ($section ?? '')) . ' di file ' . $this->rupiah($value)
                        . ', jumlah itemnya ' . $this->rupiah($running) . '.';
                }

                $running     = 0.0;
                $sectionOpen = false;
                $previous    = 'total';

                continue;
            }

            if (! $hasAmount && ($row['remark'] === null || $text === '')) {
                if ($text === '') {
                    $previous = 'blank';

                    continue;
                }

                if ($row['marker'] !== null && $row['depth'] === 0) {
                    // "A | Pekerjaan Shopfront/Area Depan": bagian baru.
                    [$section, $subsection, $pending, $sectionOpen] = [$text, null, [], true];
                    $previous = 'heading';
                } elseif ($row['no'] !== '') {
                    // Baris bernomor tanpa harga (mis. "1  Main Flagship Store"): judul dokumen.
                    $pending = [];
                } elseif ($this->isHeading($text)) {
                    $pending[] = $text;
                    $previous  = 'heading';
                } elseif ($usesMarkers && $section !== null && $previous !== 'item' && $previous !== 'spec') {
                    // "Sisi Kanan" di bawah judul bagian atau setelah baris kosong.
                    $subsection = $text;
                    $previous   = 'heading';
                } elseif ($items && ! $pending) {
                    $items[array_key_last($items)]['details'][] = ltrim($text, "- \t");
                    $previous = 'spec';
                } else {
                    $skipped[] = $text;
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
            $previous = 'item';
        }

        $total = round(array_sum(array_column($items, 'total')), 2);

        if ($expected !== null && abs($expected - $total) > 1) {
            $warnings[] = 'Grand total di file ' . $this->rupiah($expected) . ', jumlah item yang terbaca ' . $this->rupiah($total) . '.';
        }

        if ($skipped) {
            $warnings[] = 'Tidak diimpor (tanpa harga, di luar tabel item): "' . implode('", "', $skipped) . '".';
        }

        return ['items' => $items, 'warnings' => $warnings, 'total' => $total, 'expected_total' => $expected];
    }

    /**
     * Setiap baris satu item: nama item = kategorinya (mis. WORK PREPARATION,
     * atau PARTITION · RIGHT PARTS), deskripsi = nama pekerjaan, spesifikasi,
     * dan keterangan jumlah. Baris di luar kategori memakai namanya sendiri.
     *
     * @param  array<int, array>  $items  hasil parse()['items']
     * @return array<int, array{item: string, description_lines: array<int, string>, description: string, qty: float, price: float, total: float}>
     */
    public function lines(array $items): array
    {
        return array_map(function (array $item) {
            $category = implode(' · ', array_filter([$item['section'], $item['subsection']]));

            $lines = array_values(array_unique(array_filter([
                $category !== '' ? $item['name'] : null,
                ...$item['details'],
                $item['note'],
            ])));

            return [
                'item'              => mb_substr($category !== '' ? $category : $item['name'], 0, 255),
                'description_lines' => $lines,
                'description'       => implode('<br>', array_map('e', $lines)),
                'qty'               => $item['qty'],
                'price'             => $item['price'],
                'total'             => $item['total'],
            ];
        }, $items);
    }

    /**
     * Satu item per bagian (mis. FLOORING): harga = jumlah item di bagian itu,
     * deskripsi = rincian pekerjaannya, dengan sub-bagian (RIGHT PARTS) sebagai
     * pemisah. Item di luar bagian mana pun tetap berdiri sendiri.
     *
     * @param  array<int, array>  $items  hasil parse()['items']
     * @return array<int, array>
     */
    public function grouped(array $items): array
    {
        $groups = [];

        foreach ($items as $item) {
            if ($item['section'] === null) {
                $groups[] = $this->lines([$item])[0];

                continue;
            }

            $key = 'section:' . $item['section'];
            $groups[$key] ??= ['item' => $item['section'], 'lines' => [], 'html' => [], 'subsection' => null, 'total' => 0.0];

            if ($item['subsection'] !== null && $item['subsection'] !== $groups[$key]['subsection']) {
                $groups[$key]['subsection'] = $item['subsection'];
                $groups[$key]['lines'][]    = $item['subsection'];
                $groups[$key]['html'][]     = '<strong>' . e($item['subsection']) . '</strong>';
            }

            $line = '- ' . $item['name']
                . ($item['quantity'] ? " ({$item['quantity']})" : '')
                . ($item['remark'] ? " ({$item['remark']})" : '')
                . ($item['details'] ? ' — ' . implode('; ', $item['details']) : '');

            $groups[$key]['lines'][] = $line;
            $groups[$key]['html'][]  = e($line);
            $groups[$key]['total']  += $item['total'];
        }

        return array_values(array_map(fn (array $group) => isset($group['html']) ? [
            'item'              => mb_substr($group['item'], 0, 255),
            'description_lines' => $group['lines'],
            'description'       => implode('<br>', $group['html']),
            'qty'               => 1.0,
            'price'             => round($group['total'], 2),
            'total'             => round($group['total'], 2),
        ] : $group, $groups));
    }

    private function item(array $row, ?string $section, ?string $subsection, array &$warnings): array
    {
        $qty      = ($row['qty'] ?? 0) > 0 ? $row['qty'] : 1.0;
        $dur      = ($row['dur'] ?? 0) > 0 ? $row['dur'] : 1.0;
        $count    = $qty * $dur;
        $price    = $row['price'] ?? 0.0;
        $total    = $row['total'] ?? round($price * $count, 2);
        $amount   = fn (float $n, string $unit) => trim($this->format($n) . ' ' . $unit);
        $note     = $dur > 1 ? $amount($qty, $row['unit']) . ' × ' . $amount($dur, $row['dunit']) : null;
        $quantity = $note ?? ($qty > 1 ? $amount($qty, $row['unit']) : null);

        if ($price <= 0) {
            $price = round($total / $count, 2);
        } elseif (abs($price * $count - $total) > 1) {
            // Total di file yang dipakai (itu yang masuk sub total); dijadikan satu paket.
            $warnings[] = "\"{$row['text']}\": " . $this->rupiah($price) . ' × ' . $this->format($count)
                . ' = ' . $this->rupiah($price * $count) . ', tapi total di file ' . $this->rupiah($total) . '. Dipakai ' . $this->rupiah($total) . ' (qty 1).';

            [$price, $count] = [$total, 1.0];
            $note ??= $qty > 1 ? $quantity : null;
        }

        // Satuan ikut tercatat ("2 Unit", "4 ttk"); kolom qty quotation hanya angka.
        $note ??= $qty > 1 && $row['unit'] !== '' ? $quantity : null;

        // Harga berupa teks ("By Owner"): item tetap ada, harga 0, teksnya jadi keterangan.
        $remark = $price <= 0 && $total <= 0 ? $row['remark'] : null;
        if ($remark !== null) {
            $note = implode(' · ', array_filter([$note, $remark]));
        }

        return [
            'name'       => $row['text'],
            'section'    => $section,
            'subsection' => $subsection,
            'remark'     => $remark,
            'details'    => $row['spec'],
            // Keterangan jumlah untuk lines() (bila tidak terbaca dari kolom Qty saja) …
            'note'       => $note,
            // … dan untuk rincian grouped().
            'quantity'   => $quantity,
            'qty'        => $count,
            'price'      => round($price, 2),
            'total'      => round($total, 2),
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
                // "@Harga" → "harga", "Kts." → "kts".
                $label = strtolower(trim(preg_replace(['/\s+/', '/^@\s*/', '/\.$/'], [' ', '', ''], trim((string) $sheet->getCell([$c, $r])->getFormattedValue()))));
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
     * Nama item beserta penandanya. Kolom item yang digabung (mis. B:D) bisa
     * diawali penanda: "A | Pekerjaan Lantai" (bagian, kolom pertama) atau
     * "1 | Facade Logo" / "- | Mobilisasi" (item, kolom berikutnya).
     *
     * @param  array<int, int>  $columns
     * @return array{0: ?string, 1: ?int, 2: string}  penanda, letak kolomnya (0 = pertama), nama
     */
    private function itemText(Worksheet $sheet, array $columns, int $row): array
    {
        $values = [];
        foreach ($columns as $index => $column) {
            $value = trim(preg_replace('/\s+/u', ' ', (string) $this->value($sheet->getCell([$column, $row]))));

            if ($value !== '') {
                $values[$index] = $value;
            }
        }

        $first = array_key_first($values);

        if (count($values) > 1 && preg_match('/^(?:[A-Za-z]|\d{1,3}|[IVX]+)[.)]?$|^[-–•·*]$/u', $values[$first])) {
            $marker = $values[$first];
            unset($values[$first]);

            return [$marker, $first, reset($values)];
        }

        return [null, null, $first === null ? '' : $values[$first]];
    }

    /**
     * Teks di kolom harga/total yang bukan angka, mis. "By Owner" ("Rp" dan "-" tidak dihitung).
     *
     * @param  array<int, int>  $columns
     */
    private function remark(Worksheet $sheet, array $columns, int $row): ?string
    {
        foreach ($columns as $column) {
            $value = $this->value($sheet->getCell([$column, $row]));

            if (is_string($value) && ($value = trim($value)) !== '' && ! preg_match('/\d/', $value) && ! preg_match('/^(rp\.?|-|–)$/iu', $value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Isi sel per baris, tanpa tanda poin: "• ACP 4mm\n• HOLO 2x2" → ["ACP 4mm", "HOLO 2x2"].
     *
     * @param  array<int, int>|null  $columns
     * @return array<int, string>
     */
    private function textLines(Worksheet $sheet, ?array $columns, int $row): array
    {
        foreach ($columns ?? [] as $column) {
            $lines = array_values(array_filter(array_map(
                fn (string $line) => trim(preg_replace(['/^[\s•·▪◦*–-]+/u', '/\s+/u'], ['', ' '], $line)),
                preg_split('/\R/u', (string) $this->value($sheet->getCell([$column, $row])))
            ), fn (string $line) => $line !== ''));

            if ($lines) {
                return $lines;
            }
        }

        return [];
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
        // "GRAND TOTAL", atau baris jumlah di bawah tabel: "TOTAL", "Total Harga", "Jumlah".
        return (bool) preg_match('/^(grand\s*total\b.*|total( keseluruhan| harga| akhir)?|jumlah( total| keseluruhan)?)$/i', trim($text, " \t:"));
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
