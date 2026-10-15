<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class InvoiceRevenueChart extends ChartWidget
{
    protected static ?string $heading = 'Grafik Pendapatan Bulanan';
    protected int|string|array $columnSpan = 3;

    public ?string $filter = '12months';


    public static function canView(): bool
    {
        return true;
    }

    // =========================
    // CHART CONFIG
    // =========================

    protected function getType(): string
    {
        return 'line';
    }

    protected function getFilters(): ?array
    {
        return [
            '6months'  => 'Last 6 months',
            '12months' => 'Last 12 months',
            'year'     => 'This year',
        ];
    }

    protected function getData(): array
    {
        $data = $this->getRevenueData($this->filter);

        return [
            'datasets' => [
                $this->makeDataset(
                    label: 'Paid (Lunas)',
                    data: $data['paid'],
                    borderColor: 'rgb(34, 197, 94)',
                    backgroundColor: 'rgba(34, 197, 94, 0.1)',
                ),
                $this->makeDataset(
                    label: 'Pending',
                    data: $data['pending'],
                    borderColor: 'rgb(234, 179, 8)',
                    backgroundColor: 'rgba(234, 179, 8, 0.1)',
                ),
            ],
            'labels' => $data['labels'],
        ];
    }

    // =========================
    // DATA
    // =========================

    private function getRevenueData(string $filter): array
    {
        [$startDate, $months] = $this->resolveDateRange($filter);

        // Ambil data sekali query, group per bulan
        $rows = Invoice::select(
                DB::raw("DATE_FORMAT(created_at, '%Y-%m') as ym"),
                'status',
                DB::raw('SUM(grand_total) as total')
            )
            ->where('created_at', '>=', $startDate)
            ->whereIn('status', ['paid', 'pending'])
            ->groupBy('ym', 'status')
            ->get()
            ->groupBy(fn ($row) => $row->ym);

        $labels = [];
        $paidData = [];
        $pendingData = [];

        for ($i = 0; $i < $months; $i++) {
            $date = $startDate->copy()->addMonths($i);
            $key = $date->format('Y-m');

            $labels[] = $date->format('M Y');

            $monthRows = $rows->get($key, collect());

            $paidData[] = (float) $monthRows
                ->firstWhere('status', 'paid')
                ?->total ?? 0;

            $pendingData[] = (float) $monthRows
                ->firstWhere('status', 'pending')
                ?->total ?? 0;
        }

        return [
            'labels'  => $labels,
            'paid'   => $paidData,
            'pending'=> $pendingData,
        ];
    }

    private function resolveDateRange(string $filter): array
    {
        $now = Carbon::now();

        return match ($filter) {
            '6months' => [$now->copy()->subMonths(5)->startOfMonth(), 6],
            'year'    => [$now->copy()->startOfYear(), 12],
            default   => [$now->copy()->subMonths(11)->startOfMonth(), 12],
        };
    }

    // =========================
    // HELPERS
    // =========================

    private function makeDataset(
        string $label,
        array $data,
        string $borderColor,
        string $backgroundColor
    ): array {
        return [
            'label' => $label,
            'data' => $data,
            'borderColor' => $borderColor,
            'backgroundColor' => $backgroundColor,
            'fill' => true,
            'tension' => 0.4,
        ];
    }
}
