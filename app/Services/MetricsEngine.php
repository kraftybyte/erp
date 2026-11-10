<?php

namespace App\Services;

use App\Helpers\ClientLabel;
use App\Models\Invoice;
use App\Models\PaymentAccount;
use App\Models\Quotation;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fakta keuangan untuk asisten keuangan (FinanceAdvisor). Semua angka dihitung
 * di sini dengan definisi yang sama dengan bot laporan keuangan; AI hanya
 * membaca fakta ini dan menyusun prioritas, tidak menghitung sendiri.
 *
 * - Bulan berjalan dibandingkan dengan tanggal yang sama bulan lalu (1–10 Nov
 *   lawan 1–10 Okt), bukan dengan sebulan penuh.
 * - Ditagih = yang ditagih invoice (Invoice::amountDue()): DP sebesar DP-nya,
 *   pelunasan sebesar sisa setelah DP, full sebesar Total.
 * - Piutang = /piutang di bot: semua invoice penjualan yang belum lunas, tanpa
 *   batas periode, umurnya dihitung dari jatuh tempo.
 * - Kas = transaksi yang dicatat di ERP (bayar invoice, expense, advance, mutasi
 *   rekening, setoran/penarikan manual). Biaya yang tidak dicatat di ERP tidak ikut.
 */
class MetricsEngine
{
    /**
     * Kunci yang nilainya Rupiah. FinanceAdvisor memformatnya "Rp 1.250.000"
     * untuk AI dan hanya menerima nominal di jawaban AI yang ada di sini.
     */
    public const MONEY_KEYS = [
        'in', 'out', 'net', 'in_invoice', 'prev_in', 'prev_out', 'balance', 'avg_out',
        'billed', 'prev_billed', 'last_month_billed', 'avg_billed',
        'amount', 'remaining', 'unbilled', 'value', 'done_amount',
        'total', 'prev_total', 'avg_total', 'outstanding',
        'expenses', 'cash_in', 'cash_out',
    ];

    /** Advance yang sisa dananya belum dipertanggungjawabkan lebih lama dari ini dianggap macet. */
    public const STALE_ADVANCE_DAYS = 14;

    /** Quotation pending yang lebih tua dari ini tidak lagi dihitung sebagai pipeline. */
    public const PIPELINE_DAYS = 90;

    private const TOP = 5;

    private const PROJECT_DONE = ['completed', 'selesai', 'done'];

    public function __construct(private FinanceOverview $finance, private TrendEngine $trend) {}

    public function generateSnapshot(?CarbonInterface $today = null): array
    {
        $today     = CarbonImmutable::parse($today ?? now())->startOfDay();
        $start     = $today->startOfMonth();
        $prevStart = $start->subMonthNoOverflow();
        $prevEnd   = $prevStart->addDays(min($today->day, $prevStart->daysInMonth) - 1);
        $mtd       = ReportPeriod::between($start, $today);
        $prev      = ReportPeriod::between($prevStart, $prevEnd);
        $history   = $this->trend->getMonthlyHistory(6, $today);
        $sales     = $this->saleInvoices();
        $dp        = $this->dpUnbilled($sales, $today);

        return [
            'period' => [
                'today'         => $today->toDateString(),
                'label'         => self::range($start, $today),
                'compare_label' => self::range($prevStart, $prevEnd),
                'day_of_month'  => $today->day,
                'days_in_month' => $today->daysInMonth,
            ],
            'cash'         => $this->cash($mtd, $prev, $history),
            'billing'      => $this->billing($mtd, $prev, $history),
            'receivables'  => $this->receivables($today),
            'dp_unbilled'  => $dp['open'],
            'pipeline'     => $this->pipeline($today),
            'expenses'     => $this->expenses($mtd, $prev, $history),
            'advances'     => $this->advances($today),
            'pending'      => $this->pending($today),
            'history'      => array_map(fn (array $month) => array_diff_key($month, ['month' => true]), $history),
            'data_quality' => $this->dataQuality($sales, $dp['unlinked']),
        ];
    }

