<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Penyusutan aset tetap menurut kelompok harta pajak (UU PPh pasal 11).
 *
 * - Dimulai bulan perolehan, tahun buku = tahun kalender; tahun pertama
 *   dihitung sebanding jumlah bulannya.
 * - Garis lurus: harga perolehan / umur manfaat per tahun.
 * - Saldo menurun: nilai buku × (2 / umur) per tahun; di akhir umur manfaat
 *   sisa nilai bukunya disusutkan sekaligus. Bangunan selalu garis lurus.
 * - Tanpa nilai sisa. Aset yang dijual/dibuang berhenti disusutkan setelah
 *   bulan dilepasnya.
 */
class AssetDepreciation
{
    public const ROLES = ['Director', 'Finance', 'super_admin'];

    public const GROUPS = [
        'kelompok_1'              => ['label' => 'Kelompok 1 (4 tahun)', 'years' => 4, 'hint' => 'komputer, laptop, monitor, printer, kamera, HP, peralatan kecil'],
        'kelompok_2'              => ['label' => 'Kelompok 2 (8 tahun)', 'years' => 8, 'hint' => 'furnitur dan peralatan kantor dari logam, AC, kendaraan'],
        'kelompok_3'              => ['label' => 'Kelompok 3 (16 tahun)', 'years' => 16, 'hint' => 'mesin dan peralatan berat'],
        'kelompok_4'              => ['label' => 'Kelompok 4 (20 tahun)', 'years' => 20, 'hint' => 'alat berat, kapal'],
        'bangunan_permanen'       => ['label' => 'Bangunan permanen (20 tahun)', 'years' => 20, 'hint' => 'gedung, ruko', 'straight_only' => true],
        'bangunan_tidak_permanen' => ['label' => 'Bangunan tidak permanen (10 tahun)', 'years' => 10, 'hint' => 'bangunan semi permanen, gudang sementara', 'straight_only' => true],
        'tidak_disusutkan'        => ['label' => 'Tidak disusutkan', 'years' => null, 'hint' => 'tanah'],
    ];

    public const METHODS = [
        'garis_lurus'   => 'Garis lurus',
        'saldo_menurun' => 'Saldo menurun',
    ];

    public static function visibleTo(?User $user): bool
    {
        return (bool) $user?->hasRole(self::ROLES);
    }

    public static function groupOptions(): array
    {
        return array_map(fn (array $group) => $group['label'], self::GROUPS);
    }

    /**
     * Penyusutan per bulan: ['2026-07' => 2_500_000.0, ...]. Kosong bila
     * kelompoknya belum diisi atau aset tidak disusutkan.
     *
     * @return array<string, float>
     */
    public function months(Asset $asset): array
    {
        $group = self::GROUPS[$asset->kelompok_harta] ?? null;
        $cost  = (float) $asset->harga_barang;

        if (! $group || ! $group['years'] || $cost <= 0 || ! $asset->tanggal_pembelian) {
            return [];
        }

        $declining = $asset->metode_penyusutan === 'saldo_menurun' && empty($group['straight_only']);
        $start     = Carbon::parse($asset->tanggal_pembelian)->startOfMonth();
        $lastMonth = $start->copy()->addMonths($group['years'] * 12 - 1);
        $stop      = $asset->tanggal_dilepas ? Carbon::parse($asset->tanggal_dilepas)->startOfMonth() : null;
        $book      = $cost;
        $months    = [];

        for ($year = $start->year; $year <= $lastMonth->year && $book > 0.005; $year++) {
            $from  = $year === $start->year ? $start->month : 1;
            $to    = $year === $lastMonth->year ? $lastMonth->month : 12;
            $count = $to - $from + 1;

            if ($year === $lastMonth->year) {
                // Akhir umur manfaat: sisa nilai buku disusutkan sekaligus.
                $amount = $book;
            } elseif ($declining) {
                $amount = min($book, $book * (2 / $group['years']) * $count / 12);
            } else {
                $amount = min($book, $cost / $group['years'] * $count / 12);
            }

            for ($month = $from; $month <= $to; $month++) {
                $key = sprintf('%04d-%02d', $year, $month);

                if ($stop && $key > $stop->format('Y-m')) {
                    return $months;
                }

                $months[$key] = $amount / $count;
            }

            $book -= $amount;
        }

        return $months;
    }

    /**
     * Keadaan aset pada akhir bulan $date.
     *
     * @return array{cost: float, monthly: float, accumulated: float, book: float, remaining_months: int, status: string}
     *         status: 'aktif' | 'habis' | 'dilepas' | 'belum_dibeli' | 'tanpa_kelompok' | 'tidak_disusutkan'
     */
    public function at(Asset $asset, ?Carbon $date = null): array
    {
        $key    = ($date ?? now())->format('Y-m');
        $cost   = (float) $asset->harga_barang;
        $months = $this->months($asset);

        $accumulated = array_sum(array_filter($months, fn ($month) => $month <= $key, ARRAY_FILTER_USE_KEY));

        $status = match (true) {
            ! isset(self::GROUPS[$asset->kelompok_harta])                                     => 'tanpa_kelompok',
            $asset->tanggal_dilepas && Carbon::parse($asset->tanggal_dilepas)->format('Y-m') <= $key => 'dilepas',
            $asset->tanggal_pembelian && Carbon::parse($asset->tanggal_pembelian)->format('Y-m') > $key => 'belum_dibeli',
            self::GROUPS[$asset->kelompok_harta]['years'] === null                             => 'tidak_disusutkan',
            $cost - $accumulated <= 0.5                                                         => 'habis',
            default                                                                             => 'aktif',
        };

        return [
            'cost'             => $cost,
            'monthly'          => $months[$key] ?? 0.0,
            'accumulated'      => round($accumulated, 2),
            'book'             => round(max(0, $cost - $accumulated), 2),
            'remaining_months' => count(array_filter($months, fn ($month) => $month > $key, ARRAY_FILTER_USE_KEY)),
            'status'           => $status,
        ];
    }

