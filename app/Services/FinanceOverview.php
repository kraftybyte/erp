<?php

namespace App\Services;

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
 * Ringkasan advance, expense, dan invoice sepanjang waktu.
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

    private const INVOICE_CANCELLED = ['cancelled', 'canceled', 'batal', 'ditolak'];

    /**
     * @return array<string, array{count: int, amount: float}>
     */
    public function expenses(): array
    {
        return $this->buckets(Expense::query(), [
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
        $buckets = $this->buckets(Advance::query(), [
            'pending'   => [Advance::STATUS_PENDING],
            'approved'  => [Advance::STATUS_DISETUJUI],
            'disbursed' => self::ADVANCE_DISBURSED,
            'rejected'  => [Advance::STATUS_DITOLAK],
        ], paidBucket: 'disbursed');

        $open = $this->openAdvances();

        return $buckets + [
            'realized'    => (float) $this->realizedPerAdvance()->sum(),
            'returned'    => (float) $this->returnedPerAdvance()->sum(),
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

        return Advance::with('user', 'project.deal')
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
     * Hanya invoice penjualan (bukan proforma/estimate). Nilai yang ditagih
     * memakai Invoice::amountDue(): invoice DP menyimpan nilai penuh project
     * tapi hanya menagih persentase DP-nya, dan sisanya ditagih lewat invoice
     * pelunasan — menjumlahkan grand_total mentah akan menghitung dobel.
     *
     * @return array<string, array{count: int, amount: float}>
     */
    public function invoices(): array
    {
        $invoices = $this->saleInvoices();
        $issued   = $invoices->where('state', 'issued');
        $due      = $issued->where('outstanding', '>', 0);
        $overdue  = $due->where('overdue_days', '>', 0);

        return [
            'billed'      => ['count' => $issued->count(), 'amount' => (float) $issued->sum('due')],
            'paid'        => ['count' => $issued->where('outstanding', '<=', 0)->count(), 'amount' => (float) $issued->sum('paid')],
            'outstanding' => ['count' => $due->count(), 'amount' => (float) $due->sum('outstanding')],
            'overdue'     => ['count' => $overdue->count(), 'amount' => (float) $overdue->sum('outstanding')],
            'draft'       => ['count' => $invoices->where('state', 'draft')->count(), 'amount' => (float) $invoices->where('state', 'draft')->sum('due')],
            'cancelled'   => ['count' => $invoices->where('state', 'cancelled')->count(), 'amount' => 0.0],
        ];
    }

    /**
     * Invoice terbit yang belum lunas, yang paling lama lewat jatuh tempo dulu.
     *
     * @return Collection<int, array>
     */
    public function receivables(): Collection
    {
        return $this->saleInvoices()
            ->where('state', 'issued')
            ->where('outstanding', '>', 0)
            ->sortByDesc('overdue_days')
            ->values();
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
     * Semua invoice penjualan (bukan proforma) beserta perhitungannya.
     *
     * - kind: 'dp', 'final' (pelunasan), atau 'full' (bayar penuh)
     * - due: yang ditagih lewat invoice ini (DP = persentasenya)
     * - contract_value: nilai pekerjaan yang diwakili invoice ini. Invoice DP
     *   menyimpan nilai penuh; pelunasan yang sudah dipasangkan ke DP-nya
     *   bernilai 0 supaya pekerjaan yang sama tidak terhitung dua kali.
     * - unbilled: untuk invoice DP, sisa nilai yang belum dibuatkan invoice pelunasan
     *
     * @return Collection<int, array>
     */
    public function saleInvoices(): Collection
    {
        $today = Carbon::today();

        $rows = Invoice::query()
            ->where('type', 'sale')
            // Model Invoice tidak memakai SoftDeletes, tapi kolomnya ada.
            ->whereNull('deleted_at')
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(function (Invoice $invoice) use ($today) {
                $status = strtolower(trim((string) $invoice->status));
                $state  = match (true) {
                    in_array($status, self::INVOICE_CANCELLED, true) => 'cancelled',
                    $status === 'draft'                              => 'draft',
                    default                                          => 'issued',
                };

                $kind = match (true) {
                    $invoice->dpPercentage() !== null        => 'dp',
                    $invoice->type_payment === 'final_payment' => 'final',
                    default                                  => 'full',
                };

                $due         = $invoice->amountDue();
                $paid        = (float) $invoice->paid;
                $outstanding = max(round($due - $paid, 2), 0);
                $overdue     = $outstanding > 0 && $invoice->due_date && Carbon::parse($invoice->due_date)->lt($today)
                    ? (int) Carbon::parse($invoice->due_date)->diffInDays($today)
                    : 0;

                return [
                    'invoice'        => $invoice,
                    'state'          => $state,
                    'kind'           => $kind,
                    'due'            => $due,
                    'paid'           => $paid,
                    'outstanding'    => $outstanding,
                    'overdue_days'   => $overdue,
                    'contract_value' => (float) $invoice->grand_total,
                    'unbilled'       => 0.0,
                    'pair_id'        => null,
                ];
            })
            ->keyBy(fn (array $row) => $row['invoice']->id);

        return $this->pairFinalsWithDp($rows)->values();
    }

    /**
     * Pasangkan invoice pelunasan dengan invoice DP-nya. Lewat parent_invoice_id
     * bila ada; invoice lama (sebelum kolom itu ada) dipasangkan ke invoice DP
     * dengan deal atau klien yang sama — hanya bila kandidatnya tepat satu.
     * Pelunasan yang tidak bisa dipasangkan dibiarkan berdiri sendiri dan
     * dilaporkan, bukan ditebak.
     */
    private function pairFinalsWithDp(Collection $rows): Collection
    {
        $issuedDp = $rows->filter(fn ($row) => $row['state'] === 'issued' && $row['kind'] === 'dp');
        $paired   = [];

        foreach ($rows->filter(fn ($row) => $row['state'] === 'issued' && $row['kind'] === 'final') as $id => $final) {
            $invoice  = $final['invoice'];
            $parentId = $invoice->parent_invoice_id;

            if (! $parentId || ! $issuedDp->has($parentId)) {
                $candidates = $issuedDp
                    ->reject(fn ($dp, $dpId) => isset($paired[$dpId]))
                    ->filter(fn ($dp) => $this->sameClient($dp['invoice'], $invoice))
                    ->filter(fn ($dp) => ! $dp['invoice']->date || ! $invoice->date || $dp['invoice']->date <= $invoice->date);

                $parentId = $candidates->count() === 1 ? $candidates->keys()->first() : null;
            }

            if ($parentId) {
                $paired[$parentId] = $id;
                $rows[$id]         = ['pair_id' => $parentId, 'contract_value' => 0.0] + $rows[$id];
                $rows[$parentId]   = ['pair_id' => $id] + $rows[$parentId];
            }
        }

        // Sisa nilai DP yang belum dibuatkan invoice pelunasan.
        foreach ($issuedDp as $dpId => $dp) {
            if (! isset($paired[$dpId])) {
                $rows[$dpId] = ['unbilled' => max((float) $dp['invoice']->grand_total - $dp['due'], 0)] + $rows[$dpId];
            }
        }

        return $rows;
    }

    private function sameClient(Invoice $a, Invoice $b): bool
    {
        if ($a->deal_id && $b->deal_id) {
            return $a->deal_id === $b->deal_id;
        }

        $normalize = fn (?string $name) => preg_replace('/\s+/', ' ', strtolower(trim((string) $name)));

        return $normalize($a->nama_perusahaan) !== '' && $normalize($a->nama_perusahaan) === $normalize($b->nama_perusahaan);
    }

    /**
     * Invoice terbit: nilai pekerjaan, uang masuk, dan sisanya — yang sudah
     * ditagih tapi belum dibayar, dan yang belum ditagih sama sekali.
     */
    public function invoiceBreakdown(): array
    {
        $issued = $this->saleInvoices()->where('state', 'issued');

        $kinds = [];
        foreach (['dp', 'final', 'full'] as $kind) {
            $group = $issued->where('kind', $kind);

            $kinds[$kind] = [
                'count'  => $group->count(),
                'billed' => (float) $group->sum('due'),
                'paid'   => (float) $group->sum('paid'),
                'unpaid' => (float) $group->sum('outstanding'),
            ];
        }

        $unpaid   = $issued->where('outstanding', '>', 0);
        $overdue  = $unpaid->where('overdue_days', '>', 0);
        $unbilled = $issued->where('unbilled', '>', 0);

        return [
            'contract_value'  => (float) $issued->sum('contract_value'),
            'paid'            => (float) $issued->sum('paid'),
            'unpaid_billed'   => ['count' => $unpaid->count(), 'amount' => (float) $unpaid->sum('outstanding')],
            'overdue'         => ['count' => $overdue->count(), 'amount' => (float) $overdue->sum('outstanding')],
            'unbilled'        => ['count' => $unbilled->count(), 'amount' => (float) $unbilled->sum('unbilled')],
            'kinds'           => $kinds,
            'unlinked_finals' => $issued->where('kind', 'final')->whereNull('pair_id')->count(),
        ];
    }

    /**
     * Invoice DP yang sisa nilainya belum dibuatkan invoice pelunasan.
     */
    public function unbilled(): Collection
    {
        return $this->saleInvoices()->where('state', 'issued')->where('unbilled', '>', 0)->sortByDesc('unbilled')->values();
    }

    /**
     * Expense per kategori (tanpa yang ditolak), terbesar dulu.
     *
     * @return Collection<int, array{label: string, count: int, amount: float, paid: float}>
     */
    public function expenseByCategory(): Collection
    {
        return $this->notRejected(Expense::query(), 'Ditolak')
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
        $rows = $this->notRejected(Expense::query(), 'Ditolak')
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
     * pencairan dan pertanggungjawabannya.
     *
     * @return Collection<int, array{advance: Advance, disbursed: float, realized: float, returned: float, outstanding: float}>
     */
    public function advanceRows(): Collection
    {
        $realized = $this->realizedPerAdvance();
        $returned = $this->returnedPerAdvance();

        return Advance::with('user', 'project.deal')
            ->orderBy('submission_date')
            ->orderBy('id')
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
        return Expense::with('user', 'project.deal')->orderBy('submission_date')->orderBy('id')->get();
    }

    public function isExpensePaid(Expense $expense): bool
    {
        return in_array($expense->status, self::EXPENSE_PAID, true);
    }

    public function projectLabel(?Project $project): string
    {
        if (! $project) {
            return 'Tanpa project';
        }

        $company = $project->nama_perusahaan ?: $project->deal?->nama_perusahaan;

        return implode(' | ', array_filter([$company, $project->nama_project])) ?: "Project #{$project->id}";
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
