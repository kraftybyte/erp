<?php

namespace App\Services;

use App\Filament\Resources\AdvanceResource;
use App\Filament\Resources\ExpenseResource;
use App\Models\Advance;
use App\Models\Expense;
use App\Models\Realisasi;
use App\Models\RealisasiItem;
use App\Models\User;
use App\Services\Concerns\WritesExcelTables;
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
    use WritesExcelTables;

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
            // Tanggal pembelian di struk/bukti bayar (dibaca AI), bisa jauh sebelum dicatat.
            $item->receipt_date,
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
            ['Dicatat', 13, 'date'],
            ['Tanggal struk', 13, 'date'],
            ['Item', 28, 'wrap'],
            ['Keterangan', 40, 'wrap'],
            ['Qty', 7, 'number'],
            ['Harga', 16, 'money'],
            ['Subtotal', 16, 'money'],
            ['Bukti', 10, 'link'],
        ], $items->all());
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

}
