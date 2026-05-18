<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Leads;
use App\Models\Deal;
use App\Models\Quotation;
use TomatoPHP\FilamentInvoices\Models\Invoice;

class FunnelIntelligenceEngine
{
    public function snapshot(): array
    {
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();

        // ================= SALES FUNNEL =================
        $leads = Leads::whereBetween('created_at', [$monthStart, $now])->count();

        $quotations = Quotation::whereBetween('created_at', [$monthStart, $now])->count();

        $deals = Deal::whereBetween('created_at', [$monthStart, $now])->count();

        $leadToQuoteRate = $leads > 0
            ? round(($quotations / $leads) * 100, 2)
            : 0;

        $quoteToDealRate = $quotations > 0
            ? round(($deals / $quotations) * 100, 2)
            : 0;

        // ================= CASH FUNNEL =================
        $proforma = Invoice::where('type', 'estimate')
            ->whereBetween('created_at', [$monthStart, $now])
            ->get();

        $invoiceSales = Invoice::where('type', 'sale')
            ->whereBetween('created_at', [$monthStart, $now])
            ->get();

        $proformaValue = $proforma->sum('grand_total');
        $proformaPaid = $proforma->sum('paid');

        $dpCoverage = $proformaValue > 0
            ? round(($proformaPaid / $proformaValue) * 100, 2)
            : 0;

        $overdueInvoices = $invoiceSales
            ->filter(fn ($i) => $i->due_date && $i->due_date < $now && $i->paid < $i->grand_total);

        // DSO = rata-rata hari dari invoice dibuat sampai lunas
        $paidInvoices = $invoiceSales->filter(fn ($i) => $i->paid >= $i->grand_total);

        $dso = $paidInvoices->count() > 0
            ? round($paidInvoices->avg(fn ($i) =>
                Carbon::parse($i->created_at)
                    ->diffInDays(Carbon::parse($i->updated_at))
            ), 1)
            : 0;

        return [
            'sales_funnel' => [
                'leads' => $leads,
                'quotations' => $quotations,
                'deals' => $deals,
                'lead_to_quote_rate' => $leadToQuoteRate,
                'quote_to_deal_rate' => $quoteToDealRate,
            ],

            'cash_funnel' => [
                'proforma_value' => round($proformaValue, 2),
                'dp_coverage_percent' => $dpCoverage,
                'overdue_invoices' => $overdueInvoices->count(),
                'dso_days' => $dso,
            ],
        ];
    }
}
