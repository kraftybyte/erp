<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\Expense;

class TopExpensesChart extends ChartWidget
{
    protected static ?string $heading = 'Top 15 Expense Terbesar (Smart Category - Rata-rata)';
    public ?string $filter = 'month';

    protected int|string|array $columnSpan = 'full';


    public static function canView(): bool
    {
        return false;
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

        // Ambil total per expense
        $rows = Expense::select(
                'expenses.keterangan_expense',
                DB::raw('SUM(item_expenses.quantity * item_expenses.price) as total')
            )
            ->join('item_expenses', 'item_expenses.expense_id', '=', 'expenses.id')
            ->when($startDate && $endDate, function ($q) use ($startDate, $endDate) {
                $q->whereBetween('expenses.submission_date', [$startDate, $endDate]);
            })
            ->groupBy('expenses.id', 'expenses.keterangan_expense')
            ->get();

        // Grouping pintar per kategori
        $grouped = [];

        foreach ($rows as $row) {
            $category = $this->resolveCategory($row->keterangan_expense);

            if (! isset($grouped[$category])) {
                $grouped[$category] = [
                    'total' => 0,
                    'count' => 0,
                ];
            }

            $grouped[$category]['total'] += (float) $row->total;
            $grouped[$category]['count']++;
        }

        // Hitung rata-rata, urutkan, ambil Top 15
        $averages = collect($grouped)
            ->map(fn ($item) => $item['total'] / max(1, $item['count']))
            ->sortDesc()
            ->take(15);

        return [
            'datasets' => [
                [
                    'label' => 'Rata-rata Expense (Rp)',
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

    'ADS & MARKETING' => [
        'ads', 'meta ads', 'google ads', 'tiktok ads',
        'sosmed', 'marketing', 'promosi',
        'digitalk', 'dgtlk',
        'beyond viral',
        'chatgpt go',
        'outsource ads',
        'ads manager',
    ],

    'SHOOTING & PRODUKSI' => [
        'shot', 'shoot', 'shooting', 'shoting',
        'shot visit', 'visit',
        'produksi', 'konten',
        'property shooting',
        'shoot ct', 'shoot maxhub',
    ],

    'TALENT & CREW' => [
        'talent', 'host', 'operator',
        'nano influencer', 'influencer',
        'crew', 'mua',
        'live jiniso',
        'fee host',
        'fee operator',
    ],

    'MEETING & PITCHING' => [
        'meeting',
        'meetin',
        'offline pitching',
        'pitching',
        'presentasi',
        'visit pt',
    ],

    'TRANSPORT' => [
        'bensin', 'bbm', 'fuel',
        'tol', 'toll',
        'parkir',
        'gocar', 'grab', 'gojek',
        'travel',
        'tanah abang',
    ],

    'KONSUMSI' => [
        'makan', 'konsumsi',
        'bakmie', 'nasi', 'ayam',
        'sei sapi',
        'snack',
        'air mineral', 'air minum',
        'indomaret',
        'puding',
    ],

    'PROPERTI & PERALATAN' => [
        'properti', 'props',
        'kamera', 'softbox', 'godox',
        'perlengkapan',
        'box', 'keranjang',
        'karpet', 'hiasan',
        'atk',
        'kabel', 'lan',
    ],

    'IT & DIGITAL' => [
        'domain', 'hosting',
        'web hosting',
        'internet',
        'akun',
        'capcut',
        'freepik',
        'server',
        'cloud',
    ],

    'LEGAL & ADMIN' => [
        'pph',
        'pajak',
        'stp',
        'bappebti',
        'invoice',
        'meterai',
        'sewa kantor',
    ],

    'GIVEAWAY & PROMO' => [
        'giveaway',
        'bonus',
        'blind box',
        'gummy',
        'hadiah',
        'reward',
    ],
];


        // Level 1: keyword match
        foreach ($map as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $category;
                }
            }
        }

        // Level 2: fallback pintar
        $words = preg_split('/\s+/', preg_replace('/[^a-z0-9\s]/i', '', $text));

        $stopWords = [
            'dan', 'untuk', 'dari', 'yang', 'di', 'ke', 'pada',
            'the', 'of', 'for', 'with', 'by',
        ];

        $filtered = array_values(array_filter($words, function ($word) use ($stopWords) {
            return strlen($word) > 3 && ! in_array($word, $stopWords);
        }));

        if (count($filtered) >= 2) {
            return strtoupper($filtered[0] . ' ' . $filtered[1]);
        }

        return strtoupper($filtered[0] ?? 'LAINNYA');
    }

    // =========================
    // DATE RANGE FILTER
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