    /**
     * Jadwal per tahun: penyusutan tahun itu, akumulasi, dan nilai buku akhir tahun.
     *
     * @return array<int, array{year: int, depreciation: float, accumulated: float, book: float}>
     */
    public function yearly(Asset $asset): array
    {
        $cost        = (float) $asset->harga_barang;
        $accumulated = 0.0;
        $rows        = [];

        foreach ($this->months($asset) as $key => $amount) {
            $year = (int) substr($key, 0, 4);
            $rows[$year] ??= ['year' => $year, 'depreciation' => 0.0, 'accumulated' => 0.0, 'book' => 0.0];

            $rows[$year]['depreciation'] += $amount;
            $accumulated                 += $amount;
            $rows[$year]['accumulated']   = $accumulated;
            $rows[$year]['book']          = max(0, $cost - $accumulated);
        }

        return array_values(array_map(fn (array $row) => array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $row), $rows));
    }

    /**
     * Ringkasan aset yang masih dimiliki pada akhir bulan $date.
     *
     * @param  Collection<int, Asset>  $assets
     * @return array{count: int, cost: float, accumulated: float, book: float, monthly: float, without_group: int}
     */
    public function summary(Collection $assets, ?Carbon $date = null): array
    {
        $sum = ['count' => 0, 'cost' => 0.0, 'accumulated' => 0.0, 'book' => 0.0, 'monthly' => 0.0, 'without_group' => 0];

        foreach ($assets as $asset) {
            $state = $this->at($asset, $date);

            if (in_array($state['status'], ['dilepas', 'belum_dibeli'], true)) {
                continue;
            }

            $sum['count']++;
            $sum['cost']          += $state['cost'];
            $sum['accumulated']   += $state['accumulated'];
            $sum['book']          += $state['book'];
            $sum['monthly']       += $state['monthly'];
            $sum['without_group'] += $state['status'] === 'tanpa_kelompok' ? 1 : 0;
        }

        return $sum;
    }

    /**
     * Daftar penyusutan satu tahun buku (untuk akuntan / lampiran SPT).
     *
     * @return string path file sementara; pemanggil yang menghapusnya
     */
    public function excel(int $year): string
    {
        $endPrev = Carbon::create($year - 1, 12, 1);
        $endYear = Carbon::create($year, 12, 1);
        $rows    = [];
        $totals  = [0.0, 0.0, 0.0, 0.0];
        $owned   = 0.0;

        $assets = Asset::query()
            ->whereDate('tanggal_pembelian', '<=', "{$year}-12-31")
            ->where(fn ($q) => $q->whereNull('tanggal_dilepas')->orWhereYear('tanggal_dilepas', '>=', $year))
            ->orderBy('tanggal_pembelian')
            ->orderBy('id')
            ->get();

        foreach ($assets as $i => $asset) {
            $before = $this->at($asset, $endPrev);
            $after  = $this->at($asset, $endYear);

            $values = [
                (float) $asset->harga_barang,
                $before['status'] === 'belum_dibeli' ? 0.0 : $before['accumulated'],
                $after['accumulated'] - ($before['status'] === 'belum_dibeli' ? 0.0 : $before['accumulated']),
                $after['accumulated'],
            ];

            foreach ($values as $k => $value) {
                $totals[$k] += $value;
            }

            // Yang dilepas tahun ini bukan milik lagi di akhir tahun.
            $owned += $after['status'] === 'dilepas' ? 0.0 : $after['book'];

            $rows[] = [
                $i + 1,
                $asset->nama_barang,
                $asset->uuid,
                Carbon::parse($asset->tanggal_pembelian)->format('Y-m-d'),
                self::GROUPS[$asset->kelompok_harta]['label'] ?? 'BELUM DIISI',
                self::METHODS[$asset->metode_penyusutan] ?? '-',
                ...$values,
                $after['book'],
                $asset->tanggal_dilepas ? 'Dilepas ' . Carbon::parse($asset->tanggal_dilepas)->format('Y-m-d') : null,
            ];
        }

        $book  = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle("Penyusutan {$year}");
        $sheet->fromArray([
            ['No', 'Nama aset', 'ID', 'Tanggal perolehan', 'Kelompok', 'Metode', 'Harga perolehan', "Akumulasi s.d. 31 Des " . ($year - 1), "Penyusutan {$year}", "Akumulasi s.d. 31 Des {$year}", "Nilai buku 31 Des {$year}", 'Keterangan'],
            ...$rows,
            [null, 'Total', null, null, null, null, ...$totals, $owned, null],
        ], null, 'A1', true);

        $last = count($rows) + 2;
        $sheet->getStyle('A1:L1')->getFont()->setBold(true);
        $sheet->getStyle("A{$last}:L{$last}")->getFont()->setBold(true);
        $sheet->getStyle("G2:K{$last}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->freezePane('A2');

        foreach (range('A', 'L') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $path = tempnam(sys_get_temp_dir(), 'penyusutan-') . '.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }
}
