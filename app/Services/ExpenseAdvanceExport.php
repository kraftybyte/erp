<?php

namespace App\Services;

use App\Filament\Resources\AdvanceResource;
use App\Filament\Resources\ExpenseResource;
use App\Models\Advance;
use App\Models\Expense;
use App\Models\Realisasi;
use App\Models\RealisasiItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel rapi dari tabel Expense dan Advance di ERP: baris yang sedang tampil
 * (filter, pencarian, urutan), baris total, dan lembar ringkasan. Angka
 * dibayar/dicairkan/direalisasi/sisa dari FinanceOverview, sama dengan bot laporan.
 */
class ExpenseAdvanceExport
{
    private const MONEY = '"Rp "#,##0';

    private const DATE = 'dd mmm yyyy';

    private const HEADER_ROW = 5;

    private const ACCENT = '1F4E79';

    private const MUTED = '6B7280';

    private const GRID = 'D0D7E2';

    // Urutan juga dipakai untuk mengurutkan ringkasan per status.
    private const STATUS_FILLS = [
        'Pending'                  => 'FFF4CC',
        'Disetujui'                => 'DDEBFF',
        'Dibayarkan'               => 'DDF4E4',
        'Sedang di-Realisasi'      => 'EDE4FF',
        'Sedang di-Realisasi User' => 'EDE4FF',
        'Siap Diselesaikan'        => 'DDF4F4',
        'Selesai'                  => 'E5E7EB',
        'Ditolak'                  => 'FDE2E1',
    ];

    public function __construct(private FinanceOverview $overview) {}

    /**
     * @param  array<int, string>  $filters  keterangan filter yang aktif di tabel
     * @return string path file sementara; pemanggil yang menghapusnya
     */
    public function expenses(Builder $query, array $filters = []): string
    {
        $expenses = $query->with('user', 'project.deal', 'paymentAccount')->get();

        $book  = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Expense');

        $this->heading($sheet, 'Laporan Expense', $filters, $expenses->count() . ' pengajuan');
        $this->dataTable($sheet, [
            ['ID', 8, 'link'],
            ['Tanggal', 13, 'date'],
            ['Pengaju', 20, 'text'],
            ['Project', 32, 'wrap'],
            ['Kategori', 16, 'text'],
            ['Keterangan', 45, 'wrap'],
            ['Nominal', 16, 'money'],
            ['Status', 13, 'status'],
            ['Dibayar', 16, 'money'],
            ['Rekening', 18, 'text'],
            ['No. referensi', 18, 'text'],
            ['Nota', 9, 'link'],
            ['Bukti bayar', 11, 'link'],
            ['Alasan ditolak', 28, 'wrap'],
        ], $expenses->map(fn (Expense $expense) => [
            [$expense->id, ExpenseResource::getUrl('view', ['record' => $expense])],
            $expense->submission_date,
            $this->name($expense->user),
            $this->overview->projectLabel($expense->project),
            $expense->category,
            $expense->keterangan_expense,
            (float) $expense->amount,
            $expense->status,
            $this->overview->expensePaidAmount($expense) ?: null,
            $expense->paymentAccount?->name,
            $expense->reference,
            $this->file($expense->attachment),
            $this->file($expense->attachment_pay),
            $expense->status === 'Ditolak' ? $expense->rejection_reason : null,
        ])->all());

        $sums    = [fn (Expense $e) => (float) $e->amount, fn (Expense $e) => $this->overview->expensePaidAmount($e)];
        $active  = $expenses->reject(fn (Expense $e) => $e->status === 'Ditolak');
        $columns = ['Nominal', 'Dibayar'];

        $this->summary($book, 'Ringkasan Expense', $filters, $expenses->count() . ' pengajuan', [
            ['Per status', 'Status', $columns, $this->group($expenses, fn (Expense $e) => $e->status, $sums, byStatus: true)],
            ['Per kategori (tanpa yang ditolak)', 'Kategori', $columns, $this->group($active, fn (Expense $e) => $e->category, $sums)],
            ['Per project (tanpa yang ditolak)', 'Project', $columns, $this->group($active, fn (Expense $e) => $this->overview->projectLabel($e->project), $sums)],
        ]);

        return $this->save($book);
    }

