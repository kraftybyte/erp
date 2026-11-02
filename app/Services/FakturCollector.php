<?php

namespace App\Services;

use App\Enums\ExpenseStatus;
use App\Helpers\ClientLabel;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\RealisasiItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ZipArchive;

/**
 * Pengepulan faktur per periode, untuk akuntan dan lapor PPN.
 *
 * Keluaran: invoice penjualan ber-PPN beserta nomor faktur pajaknya. Invoice DP
 * dan invoice pelunasan sama-sama menyimpan harga penuh pekerjaan, jadi DPP dan
 * PPN-nya dihitung dari porsi yang ditagih lewat invoice itu. Tanpa itu PPN satu
 * pekerjaan terhitung dua kali (sekali di DP, sekali lagi di pelunasan).
 *
 * Masukan: nota Expense dan struk Realisasi Advance, file-nya di disk public.
 */
class FakturCollector
{
    public const ROLES = ProjectProfit::ROLES;

    private const MONEY = '#,##0';

    public function __construct(private FinanceOverview $overview) {}

    public static function visibleTo(?User $user): bool
    {
        return (bool) $user?->hasRole(self::ROLES);
    }

    /**
     * Invoice penjualan ber-PPN pada periode (tanpa proforma dan yang dibatalkan).
     *
     * @return Collection<int, array{invoice: Invoice, kind: string, share: float, dpp: float, ppn: float, faktur: ?string, note: ?string}>
     */
    public function keluaran(ReportPeriod $period): Collection
    {
        return $this->overview->forPeriod($period)->invoiceRows()
            ->load('invoicesItems', 'parentInvoice')
            ->sortBy([['date', 'asc'], ['id', 'asc']])
            ->map(function (Invoice $invoice) {
                [$dpp, $ppn]            = $this->taxBase($invoice);
                [$share, $kind, $note] = $this->share($invoice);

                return [
                    'invoice' => $invoice,
                    'kind'    => $kind,
                    'share'   => $share,
                    'dpp'     => round($dpp * $share, 2),
                    'ppn'     => round($ppn * $share, 2),
                    'faktur'  => self::fakturNumber($invoice->faktur),
                    'note'    => $note,
                ];
            })
            ->filter(fn (array $row) => $row['ppn'] > 0)
            ->values();
    }

    /**
     * Nota Expense (tanpa yang ditolak) dan struk Realisasi pada periode.
     * Setiap file sudah diberi nama di dalam ZIP, supaya daftar Excel dan isi
     * ZIP-nya bisa dicocokkan.
     *
     * 'record' = Expense, atau Advance untuk realisasi. 'target' = tujuan upload
     * faktur: "expense:<id>" atau "realisasi:<id item>".
     *
     * @return Collection<int, array{source: string, date: Carbon, user: string, project: string, description: string, amount: float, files: array<int, array{path: string, exists: bool, zip: ?string}>, record: Model, target: string}>
     */
    public function masukan(ReportPeriod $period): Collection
    {
        $expenses = $this->overview->forPeriod($period)->expenseRows()
            ->reject(fn (Expense $expense) => $expense->status === ExpenseStatus::Ditolak->value)
            ->map(fn (Expense $expense) => [
                'source'      => 'Expense',
                'date'        => Carbon::parse($expense->submission_date),
                'user'        => $this->name($expense->user),
                'project'     => ClientLabel::project($expense->project),
                'description' => (string) $expense->keterangan_expense,
                'amount'      => (float) $expense->amount,
                'files'       => filled($expense->attachment) ? [$this->file($expense->attachment)] : [],
                'record'      => $expense,
                'target'      => "expense:{$expense->id}",
            ]);

        $items = $period->apply(
            RealisasiItem::with('files', 'realizasi.advance.user', 'realizasi.advance.project.deal')
                ->join('realisasi_batches', 'realisasi_batches.id', '=', 'realisasi_items.realisasi_id')
                ->whereNull('realisasi_batches.deleted_at')
                ->select('realisasi_items.*', 'realisasi_batches.created_at as batch_date'),
            'realisasi_batches.created_at'
        )
            ->orderBy('realisasi_batches.created_at')
            ->orderBy('realisasi_items.id')
            ->get()
            ->filter(fn (RealisasiItem $item) => $item->realizasi?->advance)
            ->map(fn (RealisasiItem $item) => [
                'source'      => 'Realisasi',
                'date'        => Carbon::parse($item->batch_date),
                'user'        => $this->name($item->realizasi->advance->user),
                'project'     => ClientLabel::project($item->realizasi->advance->project),
                'description' => trim($item->name . ($item->description ? ' — ' . $item->description : '')),
                'amount'      => (float) $item->subtotal,
                'files'       => $item->files->map(fn ($file) => $this->file($file->filepath))->all(),
                'record'      => $item->realizasi->advance,
                'target'      => "realisasi:{$item->id}",
            ]);

        return $this->nameZipEntries($expenses->concat($items)->sortBy(fn (array $row) => $row['date']->timestamp)->values());
    }

