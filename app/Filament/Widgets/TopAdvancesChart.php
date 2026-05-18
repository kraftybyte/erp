<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\Advance;

class TopAdvancesChart extends ChartWidget
{
    protected static ?string $heading = 'Top 15 Advance Terbesar (Smart Category - Rata-rata)';    
    protected int|string|array $columnSpan = 'full';

    public ?string $filter = 'month';

    // =========================
    // VISIBILITY
    // =========================
    public static function canView(): bool
    {
        return false;
    }

    protected function getHeight(): string
    {
        return '400px';
    }

    // =========================
    // FILTERS
    // =========================
    protected function getFilters(): ?array
    {
        return [
            'month' => 'Bulan Ini',
            'year'  => 'Tahun Ini',
            'all'   => 'Sepanjang Waktu',
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    // =========================
    // DATA
    // =========================
    protected function getData(): array
    {
        [$startDate, $endDate] = $this->resolveDateRange($this->filter);

        // =========================
        // QUERY UTAMA
        // =========================
        $rows = Advance::select(
                'advances.keterangan_advance',
                'item_advances.name',
                'item_advances.description',
                DB::raw('SUM(item_advances.quantity * item_advances.price) as total')
            )
            ->join('item_advances', 'item_advances.advance_id', '=', 'advances.id')
            ->when($startDate && $endDate, function ($q) use ($startDate, $endDate) {
                // fallback ke created_at kalau submission_date kosong
                $q->where(function ($sub) use ($startDate, $endDate) {
                    $sub->whereBetween('advances.submission_date', [$startDate, $endDate])
                        ->orWhereBetween('advances.created_at', [$startDate, $endDate]);
                });
            })
            ->groupBy(
                'advances.id',
                'advances.keterangan_advance',
                'item_advances.name',
                'item_advances.description'
            )
            ->get();

        // =========================
        // SMART GROUPING
        // =========================
        $grouped = [];

        foreach ($rows as $row) {
            $categoryText = implode(' ', array_filter([
                $row->keterangan_advance,
                $row->name,
                $row->description,
            ]));

            $category = $this->resolveCategory($categoryText);

            if (! isset($grouped[$category])) {
                $grouped[$category] = [
                    'total' => 0,
                    'count' => 0,
                ];
            }

            $grouped[$category]['total'] += (float) $row->total;
            $grouped[$category]['count']++;
        }

        // =========================
        // RATA-RATA + SORT
        // =========================
        $averages = collect($grouped)
            ->map(fn ($item) => $item['total'] / max(1, $item['count']))
            ->sortDesc()
            ->take(15);

        return [
            'datasets' => [
                [
                    'label' => 'Rata-rata Advance (Rp)',
                    'data' => $averages
                        ->values()
                        ->map(fn ($v) => round($v, 0))
                        ->toArray(),
                ],
            ],
            'labels' => $averages
                ->keys()
                ->map(fn ($v) => ucwords(strtolower($v)))
                ->toArray(),
        ];
    }

    // =========================
    // SMART CATEGORY ENGINE
    // =========================
    private function resolveCategory(string $text): string
    {
        $text = strtolower($text);

        $map = [

            // =========================
            // OPERASIONAL KANTOR
            // =========================
            'OPERASIONAL KANTOR' => [
                'listrik', 'pln', 'token listrik',
                'internet', 'wifi',
                'galon', 'air galon',
                'ac kantor', 'pendingin',
                'kantor',
                'tissue', 'sabun', 'pengharum',
                'tinta printer', 'printer',
                'alat kantor',
            ],

            // =========================
            // LOGISTIK / KURIR
            // =========================
            'LOGISTIK & KURIR' => [
                'paxel', 'jne', 'gosend',
                'resi', 'kirim dokumen',
                'kirim paket', 'pengiriman',
                'ongkos kirim',
            ],

            // =========================
            // KONSUMSI / MAKAN
            // =========================
            'KONSUMSI' => [
                'makan', 'konsumsi', 'gofood',
                'bakmie', 'ayam', 'snack',
                'kue ulang tahun', 'hampers',
                'alfagift', 'coffee', 'foodcourt',
                'bahan makanan', 'daging',
                'hari hari pasar', 'jajan',
            ],

            // =========================
            // PERALATAN / PERLENGKAPAN
            // =========================
            'PERALATAN & PERLENGKAPAN' => [
                'kamera', 'microphone', 'mic',
                'remote ac', 'terpal',
                'alat', 'peralatan',
                'properti',
                'tinta', 'printer',
                'box', 'kardus',
            ],

            // =========================
            // GAJI / JASA / SERVICE
            // =========================
            'GAJI & JASA' => [
                'upah', 'fee', 'jasa',
                'bibi', 'mba',
                'pinhome',
                'service', 'pengecekan',
                'perbaikan', 'instalasi',
                'home carwash',
                'tambal ban',
            ],

            // =========================
            // IT & DIGITAL
            // =========================
            'IT & DIGITAL' => [
                'domain', 'hosting',
                'akun', 'capcut',
                'freepik', 'telkomsel',
                'internet',
                'server',
            ],

            // =========================
            // LEGAL / ADMIN
            // =========================
            'LEGAL & ADMIN' => [
                'invoice', 'materai',
                'pajak', 'pph',
                'dokumen',
            ],

            // =========================
            // TRANSPORT / OPERASIONAL
            // =========================
            'TRANSPORT' => [
                'bensin', 'pertalite',
                'tol', 'toll',
                'parkir',
                'mobil',
            ],

            // =========================
            // SEWA / AKOMODASI
            // =========================
            'SEWA & AKOMODASI' => [
                'sewa', 'villa',
                'hotel', 'akomodasi',
            ],
        ];


        foreach ($map as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $category;
                }
            }
        }

        // Fallback pintar
        $words = preg_split('/\s+/', preg_replace('/[^a-z0-9\s]/i', '', $text));
        $filtered = array_values(array_filter($words, fn ($w) => strlen($w) > 3));

        if (count($filtered) >= 2) {
            return strtoupper($filtered[0] . ' ' . $filtered[1]);
        }

        return strtoupper($filtered[0] ?? 'LAINNYA');
    }

    // =========================
    // DATE RANGE
    // =========================
    private function resolveDateRange(string $filter): array
    {
        $now = Carbon::now();

        return match ($filter) {
            'month' => [
                $now->copy()->startOfMonth(),
                $now->copy()->endOfMonth(),
            ],
            'year' => [
                $now->copy()->startOfYear(),
                $now->copy()->endOfYear(),
            ],
            default => [null, null],
        };
    }
}
