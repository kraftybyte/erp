<?php

namespace App\Services;

use App\Models\Advance;
use App\Models\Expense;
use App\Models\Invoice;
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
     * @return Collection<int, array{invoice: Invoice, state: string, due: float, paid: float, outstanding: float, overdue_days: int}>
     */
    private function saleInvoices(): Collection
    {
        $today = Carbon::today();

        return Invoice::query()
            ->where('type', 'sale')
            // Model Invoice tidak memakai SoftDeletes, tapi kolomnya ada.
            ->whereNull('deleted_at')
            ->get()
            ->map(function (Invoice $invoice) use ($today) {
                $status = strtolower(trim((string) $invoice->status));
                $state  = match (true) {
                    in_array($status, self::INVOICE_CANCELLED, true) => 'cancelled',
                    $status === 'draft'                              => 'draft',
                    default                                          => 'issued',
                };

                $due         = $invoice->amountDue();
                $paid        = (float) $invoice->paid;
                $outstanding = max(round($due - $paid, 2), 0);
                $overdue     = $outstanding > 0 && $invoice->due_date && Carbon::parse($invoice->due_date)->lt($today)
                    ? (int) Carbon::parse($invoice->due_date)->diffInDays($today)
                    : 0;

                return [
                    'invoice'      => $invoice,
                    'state'        => $state,
                    'due'          => $due,
                    'paid'         => $paid,
                    'outstanding'  => $outstanding,
                    'overdue_days' => $overdue,
                ];
            });
    }
}
