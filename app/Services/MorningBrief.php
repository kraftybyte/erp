<?php

namespace App\Services;

use App\Helpers\ClientLabel;
use App\Helpers\Money;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Ringkasan pagi untuk Director, Finance, dan super_admin: invoice yang lewat
 * jatuh tempo atau jatuh tempo minggu ini, kontrak yang segera berakhir, task
 * yang terlambat atau deadline-nya minggu ini, dan pengajuan yang menunggu.
 *
 * Invoice dihitung sama dengan /piutang di bot laporan keuangan (lihat
 * FinanceOverview::unpaidInvoices()).
 */
class MorningBrief
{
    public const ROLES = ['Director', 'Finance', 'super_admin'];

    public const CONTRACT_DAYS = 30;

    public const DUE_SOON_DAYS = 7;

    private const LIST_LIMIT = 5;

    /** Task yang tidak perlu diingatkan: selesai atau sengaja ditahan. */
    private const TASK_CLOSED = ['done', 'hold'];

    public function __construct(private FinanceOverview $finance) {}

    /**
     * @return Collection<int, User>
     */
    public function recipients(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $query) => $query->whereIn('name', self::ROLES))
            ->get();
    }

    /**
     * @return array{date: Carbon, overdue: Collection<int, Invoice>, due_soon: Collection<int, Invoice>, contracts: Collection<int, Contract>, late_tasks: Collection<int, Task>, week_tasks: Collection<int, Task>, pending: array{expense: Collection, advance: Collection}}
     */
    public function data(?Carbon $today = null): array
    {
        $today  = ($today ?? Carbon::today())->copy()->startOfDay();
        $unpaid = $this->finance->unpaidInvoices();
        $due    = fn (Invoice $invoice) => $invoice->due_date ? Carbon::parse($invoice->due_date)->startOfDay() : null;

        $tasks = Task::query()
            ->whereNotIn('status', self::TASK_CLOSED)
            ->whereNotNull('selesai_task')
            ->where('selesai_task', '<', $today->copy()->addDays(self::DUE_SOON_DAYS))
            ->with('users')
            ->orderBy('selesai_task')
            ->get();

        return [
            'date'      => $today,
            'overdue'   => $unpaid->filter(fn (Invoice $invoice) => $due($invoice)?->lt($today))->values(),
            'due_soon'  => $unpaid->filter(fn (Invoice $invoice) => $due($invoice)?->betweenIncluded($today, $today->copy()->addDays(self::DUE_SOON_DAYS - 1)))->values(),
            'contracts' => Contract::query()
                ->with('deal')
                // Model Contract tidak memakai SoftDeletes, tapi kolomnya ada dan sebagian terisi.
                ->whereNull('deleted_at')
                ->whereBetween('expiration_date', [$today->toDateString(), $today->copy()->addDays(self::CONTRACT_DAYS)->toDateString()])
                ->orderBy('expiration_date')
                ->get(),
            // Terlambat: yang paling baru lewat lebih dulu; task lama yang terbengkalai di bawah.
            'late_tasks' => $tasks->filter(fn (Task $task) => Carbon::parse($task->selesai_task)->lt($today))->sortByDesc('selesai_task')->values(),
            'week_tasks' => $tasks->reject(fn (Task $task) => Carbon::parse($task->selesai_task)->lt($today))->values(),
            'pending'    => $this->finance->pending(),
        ];
    }

    public function isEmpty(array $data): bool
    {
        return $data['overdue']->isEmpty() && $data['due_soon']->isEmpty() && $data['contracts']->isEmpty()
            && $data['late_tasks']->isEmpty() && $data['week_tasks']->isEmpty()
            && $data['pending']['expense']->isEmpty() && $data['pending']['advance']->isEmpty();
    }

    /**
     * Pesan Telegram (HTML).
     */
    public function telegram(array $data): string
    {
        /** @var Carbon $today */
        $today = $data['date'];
        $lines = ['☀️ <b>Ringkasan pagi · ' . $today->locale('id')->translatedFormat('l, j M Y') . '</b>'];

        if ($data['overdue']->isNotEmpty()) {
            $lines = [...$lines, '', '🔴 <b>Invoice lewat jatuh tempo</b> — ' . $this->invoiceTotal($data['overdue'])];

            foreach ($data['overdue']->sortBy('due_date')->take(self::LIST_LIMIT) as $invoice) {
                $days    = (int) Carbon::parse($invoice->due_date)->startOfDay()->diffInDays($today);
                $lines[] = '• ' . e(ClientLabel::invoice($invoice)) . ' — <b>' . Money::rupiah(FinanceOverview::invoiceUnpaid($invoice)) . "</b> · lewat {$days} hari"
                    . "\n   " . e((string) $invoice->uuid);
            }

            $lines = [...$lines, ...$this->more($data['overdue'], 'invoice', '/piutang di bot laporan keuangan')];
        }

        if ($data['due_soon']->isNotEmpty()) {
            $lines = [...$lines, '', '🟡 <b>Jatuh tempo ' . self::DUE_SOON_DAYS . ' hari ke depan</b> — ' . $this->invoiceTotal($data['due_soon'])];

            foreach ($data['due_soon']->take(self::LIST_LIMIT) as $invoice) {
                $lines[] = '• ' . e(ClientLabel::invoice($invoice)) . ' — <b>' . Money::rupiah(FinanceOverview::invoiceUnpaid($invoice)) . '</b> · '
                    . $this->day(Carbon::parse($invoice->due_date), $today);
            }

            $lines = [...$lines, ...$this->more($data['due_soon'], 'invoice')];
        }

        if ($data['contracts']->isNotEmpty()) {
            $lines = [...$lines, '', '📄 <b>Kontrak berakhir dalam ' . self::CONTRACT_DAYS . ' hari</b> — ' . $data['contracts']->count()];

            foreach ($data['contracts']->take(self::LIST_LIMIT) as $contract) {
                $lines[] = '• ' . e($this->contractClient($contract)) . ' — ' . $this->day(Carbon::parse($contract->expiration_date), $today)
                    . "\n   " . e((string) $contract->contract_number);
            }

            $lines = [...$lines, ...$this->more($data['contracts'], 'kontrak')];
        }

        if ($data['late_tasks']->isNotEmpty() || $data['week_tasks']->isNotEmpty()) {
            $counts = array_filter([
                $data['late_tasks']->isNotEmpty() ? $data['late_tasks']->count() . ' terlambat' : null,
                $data['week_tasks']->isNotEmpty() ? $data['week_tasks']->count() . ' deadline ' . self::DUE_SOON_DAYS . ' hari ke depan' : null,
            ]);
            $lines = [...$lines, '', '🗂 <b>Task</b> — ' . implode(' · ', $counts)];

            foreach ([...$data['late_tasks']->take(self::LIST_LIMIT), ...$data['week_tasks']->take(self::LIST_LIMIT)] as $task) {
                $people  = $task->users->map(fn (User $user) => trim("{$user->firstname} {$user->lastname}") ?: $user->username)->implode(', ');
                $lines[] = '• ' . e((string) $task->nama_task) . ' — ' . $this->day(Carbon::parse($task->selesai_task), $today)
                    . ($people !== '' ? ' · ' . e($people) : '');
            }
        }

        $pending = $data['pending']['expense']->concat($data['pending']['advance']);

        if ($pending->isNotEmpty()) {
            $approve = $pending->where('status', 'Pending');
            $pay     = $pending->where('status', 'Disetujui');

            $lines = [...$lines, '', '⏳ <b>Pengajuan menunggu</b> — ' . implode(' · ', array_filter([
                $approve->isNotEmpty() ? $approve->count() . ' perlu persetujuan (' . Money::rupiah($approve->sum('amount')) . ')' : null,
                $pay->isNotEmpty() ? $pay->count() . ' perlu dibayar (' . Money::rupiah($pay->sum('amount')) . ')' : null,
            ]))];
        }

        return implode("\n", $lines);
    }

    /**
     * Satu kalimat untuk lonceng notifikasi ERP.
     */
    public function summary(array $data): string
    {
        $pending = $data['pending']['expense']->count() + $data['pending']['advance']->count();

        return implode(' · ', array_filter([
            $data['overdue']->isNotEmpty() ? $data['overdue']->count() . ' invoice lewat jatuh tempo (' . $this->unpaid($data['overdue']) . ')' : null,
            $data['due_soon']->isNotEmpty() ? $data['due_soon']->count() . ' invoice jatuh tempo minggu ini' : null,
            $data['contracts']->isNotEmpty() ? $data['contracts']->count() . ' kontrak berakhir dalam ' . self::CONTRACT_DAYS . ' hari' : null,
            $data['late_tasks']->isNotEmpty() ? $data['late_tasks']->count() . ' task terlambat' : null,
            $data['week_tasks']->isNotEmpty() ? $data['week_tasks']->count() . ' task deadline minggu ini' : null,
            $pending > 0 ? "{$pending} pengajuan menunggu" : null,
        ])) . '.';
    }

    private function invoiceTotal(Collection $invoices): string
    {
        return $invoices->count() . ' invoice · <b>' . $this->unpaid($invoices) . '</b>';
    }

    private function unpaid(Collection $invoices): string
    {
        return Money::rupiah($invoices->sum(fn (Invoice $invoice) => FinanceOverview::invoiceUnpaid($invoice)));
    }

    /**
     * @return array<int, string>
     */
    private function more(Collection $rows, string $unit, ?string $where = null): array
    {
        $rest = $rows->count() - self::LIST_LIMIT;

        return $rest > 0 ? ["…dan {$rest} {$unit} lain" . ($where ? " ({$where})" : '') . '.'] : [];
    }

    /** "hari ini", "besok", "3 Nov (lewat 4 hari)". */
    private function day(Carbon $date, Carbon $today): string
    {
        $date = $date->copy()->startOfDay();
        $diff = (int) $today->diffInDays($date, false);

        return match (true) {
            $diff === 0 => 'hari ini',
            $diff === 1 => 'besok',
            $diff < 0   => $date->locale('id')->translatedFormat('j M') . ' (lewat ' . abs($diff) . ' hari)',
            default     => $date->locale('id')->translatedFormat('j M') . " ({$diff} hari lagi)",
        };
    }

    private function contractClient(Contract $contract): string
    {
        return $contract->client_name_full ?: ($contract->deal?->nama_perusahaan ?: 'Kontrak #' . $contract->id);
    }
}
