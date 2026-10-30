<?php

namespace App\Services;

use App\Helpers\ClientLabel;
use App\Models\Advance;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ringkasan advance, expense, dan invoice — sepanjang waktu, atau per periode
 * lewat forPeriod() (invoice menurut tanggal invoice, expense & advance menurut
 * tanggal pengajuan). Angkanya jumlah langsung dari tabel masing-masing.
 *
 * "Sudah dibayar/dicairkan" ditentukan dari status, bukan kolom `paid`: pada
 * data lama kolom itu bisa sudah terisi walau pengajuannya baru disetujui.
 * Pengajuan yang ditolak dihitung terpisah dan tidak masuk total mana pun.
 */
class FinanceOverview
{
    private const EXPENSE_PAID = ['Dibayarkan', 'Selesai'];

    private const ADVANCE_DISBURSED = [
        Advance::STATUS_DIBAYARKAN,
        Advance::STATUS_REALIZING,
        Advance::STATUS_REALIZED_USER,
        Advance::STATUS_READY_TO_CLOSE,
        Advance::STATUS_SELESAI,
    ];

    public const INVOICE_CANCELLED = ['cancelled', 'canceled', 'batal', 'ditolak'];

    private ?ReportPeriod $period = null;

    /**
     * Salinan yang hanya menghitung data pada periode itu.
     */
    public function forPeriod(ReportPeriod $period): static
    {
        $copy = clone $this;
        $copy->period = $period->isAll() ? null : $period;

        return $copy;
    }

    public function period(): ReportPeriod
    {
        return $this->period ?? ReportPeriod::all();
    }

    /**
     * @return array<string, array{count: int, amount: float}>
     */
    public function expenses(): array
    {
        return $this->buckets($this->inPeriod(Expense::query()), [
            'pending'  => ['Pending'],
            'approved' => ['Disetujui'],
            'paid'     => self::EXPENSE_PAID,
            'rejected' => ['Ditolak'],
        ], paidBucket: 'paid');
    }

    /**
     * @return array<string, mixed>
     */
    public function advances(): array
    {
        $buckets = $this->buckets($this->inPeriod(Advance::query()), [
            'pending'   => [Advance::STATUS_PENDING],
            'approved'  => [Advance::STATUS_DISETUJUI],
            'disbursed' => self::ADVANCE_DISBURSED,
            'rejected'  => [Advance::STATUS_DITOLAK],
        ], paidBucket: 'disbursed');

        $open = $this->openAdvances();

        $ids = $this->period ? $this->inPeriod(Advance::query())->pluck('id')->all() : null;

        return $buckets + [
            'realized'    => (float) $this->realizedPerAdvance()->when($ids !== null, fn ($rows) => $rows->only($ids))->sum(),
            'returned'    => (float) $this->returnedPerAdvance()->when($ids !== null, fn ($rows) => $rows->only($ids))->sum(),
            'outstanding' => ['count' => $open->where('outstanding', '>', 0)->count(), 'amount' => (float) $open->sum('outstanding')],
            'overrun'     => ['count' => $open->where('overrun', '>', 0)->count(), 'amount' => (float) $open->sum('overrun')],
        ];
    }

    /**
     * Advance yang sudah dicairkan tapi belum selesai, dengan sisa dana yang
     * belum dipertanggungjawabkan (dicairkan − direalisasi − dikembalikan).
     *
     * @return Collection<int, array{advance: Advance, disbursed: float, realized: float, returned: float, outstanding: float, overrun: float}>
     */
    public function openAdvances(): Collection
    {
        $realized = $this->realizedPerAdvance();
        $returned = $this->returnedPerAdvance();

        return $this->inPeriod(Advance::with('user', 'project.deal'))
            ->whereIn('status', array_diff(self::ADVANCE_DISBURSED, [Advance::STATUS_SELESAI]))
            ->get()
            ->map(function (Advance $advance) use ($realized, $returned) {
                $disbursed = $this->paidAmount($advance->paid, $advance->amount);
                $balance   = $disbursed - (float) ($realized[$advance->id] ?? 0) - (float) ($returned[$advance->id] ?? 0);

                return [
                    'advance'     => $advance,
                    'disbursed'   => $disbursed,
                    'realized'    => (float) ($realized[$advance->id] ?? 0),
                    'returned'    => (float) ($returned[$advance->id] ?? 0),
                    'outstanding' => max($balance, 0),
                    'overrun'     => max(-$balance, 0),
                ];
            })
            ->sortByDesc('outstanding')
            ->values();
    }

