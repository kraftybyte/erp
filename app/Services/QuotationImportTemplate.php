<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Template Excel untuk tombol "Import dari Excel" di quotation dan invoice:
 * satu baris = satu item. Kolomnya dibaca QuotationItemImport apa adanya.
 */
class QuotationImportTemplate
{
    public const FILENAME = 'Template Import Item Quotation.xlsx';

    public const HEADERS = ['Item', 'Deskripsi', 'Qty', 'Satuan', 'Harga', 'Total'];

    private const EXAMPLES = [
        ['Persiapan Pekerjaan', 'Mobilisasi dan akomodasi', 1, 'ls', 40_000_000],
        ['Persiapan Pekerjaan', 'Listrik & air kerja', 1, 'ls', 'By Owner'],
        ['Pekerjaan Shopfront', "Facade Logo\nHollow 4x4 t=1,2 Fin. ACP Seven Outdoor Silver", 1, 'ls', 55_000_000],
        ['Pekerjaan Furniture', 'Sofa 3 seater', 2, 'Unit', 13_000_000],
        ['Pekerjaan Elektrikal', 'Instalasi CCTV Wifi', 4, 'ttk', 4_000_000],
    ];

    private const GUIDE = [
        ['Kolom', 'Cara mengisi'],
        ['Item', 'Wajib. Nama item yang tampil di quotation, mis. kategori pekerjaan (Pekerjaan Partisi) atau nama barang.'],
        ['Deskripsi', 'Rincian pekerjaan. Satu poin per baris di dalam sel (Alt+Enter di Windows, Option+Enter di Mac). Boleh kosong.'],
        ['Qty', 'Jumlah (angka). Kosong dianggap 1.'],
        ['Satuan', 'Opsional, mis. Unit, ls, ttk, m2. Bila Qty lebih dari 1, ditulis di deskripsi, mis. "2 Unit".'],
        ['Harga', 'Harga satuan. Boleh 1250000, 1.250.000, atau Rp 1.250.000. Boleh teks seperti "By Owner": item masuk Rp 0 dengan keterangan itu.'],
        ['Total', 'Opsional, = Qty × Harga. Bila diisi tapi berbeda, Total ini yang dipakai (sebagai 1 paket) dan muncul peringatan.'],
        ['', ''],
        ['Aturan', ''],
        ['1 baris = 1 item', 'Isi di sheet "Item Quotation" (sheet pertama). Sheet "Contoh" berisi contoh pengisian.'],
        ['Baris tanpa Harga', 'Dianggap rincian tambahan item di atasnya. Setiap item harus punya Harga (atau teks seperti "By Owner").'],
        ['Baris TOTAL', 'Opsional: tulis TOTAL di kolom Item dan jumlahnya di kolom Total, untuk dicocokkan dengan jumlah item.'],
        ['PPN', 'Tidak diisi di file. Pilih persentasenya di jendela Import.'],
        ['Susunan item', 'Saat import pilih "Setiap baris satu item".'],
    ];

    public function save(string $path): void
    {
        $book = new Spreadsheet();

        $this->itemSheet($book->getActiveSheet(), 'Item Quotation', []);
        $this->itemSheet($book->createSheet(), 'Contoh', self::EXAMPLES);
        $this->guideSheet($book->createSheet());

        // Importer membaca sheet yang aktif saat file disimpan.
        $book->setActiveSheetIndex(0);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: int, 3: string, 4: int|string}>  $rows
     */
    private function itemSheet(Worksheet $sheet, string $title, array $rows): void
    {
        $sheet->setTitle($title);
        $sheet->fromArray(self::HEADERS, null, 'A1');

        $r = 2;
        foreach ($rows as [$item, $description, $qty, $unit, $price]) {
            $sheet->setCellValueExplicit("A{$r}", $item, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("B{$r}", $description, DataType::TYPE_STRING);
            $sheet->setCellValue("C{$r}", $qty);
            $sheet->setCellValue("D{$r}", $unit);
            $sheet->setCellValue("E{$r}", $price);
            $sheet->setCellValue("F{$r}", is_int($price) ? "=C{$r}*E{$r}" : 0);
            $r++;
        }

        if ($rows) {
            $sheet->setCellValue("A{$r}", 'TOTAL');
            $sheet->setCellValue("F{$r}", '=SUM(F2:F' . ($r - 1) . ')');
            $sheet->getStyle("A{$r}:F{$r}")->getFont()->setBold(true);
        }

        $last = max($r, 100);

        $sheet->getStyle('A1:F1')->getFont()->setBold(true);
        $sheet->getStyle('A1:F1')->getFill()->setFillType('solid')->getStartColor()->setRGB('E8EEF7');
        $sheet->getStyle("E2:F{$last}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("A2:F{$last}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getStyle("A2:B{$last}")->getAlignment()->setWrapText(true);

        foreach (['A' => 30, 'B' => 60, 'C' => 7, 'D' => 9, 'E' => 16, 'F' => 18] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $sheet->freezePane('A2');
    }

    private function guideSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Petunjuk');
        $sheet->fromArray(self::GUIDE, null, 'A1');

        $sheet->getStyle('A1:B1')->getFont()->setBold(true);
        $sheet->getStyle('A9')->getFont()->setBold(true);
        $sheet->getStyle('A1:A' . count(self::GUIDE))->getFont()->setBold(true);
        $sheet->getStyle('B1:B' . count(self::GUIDE))->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(100);
    }
}
