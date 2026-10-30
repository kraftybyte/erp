<?php

namespace App\Services;

use App\Models\Advance;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Laba per project dari uang yang benar-benar masuk dan keluar:
 *
 *   uang masuk = pembayaran yang diterima dari invoice penjualan project
 *   biaya      = expense yang sudah dibayarkan + advance yang sudah dicairkan
 *                (dikurangi pengembalian, atau sebesar realisasinya bila lebih besar)
 *   laba       = uang masuk − biaya
 *
 * Invoice project: invoice asalnya (tombol "Buat Project" di invoice) beserta
 * rangkaian DP → pelunasannya. Project tanpa invoice asal memakai invoice client
 * (deal) yang bukan milik project lain; bila client itu punya beberapa project
 * seperti itu, pendapatannya tidak bisa dibagi dan dibiarkan kosong.
 *
 * Proforma (belum menjadi tagihan) dan invoice yang dibatalkan tidak dihitung.
 * "Belum dibayar" pada rangkaian DP dihitung dari harga penuh di invoice DP,
 * karena invoice pelunasan bisa menyimpan total penuh juga.
 */
class ProjectProfit
{
    /** Yang boleh melihat angka keuangan project. */
    public const ROLES = ['Director', 'Finance', 'super_admin'];

    private const EXPENSE_PENDING = ['Pending', 'Disetujui'];

    public function __construct(private FinanceOverview $finance) {}

    public static function visibleTo(?User $user): bool
    {
        return (bool) $user?->hasRole(self::ROLES);
    }

    /**
     * @return array<int, array{received: ?float, outstanding: ?float, expense: float, expense_pending: float, advance: float, cost: float, profit: ?float, margin: ?float, shared: int}>  project_id => ringkasan
     */
    public function summaries(): array
    {
        return array_map(fn (array $row) => $row['summary'], $this->build());
    }

    /**
     * Ringkasan beserta invoice, expense, dan advance yang dihitung.
     *
     * @return array{summary: array, invoices: Collection<int, Invoice>, expenses: Collection<int, array{expense: Expense, counted: float}>, advances: Collection<int, array>}
     */
    public function details(Project $project): array
    {
        return $this->build()[$project->getKey()];
    }

    private function build(): array
    {
        $projects = Project::query()->get(['id', 'deal_id', 'invoice_id']);
        $chains   = $this->invoicesPerProject($projects);

        $expenses = Expense::query()
            ->whereNotNull('project_id')
            ->where(fn (Builder $q) => $q->whereNull('status')->orWhere('status', '!=', 'Ditolak'))
            ->orderBy('submission_date')
            ->orderBy('id')
            ->get()
            ->map(fn (Expense $expense) => ['expense' => $expense, 'counted' => $this->finance->expensePaidAmount($expense)])
            ->groupBy(fn (array $row) => $row['expense']->project_id);

        $advances = $this->finance
            ->advanceRows(Advance::query()
                ->whereNotNull('project_id')
                ->where(fn (Builder $q) => $q->whereNull('status')->orWhere('status', '!=', Advance::STATUS_DITOLAK))
                ->orderBy('submission_date')
                ->orderBy('id'))
            ->map(fn (array $row) => $row + [
                // Dana yang keluar dan tidak kembali; overrun yang disetujui ikut sebagai biaya.
                'counted' => max($row['realized'], $row['disbursed'] - $row['returned'], 0),
            ])
            ->groupBy(fn (array $row) => $row['advance']->project_id);

        $result = [];

        foreach ($projects as $project) {
            $chain       = $chains[$project->id];
            $expenseRows = $expenses[$project->id] ?? collect();
            $advanceRows = $advances[$project->id] ?? collect();

            $expense = round((float) $expenseRows->sum('counted'), 2);
            $advance = round((float) $advanceRows->sum('counted'), 2);
            $cost    = round($expense + $advance, 2);

            $received = $chain['shared'] ? null : $chain['received'];
            $profit   = $received === null ? null : round($received - $cost, 2);

            $result[$project->id] = [
                'summary' => [
                    'received'        => $received,
                    'outstanding'     => $chain['shared'] ? null : $chain['outstanding'],
                    'expense'         => $expense,
                    'expense_pending' => round((float) $expenseRows
                        ->filter(fn (array $row) => in_array($row['expense']->status, self::EXPENSE_PENDING, true))
                        ->sum(fn (array $row) => (float) $row['expense']->amount), 2),
                    'advance'         => $advance,
                    'cost'            => $cost,
                    'profit'          => $profit,
                    'margin'          => $received > 0 ? round($profit / $received * 100, 1) : null,
                    'shared'          => $chain['shared'],
                ],
                'invoices' => $chain['invoices'],
                'expenses' => $expenseRows->values(),
                'advances' => $advanceRows->values(),
            ];
        }

        return $result;
    }