    /** "1–10 Nov 2026", atau "28 Okt – 3 Nov 2026" bila beda bulan. */
    public static function range(CarbonInterface $from, CarbonInterface $to): string
    {
        $to = CarbonImmutable::parse($to)->locale('id');

        return ($from->month === $to->month ? $from->day : CarbonImmutable::parse($from)->locale('id')->translatedFormat('j M') . ' ')
            . '–' . $to->translatedFormat('j M Y');
    }

    /** Perubahan dalam persen, dibulatkan; null bila pembandingnya nol. */
    public static function change(float $now, float $before): ?int
    {
        return $before > 0 ? (int) round(($now - $before) / $before * 100) : null;
    }

    // =================================================================
    // Bagian
    // =================================================================

    private function cash(ReportPeriod $mtd, ReportPeriod $prev, array $history): array
    {
        // Hanya nama rekening: nomor rekening tidak perlu ikut dikirim ke AI.
        $accounts = PaymentAccount::active()->orderBy('name')->get()
            ->map(fn (PaymentAccount $account) => ['name' => (string) $account->name, 'balance' => round($account->getAvailableBalance(), 2)]);

        $balance = round((float) $accounts->sum('balance'), 2);
        $avgOut  = TrendEngine::runRate($history, 'cash_out');
        $in      = TrendEngine::cash($mtd, Transaction::TYPE_IN);
        $out     = TrendEngine::cash($mtd, Transaction::TYPE_OUT);

        return [
            'in'            => $in,
            'out'           => $out,
            'net'           => round($in - $out, 2),
            'in_invoice'    => TrendEngine::cash($mtd, Transaction::TYPE_IN, Transaction::REFERENCE_INVOICE),
            'prev_in'       => TrendEngine::cash($prev, Transaction::TYPE_IN),
            'prev_out'      => TrendEngine::cash($prev, Transaction::TYPE_OUT),
            'balance'       => $balance,
            'accounts'      => $accounts->values()->all(),
            'avg_out'       => $avgOut,
            'runway_months' => $balance > 0 && $avgOut > 0 ? round($balance / $avgOut, 1) : null,
        ];
    }

    private function billing(ReportPeriod $mtd, ReportPeriod $prev, array $history): array
    {
        $now    = $this->finance->forPeriod($mtd)->invoiceTotals()['total'];
        $before = $this->finance->forPeriod($prev)->invoiceTotals()['total'];
        $full   = array_values(array_filter($history, fn (array $month) => ! $month['partial']));

        return [
            'billed'            => round($now['amount'], 2),
            'count'             => $now['count'],
            'prev_billed'       => round($before['amount'], 2),
            'change_pct'        => self::change($now['amount'], $before['amount']),
            'last_month_billed' => $full ? end($full)['billed'] : 0.0,
            'avg_billed'        => TrendEngine::runRate($history, 'billed'),
        ];
    }

