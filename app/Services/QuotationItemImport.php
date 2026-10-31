<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Membaca item quotation dari file Excel/CSV budget apa adanya, mis. format
 * NO | ITEMS | PRICE/QTY | QTY | UNIT | DUR | UNIT | TOTAL (kolom lain seperti
 * USD diabaikan), NO | JENIS PEKERJAAN | QTY | Satuan | Harga Satuan | Total
 * Harga dengan penanda di depan nama (A / 1 / -), Description | Qty | Unit
 * Price | Amount, judul kolom dua baris (HARGA → Satuan | Jumlah), atau tabel
 * tanpa judul kolom (dikenali dari isinya: qty × harga = total).
 *
 * - Baris berharga = item; qty = QTY × DUR. Harga berupa teks ("By Owner")
 *   tetap menjadi item, harga 0, dengan teks itu sebagai keterangan.
 * - Baris HURUF BESAR tanpa harga, berpenanda bagian ("A  Pekerjaan Lantai",
 *   di kolom nama atau kolom NO), atau tanpa harga di awal tabel / setelah
 *   sub total = judul bagian / kategori (mis. PARTITION → RIGHT PARTS).
 * - Baris lain tanpa harga = spesifikasi item di atasnya; di format berpenanda,
 *   baris tanpa harga setelah judul atau baris kosong = sub-bagian ("Sisi Kanan").
 * - SUB TOTAL / Total Harga Pekerjaan … / GRAND TOTAL tidak diimpor, tapi
 *   dicocokkan dengan jumlah item.
 *
 * Bila kolomnya tidak dikenali, pengguna memilih sendiri lewat $mapping (lihat
 * detect()). parse() mengembalikan baris mentah; susunannya untuk quotation
 * lewat lines() (kategori sebagai nama item, setiap baris satu item) atau
 * grouped() (satu item per kategori, rinciannya di deskripsi).
 */
class QuotationItemImport
{
    /** Pilihan sheet: gabungkan semua sheet yang berisi tabel item. */
    public const ALL_SHEETS = '*';

    /** Kolom yang bisa dipilih pengguna di $mapping, dengan labelnya. */
    public const ROLES = [
        'item'  => 'Nama item',
        'spec'  => 'Deskripsi / spesifikasi',
        'qty'   => 'Qty',
        'unit'  => 'Satuan',
        'price' => 'Harga satuan',
        'total' => 'Total',
        'no'    => 'No / penanda bagian',
    ];

    /** Area yang dibaca; sel di luarnya (mis. format sampai kolom XEK) diabaikan. */
    private const MAX_ROWS = 5000;

    private const MAX_COLUMNS = 80;

    /** Baris teratas yang diperiksa untuk mencari judul kolom. */
    private const HEADER_SCAN = 40;

    /**
     * Judul kolom yang dikenali, setelah dirapikan (huruf kecil, tanpa "(Rp)",
     * tanda baca, dan spasi ganda). Yang pertama cocok dipakai.
     */
    private const HEADERS = [
        'no'    => '/^(no|nomor|nr|urut|no urut|item no)$/',
        'total' => '/^(total|sub ?total|jumlah harga|total harga|harga total|amount|total amount|nilai|nilai total|total price|line total|jumlah biaya|total biaya|biaya total)$/',
        'price' => '/^(price|unit price|price\/(qty|unit|pcs|item)|harga|harga satuan|harga unit|harga\/(qty|unit|pcs|item|satuan)|rate|unit rate|unit cost|biaya|biaya satuan)$/',
        'qty'   => '/^(qty|quantity|kts|kuantitas|jumlah|jml|vol|volume|banyak|banyaknya|pcs)$/',
        'dur'   => '/^(dur|durasi|duration|periode|bulan|months?|frekuensi|freq)$/',
        'unit'  => '/^(unit|satuan|sat|uom)$/',
        'item'  => '/^(items?|item name|nama|nama (item|barang|pekerjaan|produk|jasa|layanan)|uraian|uraian (pekerjaan|barang|kegiatan)|(jenis|lingkup) pekerjaan|pekerjaan|jenis barang|barang|produk|products?|services?|jasa|layanan|kegiatan|particulars|item description|deskripsi pekerjaan)$/',
        'spec'  => '/^(deskripsi|description|desc|spesifikasi|spec|specs|specification|keterangan|ket|detail|detil|rincian|catatan|remarks?|notes?)$/',
    ];

