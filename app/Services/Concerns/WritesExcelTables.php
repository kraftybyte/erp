<?php

namespace App\Services\Concerns;

use App\Models\User;
use Illuminate\Support\Carbon;
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
 * Tabel Excel bergaya sama untuk semua ekspor ERP: judul, filter yang aktif,
 * siapa yang mengekspor, judul kolom berwarna, baris total, format Rupiah dan
 * tanggal, filter kolom, dan siap cetak landscape A4.
 */
trait WritesExcelTables
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
