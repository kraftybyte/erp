<?php

namespace App\Filament\Resources\InvoiceResource\Widgets;

use App\Helpers\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use TomatoPHP\FilamentInvoices\Models\Invoice;

/**
 * Angka yang sama dengan widget bawaan plugin invoice, tetapi dalam Rupiah
 * ("Rp 6.110.000", bukan "6,110,000.00") dan tanpa grafik contoh: plugin
 * menggambar deret angka tetap yang tidak berasal dari data.
 */
class InvoiceStatsWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $query = Invoice::query();

        $due = (clone $query)
            ->whereNotIn('status', ['paid', 'cancelled', 'estimate'])
            ->get()
            ->sum(fn ($invoice) => $invoice->total - $invoice->paid);

        return [
            Stat::make('Jumlah invoice', number_format((clone $query)->count(), 0, ',', '.'))
                ->icon('heroicon-o-document-text'),
            Stat::make('Sudah dibayar', Money::rupiah((clone $query)->where('status', 'paid')->sum('grand_total')))
                ->description('Invoice berstatus Paid')
                ->icon('heroicon-o-check-circle'),
            Stat::make('Belum dibayar', Money::rupiah($due))
                ->description('Sisa tagihan invoice yang belum lunas, termasuk draft')
                ->icon('heroicon-o-clock'),
        ];
    }
}