    /**
     * Invoice setiap project, dikelompokkan per rangkaian DP → pelunasan (dikenali
     * dari invoice pertamanya).
     *
     * @return array<int, array{invoices: Collection<int, Invoice>, received: float, outstanding: float, shared: int}>
     */
    private function invoicesPerProject(Collection $projects): array
    {
        $all    = Invoice::query()->whereNull('deleted_at')->orderBy('date')->orderBy('id')->get()->keyBy('id');
        $rootOf = fn (Invoice $invoice) => $invoice->parent_invoice_id && $all->has($invoice->parent_invoice_id)
            ? (int) $invoice->parent_invoice_id
            : (int) $invoice->id;
        $chains = $all->groupBy($rootOf);

        // Rangkaian milik project yang dibuat dari salah satu invoicenya.
        $owners = $projects
            ->filter(fn (Project $project) => $project->invoice_id && $all->has($project->invoice_id))
            ->groupBy(fn (Project $project) => $rootOf($all[$project->invoice_id]));

        // Project tanpa invoice asal berbagi invoice client yang tersisa.
        $unlinked = $projects
            ->reject(fn (Project $project) => $project->invoice_id && $all->has($project->invoice_id))
            ->groupBy('deal_id');

        $result = [];

        foreach ($projects as $project) {
            if ($project->invoice_id && $all->has($project->invoice_id)) {
                $root   = $rootOf($all[$project->invoice_id]);
                $roots  = [$root];
                $shared = $owners[$root]->count();
            } else {
                $roots = $project->deal_id === null ? [] : $chains
                    ->filter(fn (Collection $chain, int $root) => ! $owners->has($root)
                        && (int) $chain->pluck('deal_id')->filter()->first() === (int) $project->deal_id)
                    ->keys()
                    ->all();
                $shared = $project->deal_id === null ? 1 : $unlinked[$project->deal_id]->count();
            }

            $money = collect($roots)->map(fn (int $root) => $this->chainMoney($chains[$root]));

            $result[$project->id] = [
                'invoices'    => $money->flatMap(fn (array $chain) => $chain['invoices'])->values(),
                'received'    => round((float) $money->sum('received'), 2),
                'outstanding' => round((float) $money->sum('outstanding'), 2),
                'shared'      => $shared > 1 ? $shared : 0,
            ];
        }

        return $result;
    }

    /**
     * @param  Collection<int, Invoice>  $chain  invoice pertama lebih dulu
     * @return array{invoices: Collection<int, Invoice>, received: float, outstanding: float}
     */
    private function chainMoney(Collection $chain): array
    {
        $root     = $chain->first();
        $counted  = $chain->filter(fn (Invoice $invoice) => $invoice->type === 'sale' && ! $this->cancelled($invoice))->values();
        $received = (float) $counted->sum(fn (Invoice $invoice) => (float) $invoice->paid);

        if ($root->dpPercentage() !== null && ! $this->cancelled($root) && $counted->isNotEmpty()) {
            $settled     = $counted->contains(fn (Invoice $invoice) => $invoice->type_payment === 'final_payment' && $invoice->status === 'paid');
            $outstanding = $settled ? 0.0 : max((float) $root->grand_total - $received, 0);
        } else {
            $outstanding = (float) $counted->sum(fn (Invoice $invoice) => FinanceOverview::invoiceUnpaid($invoice));
        }

        return ['invoices' => $counted, 'received' => $received, 'outstanding' => round($outstanding, 2)];
    }

    private function cancelled(Invoice $invoice): bool
    {
        return in_array(strtolower((string) $invoice->status), FinanceOverview::INVOICE_CANCELLED, true);
    }
}