    /**
     * @param  array<int, string>  $filters  keterangan filter yang aktif di tabel
     * @return string path file sementara; pemanggil yang menghapusnya
     */
    public function advances(Builder $query, array $filters = []): string
    {
        $rows = $this->overview->advanceRows($query->with('paymentAccount'));

        $book  = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Advance');

        $this->heading($sheet, 'Laporan Advance', $filters, $rows->count() . ' pengajuan');
        $this->dataTable($sheet, [
            ['ID', 8, 'link'],
            ['Tanggal', 13, 'date'],
            ['Pengaju', 20, 'text'],
            ['Project', 32, 'wrap'],
            ['Kategori', 16, 'text'],
            ['Keterangan', 45, 'wrap'],
            ['Diajukan', 16, 'money'],
            ['Status', 16, 'status'],
            ['Dicairkan', 16, 'money'],
            ['Direalisasi', 16, 'money'],
            ['Dikembalikan', 16, 'money'],
            ['Belum dipertanggungjawabkan', 18, 'money'],
            ['Rekening', 18, 'text'],
            ['Lampiran', 10, 'link'],
            ['Alasan ditolak', 28, 'wrap'],
        ], $rows->map(fn (array $row) => [
            [$row['advance']->id, $this->advanceUrl($row['advance'])],
            $row['advance']->submission_date,
            $this->name($row['advance']->user),
            $this->overview->projectLabel($row['advance']->project),
            $row['advance']->category,
            $row['advance']->keterangan_advance,
            (float) $row['advance']->amount,
            $row['advance']->status,
            $row['disbursed'] ?: null,
            $row['realized'] ?: null,
            $row['returned'] ?: null,
            $row['outstanding'] ?: null,
            $row['advance']->paymentAccount?->name,
            $this->files(array_map($this->storageUrl(...), $row['advance']->attachmentPaths()), $this->advanceUrl($row['advance'])),
            $row['advance']->status === Advance::STATUS_DITOLAK ? $row['advance']->rejection_reason : null,
        ])->all());

        $this->realisasiSheet($book, $rows, $filters);

        $sums    = [
            fn (array $r) => (float) $r['advance']->amount,
            fn (array $r) => $r['disbursed'],
            fn (array $r) => $r['realized'],
            fn (array $r) => $r['returned'],
            fn (array $r) => $r['outstanding'],
        ];
        $active  = $rows->reject(fn (array $r) => $r['advance']->status === Advance::STATUS_DITOLAK);
        $columns = ['Diajukan', 'Dicairkan', 'Direalisasi', 'Dikembalikan', 'Belum dipertanggungjawabkan'];

        $this->summary($book, 'Ringkasan Advance', $filters, $rows->count() . ' pengajuan', [
            ['Per status', 'Status', $columns, $this->group($rows, fn (array $r) => $r['advance']->status, $sums, byStatus: true)],
            ['Per project (tanpa yang ditolak)', 'Project', $columns, $this->group($active, fn (array $r) => $this->overview->projectLabel($r['advance']->project), $sums)],
            ['Per kategori (tanpa yang ditolak)', 'Kategori', $columns, $this->group($active, fn (array $r) => $r['advance']->category, $sums)],
        ]);

        return $this->save($book);
    }

    /**
     * Setiap item realisasi dari advance yang diekspor.
     *
     * @param  Collection<int, array>  $rows
     * @param  array<int, string>  $filters
     */
    private function realisasiSheet(Spreadsheet $book, Collection $rows, array $filters): void
    {
        $advances = $rows->pluck('advance')->keyBy('id');

        $batches = $advances->isEmpty() ? collect() : Realisasi::with('items.files')
            ->whereIn('advance_id', $advances->keys())
            ->orderBy('advance_id')
            ->orderBy('batch_number')
            ->get();

        $items = $batches->flatMap(fn (Realisasi $batch) => $batch->items->map(fn (RealisasiItem $item) => [
            [$batch->advance_id, $this->advanceUrl($advances[$batch->advance_id])],
            $this->name($advances[$batch->advance_id]->user),
            $this->overview->projectLabel($advances[$batch->advance_id]->project),
            'Batch ' . $batch->batch_number,
            $batch->created_at,
            $item->name,
            $item->description,
            (float) $item->quantity,
            (float) $item->price,
            (float) $item->subtotal,
            $this->files($item->files->map->getUrl()->all(), $this->advanceUrl($advances[$batch->advance_id])),
        ]));

        $sheet = $book->createSheet()->setTitle('Realisasi');

        $this->heading($sheet, 'Rincian Realisasi Advance', $filters, $items->count() . ' item');
        $this->dataTable($sheet, [
            ['Advance', 9, 'link'],
            ['Pengaju', 20, 'text'],
            ['Project', 32, 'wrap'],
            ['Batch', 9, 'text'],
            ['Tanggal', 13, 'date'],
            ['Item', 28, 'wrap'],
            ['Keterangan', 40, 'wrap'],
            ['Qty', 7, 'number'],
            ['Harga', 16, 'money'],
            ['Subtotal', 16, 'money'],
            ['Bukti', 10, 'link'],
        ], $items->all());
    }

