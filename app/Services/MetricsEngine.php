<?php

namespace App\Services;

use Carbon\Carbon;
use TomatoPHP\FilamentInvoices\Models\Invoice;
use App\Models\Advance;
use App\Models\Expense;
use App\Models\Quotation;
use App\Models\Leads;
use App\Models\Deal;

class MetricsEngine
{
    public function generateSnapshot(): array
    {
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $weekStart = $now->copy()->startOfWeek();

        // ================= FINANCE =================
        $invoiceSales = Invoice::where('type', 'sale')
            ->whereBetween('created_at', [$monthStart, $now])
            ->get();

        $proforma = Invoice::where('type', 'estimate')
            ->whereBetween('created_at', [$monthStart, $now])
            ->get();

        $revenue = (float) $invoiceSales->sum('paid'); // uang masuk nyata
        $billed = (float) $invoiceSales->sum('grand_total'); // total ditagih
        $proformaValue = (float) $proforma->sum('grand_total');
        $proformaPaid = (float) $proforma->sum('paid');

        $expenses = (float) Expense::whereBetween('created_at', [$monthStart, $now])
            ->sum('amount');

        $advances = (float) Advance::whereBetween('created_at', [$monthStart, $now])
            ->sum('amount');

        // ================= SALES FUNNEL =================
        $leads = Leads::whereBetween('created_at', [$monthStart, $now])->count();

        $converted = Leads::whereBetween('created_at', [$monthStart, $now])
            ->where('is_converted', 1)
            ->count();

        $quotations = Quotation::whereBetween('created_at', [$monthStart, $now])->count();

        $deals = Deal::whereBetween('created_at', [$monthStart, $now])->count();

        // ================= WEEKLY =================
        $weeklyRevenue = (float) Invoice::where('type', 'sale')
            ->whereBetween('created_at', [$weekStart, $now])
            ->sum('paid');

        $weeklyExpenses = (float) Expense::whereBetween('created_at', [$weekStart, $now])
            ->sum('amount');

        // ================= RATIOS =================
        $grossMargin = $billed > 0
            ? round((($billed - $expenses) / $billed) * 100, 2)
            : 0;

        $conversionRate = $leads > 0
            ? round(($converted / $leads) * 100, 2)
            : 0;

        // Burn rate mingguan real
        $weeksElapsed = max(1, ceil($monthStart->diffInDays($now) / 7));
        $burnRateWeekly = round($expenses / $weeksElapsed, 2);

        // Runway kas (berapa minggu bisa hidup dari uang masuk & DP)
        $cashRunwayWeeks = $burnRateWeekly > 0
            ? round(($revenue + $advances) / $burnRateWeekly, 1)
            : 0;

        // Funnel performance
        $leadToQuoteRate = $leads > 0
            ? round(($quotations / $leads) * 100, 2)
            : 0;

        $quoteToDealRate = $quotations > 0
            ? round(($deals / $quotations) * 100, 2)
            : 0;

        // Pipeline & DP health
        $avgDealSize = $deals > 0
            ? round($proformaValue / $deals, 2)
            : 0;

        $dpCoverage = $proformaValue > 0
            ? round(($proformaPaid / $proformaValue) * 100, 2)
            : 0;

        // Overdue invoices
        $overdueCount = $invoiceSales
            ->filter(fn ($i) =>
                $i->due_date &&
                Carbon::parse($i->due_date)->lt($now) &&
                $i->paid < $i->grand_total
            )
            ->count();

        // ================= RISK SCORE =================
        $riskScore = 0;
        if ($grossMargin < 20) $riskScore += 20;        // Margin rendah
        if ($conversionRate < 15) $riskScore += 20;    // Lead quality lemah
        if ($cashRunwayWeeks < 4) $riskScore += 30;    // Runway < 1 bulan
        if ($dpCoverage < 20) $riskScore += 20;        // Klien tidak commit bayar
        if ($overdueCount > 0) $riskScore += 10;       // Tagihan macet

        // ================= OUTPUT =================
        return [
            'period' => [
                'start' => $monthStart->toDateString(),
                'end' => $now->toDateString(),
                'week_start' => $weekStart->toDateString(),
            ],

            'finance' => [
                'revenue_received' => round($revenue, 2),
                'billed_total' => round($billed, 2),
                'expenses' => round($expenses, 2),
                'profit' => round($revenue - $expenses, 2),
                'gross_margin' => $grossMargin,
                'proforma_value' => round($proformaValue, 2),
                'dp_coverage_percent' => $dpCoverage,
                'burn_rate_weekly' => $burnRateWeekly,
                'cash_runway_weeks' => $cashRunwayWeeks,
                'overdue_invoices' => $overdueCount,
            ],

            'sales' => [
                'leads' => $leads,
                'converted' => $converted,
                'conversion_rate' => $conversionRate,
                'quotations' => $quotations,
                'deals' => $deals,
                'lead_to_quote_rate' => $leadToQuoteRate,
                'quote_to_deal_rate' => $quoteToDealRate,
                'avg_deal_size' => $avgDealSize,
            ],

            'risk_score' => min(100, $riskScore),
        ];
    }
}