    private function receivables(CarbonImmutable $today): array
    {
        $rows = $this->finance->unpaidInvoices()->map(function (Invoice $invoice) use ($today) {
            $due = $invoice->due_date ? CarbonImmutable::parse($invoice->due_date)->startOfDay() : null;

            return [
                'invoice'      => (string) $invoice->uuid,
                'client'       => ClientLabel::invoice($invoice),
                'kind'         => FinanceOverview::invoiceKind($invoice),
                'status'       => (string) $invoice->status,
                'remaining'    => FinanceOverview::invoiceUnpaid($invoice),
                'due'          => $due?->toDateString(),
                'days_overdue' => $due && $due->lt($today) ? (int) $due->diffInDays($today) : 0,
                'days_to_due'  => $due && $due->gte($today) ? (int) $today->diffInDays($due) : null,
            ];
        });

        $overdue = $rows->where('days_overdue', '>', 0);

        return [
            'total'   => self::bucket($rows, 'remaining'),
            'overdue' => self::bucket($overdue, 'remaining'),
            'aging'   => [
                'not_due'  => self::bucket($rows->where('days_overdue', 0), 'remaining'),
                'd1_30'    => self::bucket($overdue->whereBetween('days_overdue', [1, 30]), 'remaining'),
                'd31_60'   => self::bucket($overdue->whereBetween('days_overdue', [31, 60]), 'remaining'),
                'd61_90'   => self::bucket($overdue->whereBetween('days_overdue', [61, 90]), 'remaining'),
                'd90_plus' => self::bucket($overdue->where('days_overdue', '>', 90), 'remaining'),
            ],
            'top_overdue' => $overdue->sortByDesc('remaining')->take(self::TOP)
                ->map(fn (array $row) => array_diff_key($row, ['days_to_due' => true]))->values()->all(),
            'due_soon' => $rows->filter(fn (array $row) => $row['days_to_due'] !== null && $row['days_to_due'] <= 7)
                ->sortBy('days_to_due')->take(3)
                ->map(fn (array $row) => array_intersect_key($row, array_flip(['invoice', 'client', 'remaining', 'due', 'days_to_due'])))
                ->values()->all(),
        ];
    }

    /**
     * Invoice DP yang sudah lunas tapi pelunasannya belum dibuat: sisa nilai
     * pekerjaan (harga penuh − DP) yang belum ditagih. Pelunasan lama yang belum
     * ditautkan ke DP-nya (klien dan harga penuh sama) tidak dihitung sebagai
     * belum ditagih, tapi dilaporkan sebagai catatan data.
     *
     * @return array{open: array, unlinked: list<array{dp: string, final: string}>}
     */
    private function dpUnbilled(Collection $sales, CarbonImmutable $today): array
    {
        $finals = $sales->filter(fn (Invoice $invoice) => in_array($invoice->type_payment, ['final_payment', 'pelunasan'], true));
        $linked = $finals->whereNotNull('parent_invoice_id')->pluck('parent_invoice_id')->flip();
        $legacy = $finals->whereNull('parent_invoice_id');

        $open     = collect();
        $unlinked = [];

        foreach ($sales as $dp) {
            if ($dp->dpPercentage() === null || ! self::settled($dp) || isset($linked[$dp->id])) {
                continue;
            }

            // Klien kadang langsung membayar harga penuh lewat invoice DP: tidak ada sisa yang perlu ditagih.
            $unbilled = round((float) $dp->grand_total - max($dp->amountDue(), (float) $dp->paid), 2);
            if ($unbilled <= 1) {
                continue;
            }

            $final = $legacy->first(fn (Invoice $final) => self::sameClient($final, $dp)
                && abs((float) $final->grand_total - (float) $dp->grand_total) < 1
                && (! $final->date || ! $dp->date || $final->date >= $dp->date));

            if ($final) {
                $unlinked[] = ['dp' => (string) $dp->uuid, 'final' => (string) $final->uuid];

                continue;
            }

            $open->push([
                'invoice'       => (string) $dp->uuid,
                'client'        => ClientLabel::invoice($dp),
                'unbilled'      => $unbilled,
                'days_since_dp' => $dp->date ? (int) CarbonImmutable::parse($dp->date)->startOfDay()->diffInDays($today) : null,
                'project_done'  => $dp->projects->contains(fn ($project) => in_array(Str::lower((string) $project->status), self::PROJECT_DONE, true)),
            ]);
        }

        $done = $open->where('project_done', true);

        return [
            'open' => [
                'count'       => $open->count(),
                'amount'      => round((float) $open->sum('unbilled'), 2),
                'done_count'  => $done->count(),
                'done_amount' => round((float) $done->sum('unbilled'), 2),
                'top'         => $open->sortBy([['project_done', 'desc'], ['unbilled', 'desc']])->take(self::TOP)->values()->all(),
            ],
            'unlinked' => $unlinked,
        ];
    }