    private function heading(Worksheet $sheet, string $title, array $filters, string $count): void
    {
        $by = Auth::user();

        $sheet->setCellValue('A1', $title);
        $sheet->setCellValueExplicit('A2', $filters ? 'Filter: ' . implode(' · ', $filters) : 'Semua data', DataType::TYPE_STRING);
        $sheet->setCellValue('A3', 'Diekspor ' . now()->locale('id')->translatedFormat('j F Y, H.i')
            . ($by ? ' oleh ' . $this->name($by) : '') . ' · ' . $count);

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB(self::ACCENT);
        $sheet->getStyle('A2:A3')->getFont()->setSize(9)->getColor()->setRGB(self::MUTED);
    }

    /**
     * Lembar data utama: tabel dengan filter kolom, judul tabel yang tetap
     * terlihat saat digulir, dan siap cetak landscape.
     */
    private function dataTable(Worksheet $sheet, array $columns, array $rows): void
    {
        $total = $this->table($sheet, self::HEADER_ROW, $columns, $rows);
        $last  = Coordinate::stringFromColumnIndex(count($columns));

        $sheet->freezePane('A' . (self::HEADER_ROW + 1));
        $sheet->setAutoFilter('A' . self::HEADER_ROW . ":{$last}" . ($total - 1));
        $this->printSetup($sheet);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(self::HEADER_ROW, self::HEADER_ROW);
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: array<int, string>, 3: array}>  $sections
     *         [judul bagian, judul kolom pertama, judul kolom nominal, baris]
     */
    private function summary(Spreadsheet $book, string $title, array $filters, string $count, array $sections): void
    {
        $sheet = $book->createSheet()->setTitle('Ringkasan');

        $this->heading($sheet, $title, $filters, $count);

        $row = self::HEADER_ROW;

        foreach ($sections as [$section, $label, $moneyColumns, $rows]) {
            $sheet->setCellValue("A{$row}", $section);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true)->getColor()->setRGB(self::ACCENT);

            $columns = [[$label, 42, 'wrap'], ['Jumlah', 9, 'number']];

            foreach ($moneyColumns as $money) {
                $columns[] = [$money, 18, 'money'];
            }

            $row = $this->table($sheet, $row + 1, $columns, $rows) + 2;
        }