    /**
     * @param  string|null  $sheet  nama sheet, ALL_SHEETS, atau null: sheet yang terbuka, lalu sheet lain
     * @param  array<string, mixed>|null  $mapping  kolom pilihan pengguna: header_row + huruf kolom per ROLES
     * @return array{
     *     items: array<int, array{name: string, section: ?string, subsection: ?string, details: array<int, string>, note: ?string, quantity: ?string, qty: float, price: float, total: float, issues: array<int, string>}>,
     *     warnings: array<int, string>,
     *     notes: array<int, string>,
     *     total: float,
     *     expected_total: ?float
     * }
     */
    public function parse(string $path, ?string $sheet = null, ?array $mapping = null): array
    {
        $book = $this->load($path);

        try {
            if ($sheet === self::ALL_SHEETS) {
                return $this->parseAll($book);
            }

            [$grid, $header] = $this->locate($book, $sheet, $mapping);
            $rows = $this->readRows($grid, ...$header);
        } finally {
            // Spreadsheet saling mereferensi; tanpa ini memorinya tidak dibebaskan.
            $book->disconnectWorksheets();
        }

        return $this->build($rows);
    }

    /**
     * Nama sheet di file, untuk dipilih sebelum import; kosong untuk CSV.
     *
     * @return array<int, string>
     */
    public function sheetNames(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);