    private function pipeline(CarbonImmutable $today): array
    {
        $age = fn ($date) => $date ? (int) CarbonImmutable::parse($date)->startOfDay()->diffInDays($today) : null;

        $proformas = Invoice::with('parentInvoice')
            ->where('type', 'estimate')
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query->whereNull('status')->orWhereNotIn('status', FinanceOverview::INVOICE_CANCELLED))
            ->get()
            ->map(fn (Invoice $invoice) => [
                'invoice'   => (string) $invoice->uuid,
                'client'    => ClientLabel::invoice($invoice),
                'remaining' => FinanceOverview::invoiceUnpaid($invoice),
                'days'      => $age($invoice->date),
            ])
            ->where('remaining', '>', 0);

        // Quotation dihapus permanen (tanpa SoftDeletes); kolom deleted_at tidak ada di produksi.
        $quotes = Quotation::query()
            ->whereIn('status', ['Approved', 'Pending'])
            ->get()
            ->map(fn (Quotation $quote) => [
                'number' => (string) $quote->uuid,
                'client' => ClientLabel::of($quote->nama_pic, $quote->nama_perusahaan),
                'value'  => round((float) $quote->grand_total, 2),
                'days'   => $age($quote->date),
                'status' => $quote->status,
            ]);

        $approved = $quotes->where('status', 'Approved');
        $pending  = $quotes->where('status', 'Pending')->filter(fn (array $quote) => ($quote['days'] ?? 0) <= self::PIPELINE_DAYS);
        $strip    = fn (array $quote) => array_diff_key($quote, ['status' => true]);