        $this->printSetup($sheet);
    }

    /**
     * @param  array<int, array{0: string, 1: int, 2: string}>  $columns  [judul, lebar, jenis]
     *         jenis: text, wrap, money, number, date, status, link ([teks, url])
     * @param  array<int, array<int, mixed>>  $rows
     * @return int nomor baris total
     */
    private function table(Worksheet $sheet, int $top, array $columns, array $rows): int
    {
        $last  = Coordinate::stringFromColumnIndex(count($columns));
        $first = $top + 1;
        $row   = $top;

        foreach ($columns as $i => [$label, $width]) {
            $column = Coordinate::stringFromColumnIndex($i + 1);

            $sheet->setCellValue("{$column}{$top}", $label);
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        foreach ($rows as $values) {
            $row++;

            foreach ($columns as $i => [, , $type]) {
                $this->cell($sheet, Coordinate::stringFromColumnIndex($i + 1) . $row, $type, $values[$i] ?? null);
            }
        }

        if (! $rows) {
            $row++;
            $sheet->setCellValue("A{$row}", 'Tidak ada data');
            $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->getColor()->setRGB(self::MUTED);
        }

        $total = $row + 1;
        $sheet->setCellValue("A{$total}", 'Total');

        foreach ($columns as $i => [, , $type]) {
            $column = Coordinate::stringFromColumnIndex($i + 1);
            $body   = "{$column}{$first}:{$column}{$row}";

            match ($type) {
                'money'  => $sheet->getStyle($body)->getNumberFormat()->setFormatCode(self::MONEY),
                'date'   => $sheet->getStyle($body)->getNumberFormat()->setFormatCode(self::DATE),
                'wrap'   => $sheet->getStyle($body)->getAlignment()->setWrapText(true),
                default  => null,
            };

            if (in_array($type, ['money', 'number'], true)) {
                $sheet->setCellValue("{$column}{$total}", "=SUM({$body})");
                $sheet->getStyle("{$column}{$total}")->getNumberFormat()->setFormatCode($type === 'money' ? self::MONEY : '#,##0');
            }
        }

        $sheet->getStyle("A{$first}:{$last}{$row}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getStyle("A{$top}:{$last}{$total}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::GRID);

        $sheet->getStyle("A{$top}:{$last}{$top}")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::ACCENT]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension($top)->setRowHeight(30);

        $sheet->getStyle("A{$total}:{$last}{$total}")->applyFromArray([
            'font'    => ['bold' => true],
            'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8EEF6']],
            'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => self::ACCENT]]],
        ]);

        return $total;
    }

    private function cell(Worksheet $sheet, string $coordinate, string $type, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if ($type === 'link') {
            [$text, $url] = $value;

            $sheet->setCellValueExplicit($coordinate, $text, is_int($text) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            $sheet->getCell($coordinate)->getHyperlink()->setUrl($url);
            $sheet->getStyle($coordinate)->getFont()->setUnderline(true)->getColor()->setRGB('1D4ED8');

            return;
        }

        if ($type === 'date') {
            $sheet->setCellValue($coordinate, Date::PHPToExcel(Carbon::parse($value)->startOfDay()));

            return;
        }

        if ($type === 'money' || $type === 'number') {
            $sheet->setCellValueExplicit($coordinate, (float) $value, DataType::TYPE_NUMERIC);

            if ($type === 'number' && floor((float) $value) != (float) $value) {
                $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode('#,##0.00');
            }

            return;
        }

        // Teks selalu sebagai teks: keterangan yang diawali "=" tidak dijalankan sebagai rumus.
        $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);

        if ($type === 'status' && isset(self::STATUS_FILLS[$value])) {
            $sheet->getStyle($coordinate)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::STATUS_FILLS[$value]);
        }
    }

    private function printSetup(Worksheet $sheet): void
    {
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0);

        $sheet->getPageMargins()->setLeft(0.4)->setRight(0.4)->setTop(0.5)->setBottom(0.5);
        $sheet->getHeaderFooter()->setOddFooter('&L&8' . $sheet->getTitle() . '&R&8Halaman &P / &N');
        $sheet->setSelectedCell('A1');
    }

    /**
     * Baris ringkasan: [label, jumlah, ...nominal].
     *
     * @param  array<int, callable(mixed): float>  $sums
     * @return array<int, array<int, mixed>>
     */
    private function group(Collection $items, callable $key, array $sums, bool $byStatus = false): array
    {
        $order = array_keys(self::STATUS_FILLS);

        return $items
            ->groupBy(fn ($item) => (string) ($key($item) ?: '-'))
            ->map(fn (Collection $group, $label) => [
                (string) $label,
                $group->count(),
                ...array_map(fn (callable $sum) => (float) $group->sum($sum), $sums),
            ])
            ->sortBy(fn (array $row) => $byStatus
                ? (array_search($row[0], $order, true) === false ? PHP_INT_MAX : array_search($row[0], $order, true))
                : -$row[2])
            ->values()
            ->all();
    }

    private function advanceUrl(Advance $advance): string
    {
        return AdvanceResource::getUrl('view', ['record' => $advance]);
    }

    private function storageUrl(string $path): string
    {
        return asset('storage/' . $path);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function file(?string $path): ?array
    {
        return filled($path) ? ['Lihat', $this->storageUrl($path)] : null;
    }

    /**
     * Satu file langsung ke filenya; beberapa file ke halaman ERP yang menampilkan semuanya.
     *
     * @param  array<int, string>  $urls
     * @return array{0: string, 1: string}|null
     */
    private function files(array $urls, string $page): ?array
    {
        return match (count($urls)) {
            0       => null,
            1       => ['Lihat', reset($urls)],
            default => [count($urls) . ' file', $page],
        };
    }

    private function name(?User $user): string
    {
        return $user ? (trim("{$user->firstname} {$user->lastname}") ?: (string) $user->username) : '-';
    }

    private function save(Spreadsheet $book): string
    {
        $book->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'export-');
        (new Xlsx($book))->save($path);
        // Spreadsheet saling mereferensi; tanpa ini memorinya tidak dibebaskan.
        $book->disconnectWorksheets();

        return $path;
    }
}
