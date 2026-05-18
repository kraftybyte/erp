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
        // Gunakan grand_total
        $paid = Invoice::where('status', 'paid')->sum('grand_total');
        $pending = Invoice::where('status', 'pending')->sum('grand_total');
        $cancelled = Invoice::where('status', 'cancelled')->sum('grand_total');
        $draft = Invoice::where('status', 'draft')->sum('grand_total');
        
        return [
            'datasets' => [
                [
                    'label' => 'Total Amount',
                    'data' => [$paid, $pending, $cancelled, $draft],
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
    return false;
}


    protected function getType(): string
    {
        return 'doughnut';
    }
}