    /**
     * Invoice penjualan pada periode, langsung dari tabel invoice, terbaru dulu.
     * Proforma (belum menjadi tagihan) dan yang dibatalkan tidak ikut; draft ikut.
     *
     * @return Collection<int, Invoice>
     */
    public function invoiceRows(): Collection
    {
        return $this->inPeriod(Invoice::query(), 'date')
            ->where('type', 'sale')
            // Model Invoice tidak memakai SoftDeletes, tapi kolomnya ada.
            ->whereNull('deleted_at')
            ->where(fn (Builder $q) => $q->whereNull('status')->orWhereNotIn('status', self::INVOICE_CANCELLED))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Total invoice per jenis pembayaran (jumlah kolom Total / grand_total) dan
     * yang belum dibayarkan (Total − Paid pada invoice yang belum berstatus paid).
     *
     * @return array<string, array{count: int, amount: float}> dp, full, final, total, unpaid
     */
    public function invoiceTotals(): array
    {
        $rows = $this->invoiceRows();
        $sum  = fn (Collection $group) => ['count' => $group->count(), 'amount' => (float) $group->sum(fn (Invoice $i) => (float) $i->grand_total)];

        $unpaid = $rows->filter(fn (Invoice $i) => self::invoiceUnpaid($i) > 0);

        return [
            'dp'     => $sum($rows->filter(fn (Invoice $i) => self::invoiceKind($i) === 'dp')),
            'full'   => $sum($rows->filter(fn (Invoice $i) => self::invoiceKind($i) === 'full')),
            'final'  => $sum($rows->filter(fn (Invoice $i) => self::invoiceKind($i) === 'final')),
            'total'  => $sum($rows),
            'unpaid' => ['count' => $unpaid->count(), 'amount' => (float) $unpaid->sum(fn (Invoice $i) => self::invoiceUnpaid($i))],
        ];
    }

    /**
     * Invoice yang belum dibayarkan, jatuh tempo terlama dulu.
     *
     * @return Collection<int, Invoice>
     */
    public function unpaidInvoices(): Collection
    {
        return $this->invoiceRows()
            ->filter(fn (Invoice $i) => self::invoiceUnpaid($i) > 0)
            ->sortBy(fn (Invoice $i) => $i->due_date ? Carbon::parse($i->due_date)->timestamp : PHP_INT_MAX)
            ->values();
    }

    /**
     * dp, final (pelunasan), atau full (bayar penuh — juga jenis yang tidak dikenali).
     */
    public static function invoiceKind(Invoice $invoice): string
    {
        return match (true) {
            $invoice->dpPercentage() !== null          => 'dp',
            $invoice->type_payment === 'final_payment' => 'final',
            default                                    => 'full',
        };
    }

    /**
     * Total − Paid, atau 0 bila invoice sudah berstatus paid.
     */
    public static function invoiceUnpaid(Invoice $invoice): float
    {
        return $invoice->status === 'paid' ? 0.0 : max(round((float) $invoice->grand_total - (float) $invoice->paid, 2), 0);
    }

    /**
     * Pengajuan yang menunggu persetujuan atau pembayaran, terlama dulu.
     *
     * @return array{expense: Collection<int, Expense>, advance: Collection<int, Advance>}
     */
    public function pending(): array
    {
        return [
            'expense' => Expense::with('user')->whereIn('status', ['Pending', 'Disetujui'])->oldest()->get(),
            'advance' => Advance::with('user')->whereIn('status', [Advance::STATUS_PENDING, Advance::STATUS_DISETUJUI])->oldest()->get(),
        ];
    }

    /**
     * Kelompokkan jumlah & nominal per status. Bucket "sudah dibayar" memakai
     * nominal yang benar-benar dibayarkan bila ada, selain itu nominal pengajuan.
     *
     * @param  array<string, array<int, string>>  $groups
     * @return array<string, array{count: int, amount: float}>
     */
    private function buckets(Builder $query, array $groups, string $paidBucket): array
    {
        // toBase(): only()/except() pada Eloquent Collection menyaring menurut
        // primary key model, bukan key koleksi, sehingga status tidak pernah cocok.
        $rows = $query
            ->selectRaw('status, count(*) as n, sum(amount) as amount, sum(case when paid > 0 then paid else amount end) as paid_amount')
            ->groupBy('status')
            ->get()
            ->toBase()
            ->keyBy('status');

        $result = [];
        $known  = [];

        foreach ($groups as $bucket => $statuses) {
            $selected = $rows->only($statuses);
            $column   = $bucket === $paidBucket ? 'paid_amount' : 'amount';

            $result[$bucket] = ['count' => (int) $selected->sum('n'), 'amount' => (float) $selected->sum($column)];
            $known = [...$known, ...$statuses];
        }

        // Status di luar alur yang dikenal tetap terhitung, supaya total cocok.
        $other = $rows->except($known);
        $result['other'] = ['count' => (int) $other->sum('n'), 'amount' => (float) $other->sum('amount')];

        $result['total'] = [
            'count'  => array_sum(array_column(array_diff_key($result, ['rejected' => true]), 'count')),
            'amount' => array_sum(array_column(array_diff_key($result, ['rejected' => true]), 'amount')),
        ];

        return $result;
    }

    private function paidAmount(mixed $paid, mixed $amount): float
    {
        return (float) $paid > 0 ? (float) $paid : (float) $amount;
    }

    /**
     * @return Collection<int, float> advance_id => total realisasi
     */
    private function realizedPerAdvance(): Collection
    {
        return DB::table('realisasi_items as i')
            ->join('realisasi_batches as b', 'b.id', '=', 'i.realisasi_id')
            ->join('advances as a', 'a.id', '=', 'b.advance_id')
            ->whereNull('i.deleted_at')
            ->whereNull('b.deleted_at')
            ->whereNull('a.deleted_at')
            ->groupBy('b.advance_id')
            ->selectRaw('b.advance_id, sum(i.subtotal) as total')
            ->pluck('total', 'advance_id')
            ->map(fn ($total) => (float) $total);
    }

    /**
     * @return Collection<int, float> advance_id => sisa dana yang sudah dikembalikan
     */
    private function returnedPerAdvance(): Collection
    {
        return Transaction::where('reference_type', 'advance_return')
            ->groupBy('reference_id')
            ->selectRaw('reference_id, sum(amount) as total')
            ->pluck('total', 'reference_id')
            ->map(fn ($total) => (float) $total);
    }

    /**
     * Expense per kategori (tanpa yang ditolak), terbesar dulu.
     *
     * @return Collection<int, array{label: string, count: int, amount: float, paid: float}>
     */
    public function expenseByCategory(): Collection
    {
        return $this->notRejected($this->inPeriod(Expense::query()), 'Ditolak')
            ->selectRaw("coalesce(nullif(category, ''), 'Tanpa kategori') as label, count(*) as n, sum(amount) as amount, "
                . $this->paidSum(self::EXPENSE_PAID) . ' as paid_amount')
            ->groupBy('label')
            ->orderByDesc('amount')
            ->get()
            ->toBase()
            ->map(fn ($row) => ['label' => $row->label, 'count' => (int) $row->n, 'amount' => (float) $row->amount, 'paid' => (float) $row->paid_amount]);
    }

    /**
     * Expense per project (tanpa yang ditolak), terbesar dulu.
     *
     * @return Collection<int, array{label: string, count: int, amount: float, paid: float}>
     */
    public function expenseByProject(): Collection
    {
        $rows = $this->notRejected($this->inPeriod(Expense::query()), 'Ditolak')
            ->selectRaw('project_id, count(*) as n, sum(amount) as amount, ' . $this->paidSum(self::EXPENSE_PAID) . ' as paid_amount')
            ->groupBy('project_id')
            ->orderByDesc('amount')
            ->get()
            ->toBase();

        $projects = Project::with('deal')->whereIn('id', $rows->pluck('project_id')->filter())->get()->keyBy('id');

        return $rows->map(fn ($row) => [
            'label'  => $this->projectLabel($projects[$row->project_id] ?? null),
            'count'  => (int) $row->n,
            'amount' => (float) $row->amount,
            'paid'   => (float) $row->paid_amount,
        ]);
    }

    /**
     * Advance per project (tanpa yang ditolak): diajukan, dicairkan,
     * direalisasi, dan sisa yang belum dipertanggungjawabkan.
     *
     * @return Collection<int, array>
     */
    public function advanceByProject(): Collection
    {
        return $this->advanceRows()
            ->reject(fn (array $row) => $row['advance']->status === Advance::STATUS_DITOLAK)
            ->groupBy(fn (array $row) => (int) $row['advance']->project_id)
            ->map(fn (Collection $rows) => [
                'label'       => $this->projectLabel($rows->first()['advance']->project),
                'count'       => $rows->count(),
                'amount'      => (float) $rows->sum(fn ($row) => $row['advance']->amount),
                'disbursed'   => (float) $rows->sum('disbursed'),
                'realized'    => (float) $rows->sum('realized'),
                'returned'    => (float) $rows->sum('returned'),
                'outstanding' => (float) $rows->sum('outstanding'),
            ])
            ->sortByDesc('amount')
            ->values();
    }

    /**
     * Setiap advance (termasuk yang ditolak, untuk ekspor) dengan angka
     * pencairan dan pertanggungjawabannya. $query membatasi advance-nya,
     * misalnya isi tabel ERP yang sedang difilter.
     *
     * @return Collection<int, array{advance: Advance, disbursed: float, realized: float, returned: float, outstanding: float}>
     */
    public function advanceRows(?Builder $query = null): Collection
    {
        $realized = $this->realizedPerAdvance();
        $returned = $this->returnedPerAdvance();

        $query ??= $this->inPeriod(Advance::query())->orderBy('submission_date')->orderBy('id');

        return $query->with('user', 'project.deal')
            ->get()
            ->map(function (Advance $advance) use ($realized, $returned) {
                $disbursed = in_array($advance->status, self::ADVANCE_DISBURSED, true)
                    ? $this->paidAmount($advance->paid, $advance->amount)
                    : 0.0;
                $real = (float) ($realized[$advance->id] ?? 0);
                $back = (float) ($returned[$advance->id] ?? 0);

                return [
                    'advance'     => $advance,
                    'disbursed'   => $disbursed,
                    'realized'    => $real,
                    'returned'    => $back,
                    // Advance yang sudah selesai tidak lagi punya sisa terbuka.
                    'outstanding' => $advance->status === Advance::STATUS_SELESAI ? 0.0 : max($disbursed - $real - $back, 0),
                ];
            });
    }

    /**
     * @return Collection<int, Expense>
     */
    public function expenseRows(): Collection
    {
        return $this->inPeriod(Expense::with('user', 'project.deal'))->orderBy('submission_date')->orderBy('id')->get();
    }

    public function isExpensePaid(Expense $expense): bool
    {
        return in_array($expense->status, self::EXPENSE_PAID, true);
    }

    /**
     * Nominal expense yang sudah dibayarkan; 0 bila belum.
     */
    public function expensePaidAmount(Expense $expense): float
    {
        return $this->isExpensePaid($expense) ? $this->paidAmount($expense->paid, $expense->amount) : 0.0;
    }

    public function projectLabel(?Project $project): string
    {
        return ClientLabel::project($project);
    }

    private function inPeriod(Builder $query, string $column = 'submission_date'): Builder
    {
        return $this->period ? $this->period->apply($query, $column) : $query;
    }

    private function notRejected(Builder $query, string $rejected): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', $rejected));
    }

    /**
     * SQL: jumlah nominal yang sudah dibayar untuk baris berstatus $statuses.
     */
    private function paidSum(array $statuses): string
    {
        $list = implode(', ', array_map(fn ($status) => DB::getPdo()->quote($status), $statuses));

        return "sum(case when status in ({$list}) then (case when paid > 0 then paid else amount end) else 0 end)";
    }
}