    /**
     * Nomor faktur yang benar-benar terisi; "-", "0", atau kosong dianggap belum ada.
     */
    public static function fakturNumber(?string $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/[1-9]/', $value) ? $value : null;
    }

    /**
     * Excel dua lembar: Keluaran dan Masukan.
     *
     * @return string path file sementara; pemanggil yang menghapusnya
     */
    public function excel(ReportPeriod $period): string
    {
        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);

        $keluaran = $this->keluaran($period);
        $this->sheet($book, 'Faktur Keluaran', [
            'Tanggal', 'No. invoice', 'Klien', 'Jenis', 'DPP', 'PPN', 'No. faktur pajak', 'Catatan',
        ], [
            ...$keluaran->map(fn (array $row) => [
                $row['invoice']->date ? Carbon::parse($row['invoice']->date)->format('Y-m-d') : null,
                $row['invoice']->uuid,
                $row['invoice']->nama_perusahaan,
                $row['kind'],
                $row['dpp'],
                $row['ppn'],
                $row['faktur'] ?? 'BELUM ADA',
                $row['note'],
            ])->all(),
            [null, null, null, 'Total', $keluaran->sum('dpp'), $keluaran->sum('ppn'), null, null],
        ], moneyColumns: ['E', 'F'], textColumns: ['G']);

        $masukan = $this->masukan($period);
        $this->sheet($book, 'Faktur Masukan', [
            'Tanggal', 'Sumber', 'Pengaju', 'Project', 'Keterangan', 'Nominal', 'File di ZIP',
        ], [
            ...$masukan->map(fn (array $row) => [
                $row['date']->format('Y-m-d'),
                $row['source'],
                $row['user'],
                $row['project'],
                $row['description'],
                $row['amount'],
                $this->fileList($row['files']),
            ])->all(),
            [null, null, null, null, 'Total', $masukan->sum('amount'), null],
        ], moneyColumns: ['F'], textColumns: []);