        return method_exists($reader, 'listWorksheetNames') ? $reader->listWorksheetNames($path) : [];
    }

    /**
     * Hasil baca otomatis, untuk diatur ulang pengguna bila kurang tepat: baris
     * judul kolom (0 = tanpa judul), huruf kolom per peran, judul setiap kolom,
     * dan cuplikan beberapa baris pertama tabel.
     *
     * @return array{found: bool, header_row: int, columns: array<string, ?string>, labels: array<string, string>, preview: array<int, array{row: int, cells: array<string, string>}>}
     */
    public function detect(string $path, ?string $sheet = null): array
    {
        $book = $this->load($path);

        try {
            try {
                [$grid, [$headerRow, $columns, $units]] = $this->locate($book, $sheet === self::ALL_SHEETS ? null : $sheet);
                $found = true;
            } catch (RuntimeException) {
                $picked = $sheet !== null && $sheet !== self::ALL_SHEETS ? $book->getSheetByName($sheet) : null;
                $grid   = $this->grid($picked ?? $book->getActiveSheet());
                [$headerRow, $columns, $units, $found] = [0, [], [], false];

                // Tebakan baris judul: baris pertama yang berisi beberapa teks dan tanpa angka.
                foreach (array_slice($grid['cells'], 0, self::HEADER_SCAN, true) as $r => $cells) {
                    if (count($cells) >= 2 && ! array_filter($cells, fn ($value) => is_int($value) || is_float($value))) {
                        $headerRow = $r;

                        break;
                    }
                }

                // Tebakan nama item: kolom yang paling banyak berisi teks di bawahnya.
                $texts = [];
                foreach ($grid['cells'] as $r => $cells) {
                    foreach ($r > $headerRow ? $cells : [] as $c => $value) {
                        if (is_string($value) && preg_match('/\p{L}{3,}/u', $value)) {
                            $texts[$c] = ($texts[$c] ?? 0) + 1;
                        }
                    }
                }

                if ($texts) {
                    arsort($texts);
                    $columns['item'] = [array_key_first($texts)];
                }
            }
        } finally {
            $book->disconnectWorksheets();
        }

        $letter  = fn (?array $span, bool $numeric) => $span ? Coordinate::stringFromColumnIndex($this->primaryColumn($grid, $span, $headerRow, $numeric)) : null;
        $lastCol = min($grid['cols'], 26);

        $labels = [];
        for ($c = 1; $c <= $lastCol; $c++) {
            $labels[Coordinate::stringFromColumnIndex($c)] = $headerRow > 0 ? $this->cellText($grid, $c, $headerRow) : '';
        }

        $preview = [];
        foreach ($grid['cells'] as $r => $cells) {
            if ($r < max($headerRow, 1) || count($preview) >= 8) {
                continue;
            }

            $preview[] = ['row' => $r, 'cells' => array_map(
                fn (string $letter) => mb_strimwidth($this->cellText($grid, Coordinate::columnIndexFromString($letter), $r), 0, 28, '…'),
                array_combine(array_keys($labels), array_keys($labels)),
            )];
        }

        return [
            'found'      => $found,
            'header_row' => $headerRow,
            'columns'    => [
                'item'  => $letter($columns['item'] ?? null, false),
                'spec'  => $letter($columns['spec'] ?? null, false),
                'qty'   => $letter($columns['qty'] ?? null, true),
                'unit'  => $letter($units[0] ?? null, false),
                'price' => $letter($columns['price'] ?? null, true),
                'total' => $letter($columns['total'] ?? null, true),
                'no'    => $letter($columns['no'] ?? null, false),
            ],
            'labels'     => $labels,
            'preview'    => $preview,
        ];
    }

    private function load(string $path): Spreadsheet
    {
        $reader = IOFactory::createReaderForFile($path);
        // Sel yang hanya berformat (tanpa isi) tidak dimuat; gabungan sel (merge) tetap dibaca.
        $reader->setReadEmptyCells(false);

        return $reader->load($path);
    }

    /**
     * Sheet dan letak tabelnya. Tanpa pilihan sheet: sheet yang terbuka, lalu
     * sheet lain sampai ditemukan tabel item (file budget sering dibuka di
     * sheet ringkasan).
     *
     * @return array{0: array, 1: array{0: int, 1: array<string, array<int, int>>, 2: array<int, array<int, int>>}}
     */
    private function locate(Spreadsheet $book, ?string $name, ?array $mapping = null): array
    {
        $sheets = $name !== null
            ? [$book->getSheetByName($name) ?? throw new RuntimeException("Sheet \"{$name}\" tidak ada di file ini.")]
            : [$book->getActiveSheet(), ...array_filter($book->getAllSheets(), fn (Worksheet $sheet) => $sheet !== $book->getActiveSheet())];

        if ($mapping !== null) {
            $grid = $this->grid($sheets[0]);

            return [$grid, $this->mapped($grid, $mapping)];
        }

        foreach ($sheets as $sheet) {
            $grid = $this->grid($sheet);

            if ($header = $this->findHeader($grid) ?? $this->detectByContent($grid)) {
                return [$grid, $header];
            }
        }

        throw new RuntimeException('Judul kolom tidak ditemukan. Pilih kolom nama item dan kolom harga atau total lewat "Atur kolom sendiri".');
    }

    /**
     * Semua sheet yang berisi tabel item, digabung. Kategori diawali nama sheet.
     */
    private function parseAll(Spreadsheet $book): array
    {
        $results = [];
        $empty   = [];

        foreach ($book->getAllSheets() as $sheet) {
            $grid   = $this->grid($sheet);
            $header = $this->findHeader($grid) ?? $this->detectByContent($grid);

            if ($header === null) {
                if ($grid['cells']) {
                    $empty[] = $sheet->getTitle();
                }

                continue;
            }

            $results[$sheet->getTitle()] = $this->build($this->readRows($grid, ...$header));
        }

        if (! $results) {
            throw new RuntimeException('Judul kolom tidak ditemukan di sheet mana pun. Pilih satu sheet, lalu pilih kolomnya lewat "Atur kolom sendiri".');
        }

        $merged = ['items' => [], 'warnings' => [], 'notes' => [], 'total' => 0.0, 'expected_total' => 0.0];

        foreach ($results as $title => $result) {
            foreach ($result['items'] as $item) {
                $item['section']   = $item['section'] !== null ? "{$title} · {$item['section']}" : $title;
                $merged['items'][] = $item;
            }

            $merged['warnings'] = [...$merged['warnings'], ...array_map(fn (string $warning) => "{$title}: {$warning}", $result['warnings'])];
            $merged['notes']    = [...$merged['notes'], ...array_map(fn (string $note) => "{$title}: {$note}", $result['notes'])];
            $merged['total']   += $result['total'];
            $merged['expected_total'] = $merged['expected_total'] === null || $result['expected_total'] === null ? null : $merged['expected_total'] + $result['expected_total'];
        }

        if ($empty) {
            $note = 'Sheet tanpa tabel item dilewati: "' . implode('", "', $empty) . '".';

            $merged['warnings'][] = $note;
            $merged['notes'][]    = $note;
        }

        $merged['total'] = round($merged['total'], 2);

        return $merged;
    }

    /**
     * Isi sheet sebagai [baris][kolom] => nilai, hanya sel yang berisi, plus
     * gabungan sel (merge) per baris: [baris][kolom awal] => kolom-kolomnya.
     *
     * @return array{cells: array<int, array<int, mixed>>, spans: array<int, array<int, array<int, int>>>, rows: int, cols: int}
     */
    private function grid(Worksheet $sheet): array
    {
        $cells = [];

        foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
            [$col, $row] = Coordinate::indexesFromString($coordinate);

            if ($row > self::MAX_ROWS || $col > self::MAX_COLUMNS) {
                continue;
            }

            $value = $this->value($sheet->getCell($coordinate));

            if ($value !== null && ! (is_string($value) && trim($value) === '')) {
                $cells[$row][$col] = $value;
            }
        }

        ksort($cells);

        $spans = [];
        foreach ($sheet->getMergeCells() as $range) {
            [[$c1, $r1], [$c2]] = Coordinate::rangeBoundaries($range);

            if ($r1 <= self::MAX_ROWS && $c1 <= self::MAX_COLUMNS) {
                $spans[$r1][$c1] = range($c1, min($c2, self::MAX_COLUMNS));
            }
        }

        return [
            'cells' => $cells,
            'spans' => $spans,
            'rows'  => $cells ? array_key_last($cells) : 0,
            'cols'  => $cells ? max(array_map(fn (array $row) => max(array_keys($row)), $cells)) : 0,
        ];
    }

    /**
     * @param  array<string, array<int, int>>  $columns
     * @param  array<int, array<int, int>>  $units
     * @return array<int, array<string, mixed>>
     */
    private function readRows(array $grid, int $headerRow, array $columns, array $units): array
    {
        $rows = [];
        for ($r = $headerRow + 1; $r <= $grid['rows']; $r++) {
            [$marker, $depth, $text] = $this->itemText($grid, $columns['item'], $r);

            $rows[] = [
                'no'     => $this->text($grid, $columns['no'] ?? null, $r),
                'text'   => $text,
                'marker' => $marker,
                'depth'  => $depth,
                'remark' => $this->remark($grid, [...($columns['price'] ?? []), ...($columns['total'] ?? [])], $r),
                'spec'   => $this->textLines($grid, $columns['spec'] ?? null, $r),
                'price'  => $this->number($grid, $columns['price'] ?? null, $r),
                'qty'    => $this->number($grid, $columns['qty'] ?? null, $r),
                'unit'   => $this->text($grid, $units[0] ?? null, $r),
                'dur'    => $this->number($grid, $columns['dur'] ?? null, $r),
                'dunit'  => $this->text($grid, $units[1] ?? null, $r),
                'total'  => $this->number($grid, $columns['total'] ?? null, $r),
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
        $hasSubtotals = collect($rows)->contains(fn ($row) => $this->isSubtotalRow($row));
        $usesMarkers  = collect($rows)->contains(fn ($row) => $row['marker'] !== null || $this->isSectionMarker($row));

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

            if ($this->isSubtotalRow($row)) {
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

                $numbered = ($row['marker'] !== null && ctype_digit($row['marker'])) || ctype_digit($row['no']);

                if (($row['marker'] !== null && $row['depth'] === 0) || $this->isSectionMarker($row)) {
                    // "A | Pekerjaan Shopfront/Area Depan" (di kolom nama atau kolom NO): bagian baru.
                    [$section, $subsection, $pending, $sectionOpen] = [$text, null, [], true];
                    $previous = 'heading';
                } elseif ($numbered && ($section !== null || $items)) {
                    // "4 | Logo KT&G" tanpa harga: tetap item sendiri (bukan rincian item
                    // di atasnya), harga 0, dan pengguna diberi tahu.
                    $items[]    = $this->item($row, $section, $subsection, $warnings);
                    $warnings[] = "\"{$text}\" tidak punya harga di file; diimpor dengan harga Rp 0. Isi harganya atau hapus barisnya.";
                    $items[array_key_last($items)]['issues'][] = 'Tidak ada harga di file.';
                    $previous   = 'item';
                } elseif ($row['no'] !== '') {
                    // Baris bernomor tanpa harga sebelum tabel (mis. "1  Main Flagship Store"): judul dokumen.
                    $pending = [];
                } elseif ($this->isHeading($text)) {
                    $pending[] = $text;
                    $previous  = 'heading';
                } elseif ($usesMarkers && $section !== null && $previous !== 'item' && $previous !== 'spec') {
                    // "Sisi Kanan" di bawah judul bagian atau setelah baris kosong.
                    $subsection = $text;
                    $previous   = 'heading';
                } elseif (str_ends_with(rtrim($text), ':') && ! $items) {
                    // "Lingkup pekerjaan antara lain :": pengantar; yang di atasnya judul dokumen.
                    $skipped  = [...$skipped, ...$pending, $text];
                    $pending  = [];
                    $previous = 'blank';
                } elseif (! $items || $previous === 'total') {
                    // Di awal tabel atau setelah sub total: judul bagian berikutnya.
                    $pending[] = $text;
                    $previous  = 'heading';
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

        return [
            'items'          => $items,
            'warnings'       => $warnings,
            // Peringatan per baris diawali nama barisnya ("Logo KT&G" …) dan sudah ada di
            // issues item itu; sisanya menyangkut bagian atau file, mis. sub total.
            'notes'          => array_values(array_filter($warnings, fn (string $warning) => ! str_starts_with($warning, '"'))),
            'total'          => $total,
            'expected_total' => $expected,
        ];
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
                'issues'            => $item['issues'] ?? [],
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
            $groups[$key] ??= ['item' => $item['section'], 'lines' => [], 'html' => [], 'subsection' => null, 'total' => 0.0, 'issues' => []];

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

            foreach ($item['issues'] ?? [] as $issue) {
                $groups[$key]['issues'][] = "{$item['name']}: {$issue}";
            }
        }

        return array_values(array_map(fn (array $group) => isset($group['html']) ? [
            'item'              => mb_substr($group['item'], 0, 255),
            'description_lines' => $group['lines'],
            'description'       => implode('<br>', $group['html']),
            'qty'               => 1.0,
            'price'             => round($group['total'], 2),
            'total'             => round($group['total'], 2),
            'issues'            => $group['issues'],
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
        $issues   = [];

        if ($price <= 0) {
            $price = round($total / $count, 2);
        } elseif (abs($price * $count - $total) > 1) {
            // Total di file yang dipakai (itu yang masuk sub total); dijadikan satu paket.
            $warnings[] = "\"{$row['text']}\": " . $this->rupiah($price) . ' × ' . $this->format($count)
                . ' = ' . $this->rupiah($price * $count) . ', tapi total di file ' . $this->rupiah($total) . '. Dipakai ' . $this->rupiah($total) . ' (qty 1).';
            $issues[]   = 'Harga ' . $this->rupiah($price) . ' × ' . $this->format($count) . ' = ' . $this->rupiah($price * $count)
                . ', tapi total di file ' . $this->rupiah($total) . '. Dipakai totalnya.';

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
            // Yang perlu dicek pengguna di baris ini sebelum diimpor.
            'issues'     => $issues,
        ];
    }

    /**
     * Baris judul kolom dan letak setiap kolom. Judul yang digabung (merge)
     * mencakup beberapa kolom, mis. "PRICE/QTY" di atas kolom "Rp" dan angkanya.
     * Judul dua baris juga dikenali: "HARGA" di atas "Satuan" dan "Jumlah".
     * Bila beberapa baris cocok, yang mengenali kolom terbanyak dipakai.
     *
     * @return array{0: int, 1: array<string, array<int, int>>, 2: array<int, array<int, int>>}|null
     */
    private function findHeader(array $grid): ?array
    {
        $best = null;

        for ($r = 1, $max = min($grid['rows'], self::HEADER_SCAN); $r <= $max; $r++) {
            foreach ([[$r, null], [$r, $r + 1]] as [$row, $child]) {
                if ($child !== null && ! $this->isGroupedHeader($grid, $row, $child)) {
                    continue;
                }

                [$columns, $units] = $this->headerAt($grid, $row, $child);

                if (! isset($columns['item']) && isset($columns['spec'])) {
                    // "Description | Qty | Unit Price | Amount": deskripsi adalah nama itemnya.
                    [$columns['item'], $columns['spec']] = [$columns['spec'], null];
                    unset($columns['spec']);
                }

                if (! isset($columns['item']) || ! (isset($columns['price']) || isset($columns['total']))) {
                    continue;
                }

                $score = count($columns) + count($units);

                if ($best === null || $score > $best[0]) {
                    $best = [$score, [$child ?? $row, $columns, $units]];
                }
            }
        }

        return $best[1] ?? null;
    }

    /**
     * Peran setiap kolom menurut judul di $row, atau gabungan $row (induk) dan
     * $child (anak) untuk judul dua baris.
     *
     * @return array{0: array<string, array<int, int>>, 1: array<int, array<int, int>>}
     */
    private function headerAt(array $grid, int $row, ?int $child): array
    {
        $columns = [];
        $units   = [];
        $assign  = function (?string $role, array $span) use (&$columns, &$units) {
            if ($role === 'unit') {
                $units[] = $span;
            } elseif ($role !== null && ! isset($columns[$role])) {
                $columns[$role] = $span;
            }
        };

        if ($child === null) {
            foreach ($grid['cells'][$row] ?? [] as $c => $value) {
                $assign($this->role((string) $value), $grid['spans'][$row][$c] ?? [$c]);
            }

            return [$columns, $units];
        }

        // Kolom yang tercakup judul induk yang digabung, mis. HARGA di atas C:D.
        $parentOf = [];
        foreach ($grid['cells'][$row] ?? [] as $c => $value) {
            foreach ($grid['spans'][$row][$c] ?? [$c] as $covered) {
                $parentOf[$covered] = $c;
            }
        }

        $candidates = $parentOf + array_fill_keys(array_keys($grid['cells'][$child] ?? []), null);
        ksort($candidates);

        foreach ($candidates as $c => $start) {
            $parent = $start !== null ? (string) $grid['cells'][$row][$start] : '';
            $label  = (string) ($grid['cells'][$child][$c] ?? '');

            if ($label === '') {
                // Judul induk satu kolom yang juga menutupi baris anak, mis. "NO".
                if ($start === $c) {
                    $assign($this->role($parent), $grid['spans'][$row][$c] ?? [$c]);
                }

                continue;
            }

            $role  = $this->role($label);
            $group = $start !== null ? count($grid['spans'][$row][$start] ?? [$start]) : 0;

            // Sub-judul di bawah judul uang yang digabung (bukan judul dokumen selebar tabel).
            if ($group >= 2 && $group <= 4 && preg_match('/harga|biaya|price|nilai|cost|amount|\brp\b|idr/i', $parent)) {
                $role = match (true) {
                    in_array($role, ['unit', 'price'], true) || (bool) preg_match('/^(per|@)/i', trim($label)) => 'price',
                    in_array($role, ['qty', 'total'], true)                                                       => 'total',
                    default                                                                                        => $role,
                };
            }

            $assign($role ?? ($start === $c ? $this->role($parent) : null), $grid['spans'][$child][$c] ?? [$c]);
        }

        return [$columns, $units];
    }

    /**
     * Judul dua baris: judul induk yang digabung di atas 2–4 sub-judul berupa
     * teks pendek (bukan angka seperti baris data), mis. HARGA → Satuan | Jumlah.
     */
    private function isGroupedHeader(array $grid, int $row, int $child): bool
    {
        $labels = $grid['cells'][$child] ?? [];

        if ($labels === [] || array_filter($labels, fn ($value) => is_int($value) || is_float($value) || mb_strlen((string) $value) > 40)) {
            return false;
        }

        foreach ($grid['spans'][$row] ?? [] as $start => $span) {
            $under = count(array_filter($span, fn (int $c) => isset($labels[$c])));

            if (isset($grid['cells'][$row][$start]) && count($span) <= 4 && $under >= 2) {
                return true;
            }
        }

        return false;
    }

    /**
     * Peran kolom menurut judulnya: "Harga Satuan (Rp)" → price, "Jumlah (Rp)" →
     * total, "Satuan (Rp)" → price, "Kts." → qty.
     */
    private function role(string $raw): ?string
    {
        $raw = trim(preg_replace('/\s+/u', ' ', $raw));

        if ($raw === '#') {
            return 'no';
        }

        $money = (bool) preg_match('/\b(rp|idr|usd|rupiah)\b|\$/iu', $raw);
        $label = mb_strtolower($raw);
        $label = preg_replace(['/\([^)]*\)/u', '/\b(rp|idr|usd|rupiah)\b\.?/u', '/^[@#]/u', '/\s*\/\s*/u'], [' ', ' ', ' ', '/'], $label);
        $label = trim(preg_replace(['/[^\p{L}\p{N}\/ ]+/u', '/\s+/u'], ' ', $label));

        foreach (self::HEADERS as $role => $pattern) {
            if ($label !== '' && preg_match($pattern, $label)) {
                return match (true) {
                    $money && $role === 'qty'  => 'total',
                    $money && $role === 'unit' => 'price',
                    default                    => $role,
                };
            }
        }

        return null;
    }

    /**
     * Tabel tanpa judul kolom, dikenali dari isinya: kolom teks terbanyak = nama
     * item, dan tiga kolom angka dengan qty × harga = total (atau satu kolom
     * nominal sebagai total). Nominal harus berupa uang (≥ 1.000), supaya daftar
     * bernomor biasa tidak terbaca sebagai harga.
     *
     * @return array{0: int, 1: array<string, array<int, int>>, 2: array<int, array<int, int>>}|null
     */
    private function detectByContent(array $grid): ?array
    {
        $texts   = [];
        $numbers = [];

        foreach (array_slice($grid['cells'], 0, 300, true) as $r => $cells) {
            foreach ($cells as $c => $value) {
                $number = is_int($value) || is_float($value) ? (float) $value : $this->parseNumber((string) $value);

                if ($number !== null && ! preg_match('/\p{L}{2,}/u', (string) $value)) {
                    $numbers[$c][$r] = $number;
                } elseif (mb_strlen(trim((string) $value)) >= 3) {
                    $texts[$c] = ($texts[$c] ?? 0) + 1;
                }
            }
        }

        if (! $texts) {
            return null;
        }

        ksort($numbers);
        arsort($texts);
        $item  = array_key_first($texts);
        $money = array_filter($numbers, fn (array $values, int $c) => $c > $item && count($values) >= 2 && $this->median($values) >= 1000, ARRAY_FILTER_USE_BOTH);

        if (! $money) {
            return null;
        }

        $columns = ['item' => [$item]];
        $total   = array_key_last($money);

        // Harga dan qty: pasangan kolom di kiri total yang hasil kalinya sama dengan total.
        foreach (array_keys($numbers) as $a) {
            foreach (array_keys($numbers) as $b) {
                if ($a === $b || $a <= $item || $b <= $item || $a >= $total || $b >= $total || ! isset($money[$b])) {
                    continue;
                }

                $rows    = array_intersect_key($numbers[$a], $numbers[$b], $numbers[$total]);
                $matches = array_filter(array_keys($rows), fn (int $r) => abs($numbers[$a][$r] * $numbers[$b][$r] - $numbers[$total][$r]) <= 1);

                if (count($rows) >= 2 && count($matches) >= max(2, 0.6 * count($rows))) {
                    $columns += ['qty' => [$a], 'price' => [$b]];

                    break 2;
                }
            }
        }

        $columns['total'] = [$total];

        // Tabel dimulai di baris data pertama, beserta judul bagian tepat di atasnya
        // (baris yang hanya berisi teks di kolom nama); judul dokumen di atasnya tidak.
        $first  = min(array_keys(array_intersect_key(array_filter($grid['cells'], fn (array $cells) => isset($cells[$item])), $numbers[$total])) ?: [1]);
        $header = $first - 1;

        while ($header >= 1 && array_keys($grid['cells'][$header] ?? []) === [$item]) {
            $header--;
        }

        return [$header, $columns, []];
    }

    /**
     * Kolom pilihan pengguna: header_row + huruf kolom per ROLES.
     *
     * @return array{0: int, 1: array<string, array<int, int>>, 2: array<int, array<int, int>>}
     */
    private function mapped(array $grid, array $mapping): array
    {
        $columns = [];
        $units   = [];

        foreach (array_keys(self::ROLES) as $role) {
            $letter = strtoupper(trim((string) ($mapping[$role] ?? '')));

            if ($letter === '' || ! preg_match('/^[A-Z]{1,3}$/', $letter)) {
                continue;
            }

            if ($role === 'unit') {
                $units[] = [Coordinate::columnIndexFromString($letter)];
            } else {
                $columns[$role] = [Coordinate::columnIndexFromString($letter)];
            }
        }

        if (! isset($columns['item']) || ! (isset($columns['price']) || isset($columns['total']))) {
            throw new RuntimeException('Pilih kolom nama item, dan kolom harga satuan atau total.');
        }

        return [max(0, (int) ($mapping['header_row'] ?? 0)), $columns, $units];
    }

    /**
     * Kolom utama dari judul yang digabung: yang paling banyak berisi angka
     * (harga/total) atau teks (nama item), mis. D dari "PRICE/QTY" di atas C:D.
     *
     * @param  array<int, int>  $span
     */
    private function primaryColumn(array $grid, array $span, int $headerRow, bool $numeric): int
    {
        $counts = array_fill_keys($span, 0);

        foreach ($grid['cells'] as $r => $cells) {
            if ($r <= $headerRow) {
                continue;
            }

            foreach ($span as $c) {
                $value = $cells[$c] ?? null;

                if ($value !== null && (is_int($value) || is_float($value)) === $numeric) {
                    $counts[$c]++;
                }
            }
        }

        arsort($counts);

        return max($counts) > 0 ? array_key_first($counts) : $span[0];
    }

    /**
     * @param  array<int, float>  $values
     */
    private function median(array $values): float
    {
        sort($values);

        return (float) $values[intdiv(count($values), 2)];
    }

    private function cellText(array $grid, int $column, int $row): string
    {
        $value = $grid['cells'][$row][$column] ?? null;

        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }

        return trim(preg_replace('/\s+/u', ' ', (string) $value));
    }

    /**
     * @param  array<int, int>|null  $columns
     */
    private function text(array $grid, ?array $columns, int $row): string
    {
        foreach ($columns ?? [] as $column) {
            if (($value = $this->cellText($grid, $column, $row)) !== '') {
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
    private function itemText(array $grid, array $columns, int $row): array
    {
        $values = [];
        foreach ($columns as $index => $column) {
            if (($value = $this->cellText($grid, $column, $row)) !== '') {
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
    private function remark(array $grid, array $columns, int $row): ?string
    {
        foreach ($columns as $column) {
            $value = $grid['cells'][$row][$column] ?? null;

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
    private function textLines(array $grid, ?array $columns, int $row): array
    {
        foreach ($columns ?? [] as $column) {
            $lines = array_values(array_filter(array_map(
                fn (string $line) => trim(preg_replace(['/^[\s•·▪◦*–-]+/u', '/\s+/u'], ['', ' '], $line)),
                preg_split('/\R/u', (string) ($grid['cells'][$row][$column] ?? ''))
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
    private function number(array $grid, ?array $columns, int $row): ?float
    {
        foreach ($columns ?? [] as $column) {
            $value = $grid['cells'][$row][$column] ?? null;

            if (is_int($value) || is_float($value)) {
                return (float) $value;
            }

            if ($value !== null && ($parsed = $this->parseNumber((string) $value))) {
                return $parsed;
            }
        }

        return null;
    }

    private function value(Cell $cell): mixed
    {
        // Nilai tersimpan dari Excel dipakai lebih dulu, supaya rumus tidak perlu dihitung ulang.
        if ($cell->isFormula()) {
            try {
                $value = $cell->getOldCalculatedValue() ?? $cell->getCalculatedValue();
            } catch (\Throwable) {
                return null;
            }
        } else {
            $value = $cell->getValue();
        }

        return $value instanceof RichText ? $value->getPlainText() : $value;
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

    /**
     * "SUB TOTAL", atau "Total Harga Pekerjaan Lantai" / "Jumlah …" tanpa harga
     * satuan dan qty (item bernama "Total Station" tetap item karena berharga).
     */
    private function isSubtotalRow(array $row): bool
    {
        return (bool) preg_match('/^sub\s*-?\s*total\b/i', $row['text'])
            || (preg_match('/^(total|jumlah)\b/i', $row['text']) && ! $row['price'] && ! $row['qty']);
    }

    /**
     * Penanda bagian di kolom NO: huruf atau angka romawi ("A", "B.", "IV").
     */
    private function isSectionMarker(array $row): bool
    {
        return $row['text'] !== '' && $row['marker'] === null && (bool) preg_match('/^(?:[A-Za-z]|[IVXivx]{1,4})[.)]?$/u', $row['no']);
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