        return [
            'proforma' => self::bucket($proformas, 'remaining') + [
                'top' => $proformas->sortByDesc('remaining')->take(3)->values()->all(),
            ],
            'quotes_approved' => self::bucket($approved, 'value') + [
                'top' => $approved->sortByDesc('value')->take(3)->map($strip)->values()->all(),
            ],
            'quotes_pending' => self::bucket($pending, 'value'),
        ];
    }

    private function expenses(ReportPeriod $mtd, ReportPeriod $prev, array $history): array
    {
        $now    = $this->finance->forPeriod($mtd);
        $totals = $now->expenses();
        $total  = $totals['total']['amount'];
        $before = $this->finance->forPeriod($prev)->expenses()['total']['amount'];

        return [
            'total'          => round($total, 2),
            'prev_total'     => round($before, 2),
            'change_pct'     => self::change($total, $before),
            'avg_total'      => TrendEngine::runRate($history, 'expenses'),
            'pending'        => ['count' => $totals['pending']['count'], 'amount' => round($totals['pending']['amount'], 2)],
            'top_categories' => $now->expenseByCategory()->take(3)
                ->map(fn (array $row) => ['label' => $row['label'], 'amount' => round($row['amount'], 2)])->values()->all(),
        ];
    }

    private function advances(CarbonImmutable $today): array
    {
        $open = $this->finance->openAdvances();

        $rows = $open->where('outstanding', '>', 0)->map(fn (array $row) => [
            'user'        => self::userName($row['advance']->user),
            'project'     => $this->finance->projectLabel($row['advance']->project),
            'outstanding' => round($row['outstanding'], 2),
            'days'        => $row['advance']->submission_date
                ? (int) CarbonImmutable::parse($row['advance']->submission_date)->startOfDay()->diffInDays($today)
                : null,
        ]);

        return [
            'outstanding' => self::bucket($rows, 'outstanding'),
            'stale'       => self::bucket($rows->filter(fn (array $row) => ($row['days'] ?? 0) > self::STALE_ADVANCE_DAYS), 'outstanding'),
            'overrun'     => self::bucket($open->where('overrun', '>', 0), 'overrun'),
            // Bayar paralel: realisasi yang belum dibayar ke karyawan.
            'owed'        => self::bucket($open->where('owed', '>', 0), 'owed'),
            'top'         => $rows->sortByDesc('outstanding')->take(3)->values()->all(),
        ];
    }

    private function pending(CarbonImmutable $today): array
    {
        $summary = fn (Collection $rows) => self::bucket($rows, 'amount') + [
            'oldest_days' => ($first = $rows->first()) ? (int) CarbonImmutable::parse($first->created_at)->startOfDay()->diffInDays($today) : null,
        ];

        $pending = $this->finance->pending();

        return ['expense' => $summary($pending['expense']), 'advance' => $summary($pending['advance'])];
    }

    /**
     * Catatan yang membuat angka di atas kurang tepat, supaya AI tidak
     * menyimpulkan lebih dari datanya.
     */
    private function dataQuality(Collection $sales, array $unlinked): array
    {
        $received = Transaction::where('reference_type', Transaction::REFERENCE_INVOICE)->where('type', Transaction::TYPE_IN)
            ->selectRaw('reference_id, SUM(amount) AS total')->groupBy('reference_id')->pluck('total', 'reference_id');

        try {
            $withheld = DB::table((new \TomatoPHP\FilamentInvoices\Models\InvoiceMeta)->getTable())->where('key', 'withholding')
                ->selectRaw('invoice_id, SUM(value) AS total')->groupBy('invoice_id')->pluck('total', 'invoice_id');
        } catch (\Throwable) {
            $withheld = collect();
        }

        // Kolom paid lebih besar dari uang masuk yang tercatat + potongan pajak.
        $gaps = $sales->map(fn (Invoice $invoice) => round((float) $invoice->paid - (float) ($received[$invoice->id] ?? 0) - (float) ($withheld[$invoice->id] ?? 0), 2))
            ->filter(fn (float $gap) => $gap > 1);

        $notMarked = $sales->filter(fn (Invoice $invoice) => $invoice->status !== 'paid' && (float) $invoice->paid > 0 && InvoicePayment::remaining($invoice) <= 0);
        $overpaid  = $sales->filter(fn (Invoice $invoice) => (float) $invoice->paid > $invoice->amountDue() + 1);

        return [
            'paid_without_cash' => ['count' => $gaps->count(), 'amount' => round((float) $gaps->sum(), 2)],
            'paid_not_marked'   => ['count' => $notMarked->count(), 'invoices' => $notMarked->pluck('uuid')->take(self::TOP)->values()->all()],
            'overpaid'          => [
                'count'    => $overpaid->count(),
                'amount'   => round((float) $overpaid->sum(fn (Invoice $invoice) => (float) $invoice->paid - $invoice->amountDue()), 2),
                'invoices' => $overpaid->pluck('uuid')->take(self::TOP)->values()->all(),
            ],
            'unlinked_finals' => ['count' => count($unlinked), 'pairs' => array_slice($unlinked, 0, self::TOP)],
        ];
    }

    // =================================================================
    // Bantu
    // =================================================================

    /**
     * Invoice penjualan sepanjang waktu, tanpa proforma dan yang dibatalkan.
     */
    private function saleInvoices(): Collection
    {
        return Invoice::with(['parentInvoice', 'projects'])
            ->where('type', 'sale')
            // Model Invoice tidak memakai SoftDeletes, tapi kolomnya ada.
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query->whereNull('status')->orWhereNotIn('status', FinanceOverview::INVOICE_CANCELLED))
            ->get();
    }

    private static function settled(Invoice $invoice): bool
    {
        return $invoice->status === 'paid' || ((float) $invoice->paid > 0 && InvoicePayment::remaining($invoice) <= 0);
    }

    private static function sameClient(Invoice $a, Invoice $b): bool
    {
        $key = fn (Invoice $invoice) => Str::lower(trim((string) ($invoice->nama_perusahaan ?: $invoice->nama_pic)));

        return $key($a) !== '' && $key($a) === $key($b);
    }

    /**
     * @return array{count: int, amount: float}
     */
    private static function bucket(Collection $rows, string $field): array
    {
        return ['count' => $rows->count(), 'amount' => round((float) $rows->sum($field), 2)];
    }

    private static function userName(?User $user): string
    {
        return $user ? (trim("{$user->firstname} {$user->lastname}") ?: (string) $user->username) : '-';
    }
}
