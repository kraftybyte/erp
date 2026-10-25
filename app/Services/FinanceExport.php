<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Seluruh data advance, expense, dan invoice dalam satu file Excel, untuk
 * hal yang tidak muat di pesan Telegram. Angkanya dari FinanceOverview,
 * jadi sama persis dengan yang tampil di bot.
 */
class FinanceExport
{
    private const MONEY = '#,##0';

    public function __construct(private FinanceOverview $overview) {}

    /**
     * @return string path file sementara; pemanggil yang menghapusnya
     */
    public function toFile(): string
    {
        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);

        $this->summarySheet($book);
        $this->invoiceSheet($book);
        $this->expenseSheet($book);
        $this->advanceSheet($book);
        $this->projectSheet($book);

        $book->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'keuangan-') . '.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    private function summarySheet(Spreadsheet $book): void
    {
        $invoice = $this->overview->invoiceBreakdown();
        $expense = $this->overview->expenses();
        $advance = $this->overview->advances();

        $rows = [
            ['Ringkasan keuangan — seluruh waktu', null],
            ['Per', now()->locale('id')->translatedFormat('j F Y, H.i')],
            [null, null],
            ['INVOICE (penjualan)', null],
            ['Nilai pekerjaan', $invoice['contract_value']],
            ['Sudah dibayar', $invoice['paid']],
            ['Sudah ditagih, belum dibayar', $invoice['unpaid_billed']['amount']],
            ['  …lewat jatuh tempo', $invoice['overdue']['amount']],
            ['Belum ditagih (sisa DP tanpa pelunasan)', $invoice['unbilled']['amount']],
            ['DP ditagih', $invoice['kinds']['dp']['billed']],
            ['DP dibayar', $invoice['kinds']['dp']['paid']],
            ['Pelunasan ditagih', $invoice['kinds']['final']['billed']],
            ['Pelunasan dibayar', $invoice['kinds']['final']['paid']],
            ['Bayar penuh ditagih', $invoice['kinds']['full']['billed']],
            ['Bayar penuh dibayar', $invoice['kinds']['full']['paid']],
            [null, null],
            ['EXPENSE (tanpa yang ditolak)', null],
            ['Total diajukan', $expense['total']['amount']],
            ['Sudah dibayar', $expense['paid']['amount']],
            ['Disetujui, belum dibayar', $expense['approved']['amount']],
            ['Menunggu persetujuan', $expense['pending']['amount']],
            ['Ditolak', $expense['rejected']['amount']],
            [null, null],
            ['ADVANCE (tanpa yang ditolak)', null],
            ['Total diajukan', $advance['total']['amount']],
            ['Sudah dicairkan', $advance['disbursed']['amount']],
            ['Direalisasi', $advance['realized']],
            ['Sisa dikembalikan', $advance['returned']],
            ['Belum dipertanggungjawabkan', $advance['outstanding']['amount']],
            ['Menunggu proses', $advance['pending']['amount'] + $advance['approved']['amount']],
            ['Ditolak', $advance['rejected']['amount']],
        ];

        $sheet = $this->sheet($book, 'Ringkasan', ['Keterangan', 'Nominal (Rp)'], $rows, moneyColumns: ['B']);
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(13);

        foreach ([5, 18, 25] as $row) {
            $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        }

        if ($invoice['unlinked_finals']) {
            $sheet->setCellValue('A34', "Catatan: {$invoice['unlinked_finals']} invoice pelunasan tidak bisa dipasangkan ke invoice DP-nya, sehingga nilai pekerjaan & sisa belum ditagih bisa kurang akurat. Isi kolom invoice DP pada invoice pelunasan tersebut di ERP.");
        }
    }

    private function invoiceSheet(Spreadsheet $book): void
    {
        $kinds  = ['dp' => 'DP', 'final' => 'Pelunasan', 'full' => 'Bayar penuh'];
        $states = ['issued' => 'Terbit', 'draft' => 'Draft', 'cancelled' => 'Batal'];
        $all    = $this->overview->saleInvoices()->keyBy(fn ($row) => $row['invoice']->id);

        $rows = $all->map(function (array $row) use ($kinds, $states, $all) {
            $invoice = $row['invoice'];
            $kind    = $kinds[$row['kind']] . ($row['kind'] === 'dp' ? ' ' . $invoice->dpPercentage() . '%' : '');

            return [
                $invoice->uuid,
                $this->date($invoice->date),
                $this->date($invoice->due_date),
                $invoice->nama_perusahaan,
                $kind,
                $states[$row['state']],
                (string) $invoice->status,
                $row['state'] === 'issued' ? $row['contract_value'] : null,
                $row['due'],
                $row['paid'],
                $row['state'] === 'issued' ? $row['outstanding'] : null,
                $row['unbilled'] ?: null,
                $row['overdue_days'] ?: null,
                $row['pair_id'] ? ($all[$row['pair_id']]['invoice']->uuid ?? null) : null,
            ];
        })->values()->all();

        $this->sheet($book, 'Invoice', [
            'No. invoice', 'Tanggal', 'Jatuh tempo', 'Klien', 'Jenis', 'Keadaan', 'Status ERP',
            'Nilai pekerjaan', 'Ditagihkan', 'Dibayar', 'Belum dibayar', 'Belum ditagih', 'Lewat (hari)', 'Pasangan DP/pelunasan',
        ], $rows, moneyColumns: ['H', 'I', 'J', 'K', 'L']);
    }

    private function expenseSheet(Spreadsheet $book): void
    {
        $rows = $this->overview->expenseRows()->map(fn (Expense $expense) => [
            $this->date($expense->submission_date),
            $this->name($expense->user),
            $this->overview->projectLabel($expense->project),
            $expense->category,
            $expense->keterangan_expense,
            (float) $expense->amount,
            $expense->status,
            $this->overview->isExpensePaid($expense) ? ((float) $expense->paid > 0 ? (float) $expense->paid : (float) $expense->amount) : null,
        ])->all();

        $this->sheet($book, 'Expense', ['Tanggal', 'Pengaju', 'Project', 'Kategori', 'Keterangan', 'Nominal', 'Status', 'Dibayar'], $rows, moneyColumns: ['F', 'H']);
    }

    private function advanceSheet(Spreadsheet $book): void
    {
        $rows = $this->overview->advanceRows()->map(fn (array $row) => [
            $this->date($row['advance']->submission_date),
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
        ])->all();

        $this->sheet($book, 'Advance', [
            'Tanggal', 'Pengaju', 'Project', 'Kategori', 'Keterangan', 'Nominal', 'Status',
            'Dicairkan', 'Direalisasi', 'Dikembalikan', 'Belum dipertanggungjawabkan',
        ], $rows, moneyColumns: ['F', 'H', 'I', 'J', 'K']);
    }

    private function projectSheet(Spreadsheet $book): void
    {
        $expense = $this->overview->expenseByProject()->keyBy('label');
        $advance = $this->overview->advanceByProject()->keyBy('label');

        $rows = $expense->keys()->merge($advance->keys())->unique()->sort()->map(fn (string $label) => [
            $label,
            $expense[$label]['amount'] ?? null,
            $expense[$label]['paid'] ?? null,
            $advance[$label]['amount'] ?? null,
            $advance[$label]['disbursed'] ?? null,
            $advance[$label]['realized'] ?? null,
            $advance[$label]['outstanding'] ?? null,
        ])->values()->all();

        $this->sheet($book, 'Per Project', [
            'Project', 'Expense diajukan', 'Expense dibayar', 'Advance diajukan', 'Advance dicairkan', 'Advance direalisasi', 'Advance belum dipertanggungjawabkan',
        ], $rows, moneyColumns: ['B', 'C', 'D', 'E', 'F', 'G']);
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, string>  $moneyColumns
     */
    private function sheet(Spreadsheet $book, string $title, array $header, array $rows, array $moneyColumns): Worksheet
    {
        $sheet = $book->createSheet()->setTitle($title);
        $sheet->fromArray([$header, ...$rows], null, 'A1', true);

        $lastColumn = $sheet->getHighestColumn();
        $lastRow    = max(2, count($rows) + 1);

        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $sheet->freezePane('A2');

        foreach ($moneyColumns as $column) {
            $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getNumberFormat()->setFormatCode(self::MONEY);
        }

        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return $sheet;
    }

    private function date(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->format('Y-m-d') : null;
    }

    private function name(?User $user): string
    {
        return $user ? (trim("{$user->firstname} {$user->lastname}") ?: (string) $user->username) : '-';
    }
}