        $book->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'faktur-') . '.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    /**
     * Semua file faktur masukan pada periode, per sumber, beserta daftar Excel-nya.
     *
     * @return string path file sementara; pemanggil yang menghapusnya
     */
    public function zip(ReportPeriod $period, string $excelName): string
    {
        $path  = tempnam(sys_get_temp_dir(), 'faktur-') . '.zip';
        $excel = $this->excel($period);
        $disk  = Storage::disk('public');

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFile($excel, $excelName);

        foreach ($this->masukan($period) as $row) {
            foreach ($row['files'] as $file) {
                if ($file['zip']) {
                    $zip->addFile($disk->path($file['path']), $file['zip']);
                }
            }
        }

        // File baru dibaca saat close, jadi Excel sementaranya dihapus sesudahnya.
        $zip->close();
        @unlink($excel);

        return $path;
    }

    /**
     * DPP dan PPN penuh dari item yang ber-PPN, cara hitung yang sama dengan
     * PDF invoice. Item tanpa PPN tidak masuk DPP.
     *
     * @return array{0: float, 1: float}
     */
    private function taxBase(Invoice $invoice): array
    {
        $dpp = 0.0;
        $ppn = 0.0;

        foreach ($invoice->invoicesItems as $item) {
            $rate = (float) $item->vat_percentage;

            if ($rate <= 0) {
                continue;
            }

            $base = max((float) $item->price * (float) ($item->qty ?? 1) - (float) $item->discount, 0);
            $dpp += $base;
            $ppn += $base * $rate / 100;
        }

        // Invoice lama tanpa item tapi dengan total PPN tersimpan.
        if ($ppn <= 0 && (float) $invoice->total_vat > 0 && $invoice->invoicesItems->isEmpty()) {
            return [(float) $invoice->subtotal - (float) $invoice->total_discount, (float) $invoice->total_vat];
        }

        return [$dpp, $ppn];
    }

    /**
     * Porsi harga penuh yang ditagih lewat invoice ini, label jenisnya, dan
     * catatan bila porsinya tidak bisa dipastikan.
     *
     * @return array{0: float, 1: string, 2: ?string}
     */
    private function share(Invoice $invoice): array
    {
        if (($dp = $invoice->dpPercentage()) !== null) {
            return [$dp / 100, "DP {$dp}%", null];
        }

        if ($invoice->type_payment !== 'final_payment') {
            return [1.0, 'Full payment', null];
        }

        // Sumber DP yang sama dengan PDF dan tombol Bayar (Invoice::settledDp()).
        $paidDp = $invoice->settledDp()['percent'] ?? null;

        return $paidDp !== null && $paidDp > 0 && $paidDp < 100
            ? [1 - $paidDp / 100, 'Pelunasan ' . (100 - $paidDp) . '%', null]
            : [1.0, 'Pelunasan', 'Persen DP-nya tidak diketahui, jadi DPP dan PPN dihitung penuh. Periksa sebelum lapor.'];
    }

    /**
     * @return array{path: string, exists: bool, zip: ?string}
     */
    private function file(string $path): array
    {
        return ['path' => $path, 'exists' => Storage::disk('public')->exists($path), 'zip' => null];
    }

    /**
     * "Expense/2026-10-03 Budi - Grab ke kantor klien - 150000.jpg"; unik di dalam ZIP.
     */
    private function nameZipEntries(Collection $rows): Collection
    {
        $used = [];

        return $rows->map(function (array $row) use (&$used) {
            foreach ($row['files'] as $index => $file) {
                if (! $file['exists']) {
                    continue;
                }

                $base = $row['source'] . '/' . $this->safe(
                    $row['date']->format('Y-m-d') . ' ' . $row['user'] . ' - ' . Str::limit($row['description'], 50, '') . ' - ' . (int) round($row['amount'])
                );
                $ext  = strtolower(pathinfo($file['path'], PATHINFO_EXTENSION)) ?: 'bin';
                $name = "{$base}.{$ext}";

                for ($n = 2; isset($used[strtolower($name)]); $n++) {
                    $name = "{$base} ({$n}).{$ext}";
                }

                $used[strtolower($name)]       = true;
                $row['files'][$index]['zip'] = $name;
            }

            return $row;
        });
    }

    private function safe(string $name): string
    {
        return trim(preg_replace(['/[\\\\\/:*?"<>|\x00-\x1F]+/u', '/\s+/u'], [' ', ' '], $name));
    }

    private function fileList(array $files): string
    {
        if (! $files) {
            return 'BELUM ADA';
        }

        return collect($files)
            ->map(fn (array $file) => $file['zip'] ?? 'tidak ditemukan di server: ' . $file['path'])
            ->implode("\n");
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows  baris terakhir = total
     * @param  array<int, string>  $moneyColumns
     * @param  array<int, string>  $textColumns  disimpan sebagai teks, mis. nomor faktur berawalan 0
     */
    private function sheet(Spreadsheet $book, string $title, array $header, array $rows, array $moneyColumns, array $textColumns): Worksheet
    {
        $sheet = $book->createSheet()->setTitle($title);
        $sheet->fromArray([$header, ...$rows], null, 'A1', true);

        $lastColumn = $sheet->getHighestColumn();
        $lastRow    = count($rows) + 1;

        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $sheet->getStyle("A{$lastRow}:{$lastColumn}{$lastRow}")->getFont()->setBold(true);
        $sheet->freezePane('A2');

        foreach ($moneyColumns as $column) {
            $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getNumberFormat()->setFormatCode(self::MONEY);
        }

        // Ditulis ulang dari data asal: fromArray sudah mengubah "04002500012345678"
        // menjadi angka, nol di depannya hilang dan digit terakhirnya bergeser.
        foreach ($textColumns as $column) {
            $index = Coordinate::columnIndexFromString($column) - 1;

            foreach ($rows as $i => $values) {
                if (($values[$index] ?? null) !== null) {
                    $sheet->setCellValueExplicit($column . ($i + 2), (string) $values[$index], DataType::TYPE_STRING);
                }
            }
        }

        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return $sheet;
    }

    private function name(?User $user): string
    {
        return $user ? (trim("{$user->firstname} {$user->lastname}") ?: (string) $user->username) : '-';
    }
}
