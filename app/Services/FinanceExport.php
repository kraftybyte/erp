<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Data advance, expense, dan invoice (seluruh waktu atau satu periode) dalam satu file Excel, untuk
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
    public function toFile(?ReportPeriod $period = null): string
    {
        $this->overview = $this->overview->forPeriod($period ?? ReportPeriod::all());

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
        $book->disconnectWorksheets();

        return $path;
    }

    private function summarySheet(Spreadsheet $book): void
    {
        $invoice = $this->overview->invoiceTotals();
        $expense = $this->overview->expenses();
        $advance = $this->overview->advances();

        $rows = [
            ['Ringkasan keuangan — ' . $this->overview->period()->label(), null],
            ['Per', now()->locale('id')->translatedFormat('j F Y, H.i')],
            [null, null],
            ['INVOICE MASUK (penjualan; tanpa proforma & yang dibatalkan)', null],
            ['Invoice DP', $invoice['dp']['amount']],
            ['Invoice full payment', $invoice['full']['amount']],
            ['Invoice pelunasan', $invoice['final']['amount']],
            ['Total', $invoice['total']['amount']],
            ['Belum dibayarkan (Total − Paid, belum berstatus paid)', $invoice['unpaid']['amount']],
            [null, null],
            ['EXPENSE (tanpa yang ditolak)', null],
            ['Total', $expense['total']['amount']],
            ['Ditolak (tidak dihitung)', $expense['rejected']['amount']],
            [null, null],
            ['ADVANCE (tanpa yang ditolak)', null],
            ['Total', $advance['total']['amount']],
            ['Belum dibayarkan (Pending & Disetujui)', $advance['pending']['amount'] + $advance['approved']['amount']],
            ['Sudah dicairkan', $advance['disbursed']['amount']],
            ['Direalisasi', $advance['realized']],
            ['Sisa dikembalikan', $advance['returned']],
            ['Belum dipertanggungjawabkan', $advance['outstanding']['amount']],
            ['Ditolak (tidak dihitung)', $advance['rejected']['amount']],
        ];

        $sheet = $this->sheet($book, 'Ringkasan', ['Keterangan', 'Nominal (Rp)'], $rows, moneyColumns: ['B']);
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(13);

        // Judul bagian: baris berlabel tanpa nominal (baris 1 lembar = judul kolom).
        foreach ($rows as $i => [$label, $value]) {
            if ($i > 1 && $label !== null && $value === null) {
                $sheet->getStyle('A' . ($i + 2))->getFont()->setBold(true);
            }
        }
    }

    private function invoiceSheet(Spreadsheet $book): void
    {
        $kinds = ['dp' => 'DP', 'final' => 'Pelunasan', 'full' => 'Full payment'];

        $rows = $this->overview->invoiceRows()->map(fn (Invoice $invoice) => [
            $invoice->uuid,
            $this->date($invoice->date),
            $this->date($invoice->due_date),
            $invoice->nama_perusahaan,
            $kinds[FinanceOverview::invoiceKind($invoice)] . ($invoice->dpPercentage() !== null ? ' ' . $invoice->dpPercentage() . '%' : ''),
            (string) $invoice->status,
            (float) $invoice->grand_total,
            (float) $invoice->paid,
            FinanceOverview::invoiceUnpaid($invoice) ?: null,
        ])->all();

        $this->sheet($book, 'Invoice', [
            'No. invoice', 'Tanggal', 'Jatuh tempo', 'Klien', 'Jenis', 'Status', 'Total', 'Paid', 'Belum dibayarkan',
        ], $rows, moneyColumns: ['G', 'H', 'I']);
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
            $this->overview->expensePaidAmount($expense) ?: null,
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
