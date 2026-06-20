<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use TomatoPHP\FilamentInvoices\Models\Invoice;

class InvoiceStatusChart extends ChartWidget
{
    protected static ?string $heading = 'Distribusi Status Invoice';
    protected int|string|array $columnSpan = 1;


    protected function getData(): array
    {
        $data = Invoice::query()
            ->selectRaw("
                COALESCE(SUM(CASE WHEN status = 'paid' THEN grand_total ELSE 0 END), 0) as paid,
                COALESCE(SUM(CASE WHEN status = 'pending' THEN grand_total ELSE 0 END), 0) as pending,
                COALESCE(SUM(CASE WHEN status = 'cancelled' THEN grand_total ELSE 0 END), 0) as cancelled,
                COALESCE(SUM(CASE WHEN status = 'draft' THEN grand_total ELSE 0 END), 0) as draft
            ")
            ->first();

        return [
            'datasets' => [
                [
                    'label' => 'Total Amount',
                    'data' => [
                        $data->paid ?? 0,
                        $data->pending ?? 0,
                        $data->cancelled ?? 0,
                        $data->draft ?? 0,
                    ],
                    'backgroundColor' => [
                        'rgb(34, 197, 94)',
                        'rgb(234, 179, 8)',
                        'rgb(239, 68, 68)',
                        'rgb(156, 163, 175)',
                    ],
                ],
            ],
            'labels' => ['Paid', 'Pending', 'Cancelled', 'Draft'],
        ];
    }

    public static function canView(): bool
{
    return true;
}


    protected function getType(): string
    {
        return 'doughnut';
    }
}