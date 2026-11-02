<?php

namespace App\Filament\Resources\InvoiceResource\Widgets;

use App\Helpers\Money;
use App\Models\Invoice;
use App\Services\FinanceOverview;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Ringkasan invoice dalam Rupiah. "Sudah dibayar" = uang yang benar-benar
 * diterima (kolom paid), bukan harga penuh invoice DP yang sudah lunas DP-nya;
 * "Belum dibayar" = sisa tagihan menurut Invoice::amountDue() (DP: uang
 * mukanya, pelunasan: harga penuh dikurangi DP), tanpa proforma.
 */
class InvoiceStatsWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $sales = Invoice::query()
            ->where('type', 'sale')
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', FinanceOverview::INVOICE_CANCELLED));

        $due = (clone $sales)
            ->with('parentInvoice')
            ->get()
            ->sum(fn (Invoice $invoice) => FinanceOverview::invoiceUnpaid($invoice));

        return [
            Stat::make('Jumlah invoice', number_format(Invoice::query()->whereNull('deleted_at')->count(), 0, ',', '.'))
                ->icon('heroicon-o-document-text'),
            Stat::make('Sudah dibayar', Money::rupiah((clone $sales)->sum('paid')))
                ->description('Uang yang sudah diterima dari invoice penjualan')
                ->icon('heroicon-o-check-circle'),
            Stat::make('Belum dibayar', Money::rupiah($due))
                ->description('Sisa tagihan invoice penjualan yang belum lunas, termasuk draft')
                ->icon('heroicon-o-clock'),
        ];
    }
}
