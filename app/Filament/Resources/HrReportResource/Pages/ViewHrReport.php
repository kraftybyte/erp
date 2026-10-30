<?php

namespace App\Filament\Resources\HrReportResource\Pages;

use App\Filament\Resources\HrReportResource;
use App\Helpers\DateHelper;
use App\Models\User;
use Carbon\Carbon;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\DB;

class ViewHrReport extends Page
{
    protected static string $resource = HrReportResource::class;
    protected static string $view     = 'filament.resources.hr-report.pages.view-hr-report';

    public $record;
    public array  $stats       = [];
    public array  $taskSummary = [];
    public array  $taskDetail  = [];
    public array  $doneNotes   = [];
    public array  $absenceStat = [];
    public array  $kpi         = [];
    public array  $chartData   = [];
    public string $period      = 'this_month';

    public function mount(int|string $record): void
    {
        $id = $record instanceof User ? $record->id : $record;
        $this->record = User::with(['roles'])->findOrFail($id);
        $this->loadData();
    }

    public function updatedPeriod(): void
    {
        $this->loadData();
    }

    private function dateRange(): array
    {
        $allTimeStart = Carbon::createFromDate(2000, 1, 1)->startOfDay();
        return match($this->period) {
            'this_month'   => [now()->copy()->startOfMonth(), now()->copy()->endOfMonth()],
            'last_month'   => [now()->copy()->subMonth()->startOfMonth(), now()->copy()->subMonth()->endOfMonth()],
            'this_quarter' => [now()->copy()->startOfQuarter(), now()->copy()->endOfQuarter()],
            'this_year'    => [now()->copy()->startOfYear(), now()->copy()->endOfYear()],
            'last_year'    => [now()->copy()->subYear()->startOfYear(), now()->copy()->subYear()->endOfYear()],
            default        => [$allTimeStart, now()],
        };
    }

    private function loadData(): void
    {
        [$from, $to] = $this->dateRange();
        $userId = $this->record->id;

        $tasks = DB::table('task_user')
            ->join('tasks', 'tasks.id', '=', 'task_user.task_id')
            ->leftJoin('projects', 'projects.id', '=', 'tasks.project_id')
            ->where('task_user.user_id', $userId)
            ->whereBetween('tasks.mulai_task', [$from, $to])
            ->select('tasks.id','tasks.nama_task','tasks.status','tasks.mulai_task',
                     'tasks.selesai_task','tasks.done_note','tasks.updated_at',
                     'projects.nama_perusahaan as client')
            ->get();

        $total   = $tasks->count();
        $done    = $tasks->where('status', 'done')->count();
        $process = $tasks->where('status', 'process')->count();
        $review  = $tasks->where('status', 'review')->count();
        $hold    = $tasks->where('status', 'hold')->count();
        $late    = $tasks->filter(fn($t) =>
            $t->status !== 'done' && $t->selesai_task && Carbon::parse($t->selesai_task)->isPast()
        )->count();

        $doneTasks = $tasks->where('status', 'done')->filter(fn($t) => $t->mulai_task && $t->updated_at);
        $avgDays   = $doneTasks->count() > 0
            ? round($doneTasks->avg(fn($t) => DateHelper::businessDaysBetween($t->mulai_task, $t->updated_at)))
            : null;

        $doneRate    = $total > 0 ? round($done / $total * 100) : 0;
        $this->kpi   = $this->calcKpi($avgDays ?? 99, $doneRate, $late, $total);
        $this->stats = compact('total','done','process','review','hold','late','avgDays','doneRate');

        $this->taskSummary = [
            ['label'=>'Done',      'count'=>$done,    'color'=>'#07a0cf'],
            ['label'=>'Process',   'count'=>$process, 'color'=>'#ff5915'],
            ['label'=>'Review',    'count'=>$review,  'color'=>'#d97706'],
            ['label'=>'Hold',      'count'=>$hold,    'color'=>'#64748b'],
            ['label'=>'Terlambat', 'count'=>$late,    'color'=>'#ef4444'],
        ];

        // Detail per task dengan durasi masing-masing (business days)
        $this->taskDetail = $tasks->map(function($t) {
            $dur = null;
            $durLabel = '-';
            if ($t->mulai_task && $t->updated_at && $t->status === 'done') {
                $dur = DateHelper::businessDaysBetween($t->mulai_task, $t->updated_at);
                $durLabel = $dur . ' hari';
            } elseif ($t->mulai_task) {
                $dur = DateHelper::businessDaysBetween($t->mulai_task, now());
                $durLabel = $dur . ' hari (jalan)';
            }

            $isLate = $t->status !== 'done' && $t->selesai_task && Carbon::parse($t->selesai_task)->isPast();

            return [
                'nama_task'  => $t->nama_task,
                'client'     => $t->client ?? '-',
                'status'     => $t->status,
                'mulai'      => $t->mulai_task ? Carbon::parse($t->mulai_task)->format('d M Y') : '-',
                'deadline'   => $t->selesai_task ? Carbon::parse($t->selesai_task)->format('d M Y') : '-',
                'durasi'     => $durLabel,
                'dur_num'    => $dur ?? 0,
                'done_note'  => $t->done_note ?? '',
                'is_late'    => $isLate,
            ];
        })->sortByDesc('dur_num')->values()->toArray();

        $this->doneNotes = $tasks
            ->where('status', 'done')->whereNotNull('done_note')
            ->filter(fn($t) => trim($t->done_note ?? '') !== '')
            ->map(fn($t) => [
                'nama_task' => $t->nama_task,
                'client'    => $t->client ?? '-',
                'done_note' => $t->done_note,
                'selesai'   => $t->updated_at ? Carbon::parse($t->updated_at)->format('d M Y') : '-',
                'duration'  => $t->mulai_task && $t->updated_at
                    ? DateHelper::businessDaysBetween($t->mulai_task, $t->updated_at).' hari' : '-',
            ])->values()->toArray();

        // Absence — kategori: izin, cuti, wfh, tukar_hari, wfa
        $absences = DB::table('absences')
            ->where('user_id', $userId)
            ->where('approval_status', 'Approved')
            ->whereBetween('start_time', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->get();

        $workDays   = max(1, $from->diffInWeekdays($to));
        $cuti       = $absences->whereIn('category', ['cuti','izin'])->count();
        $wfa        = $absences->where('category', 'wfa')->count();
        $wfh        = $absences->where('category', 'wfh')->count();
        $tukar      = $absences->where('category', 'tukar_hari')->count();
        $hadir      = max(0, $workDays - $cuti - $wfa - $wfh);
        $attendRate = round($hadir / $workDays * 100);

        $this->absenceStat = compact('workDays','hadir','wfa','wfh','cuti','tukar','attendRate');
        $this->chartData   = $this->loadChartData($userId);
    }

    private function loadChartData(int $userId): array
    {
        $labels = $taskDone = $taskTotal = $attendance = [];

        for ($i = 5; $i >= 1; $i--) {
            $month = now()->copy()->subMonths($i);
            $start = $month->copy()->startOfMonth();
            $end   = $month->copy()->endOfMonth();

            $labels[] = $month->locale('id')->isoFormat('MMM YY');

            $monthTasks = DB::table('task_user')
                ->join('tasks', 'tasks.id', '=', 'task_user.task_id')
                ->where('task_user.user_id', $userId)
                ->whereBetween('tasks.mulai_task', [$start, $end])
                ->select('tasks.status')->get();

            $taskTotal[] = $monthTasks->count();
            $taskDone[]  = $monthTasks->where('status', 'done')->count();

            $absent = DB::table('absences')
                ->where('user_id', $userId)
                ->where('approval_status', 'Approved')
                ->whereBetween('start_time', [$start->toDateTimeString(), $end->toDateTimeString()])
                ->whereIn('category', ['cuti','izin','wfa','wfh'])
                ->count();

            $wd           = max(1, $start->diffInWeekdays($end));
            $attendance[] = round(max(0, $wd - $absent) / $wd * 100);
        }

        return compact('labels','taskDone','taskTotal','attendance');
    }

    private function calcKpi(float $avg, float $rate, int $late, int $total): array
    {
        if ($total === 0) return ['label'=>'No Data','color'=>'#64748b','bg'=>'#f1f5f9','score'=>'-'];

        $taskScore  = min(100, $rate);
        $speedScore = match(true) { $avg<=3=>100, $avg<=7=>80, $avg<=14=>60, $avg<=30=>40, default=>20 };
        $lateScore  = max(0, 100 - ($late * 10));
        $score      = round(($taskScore * 0.5) + ($speedScore * 0.3) + ($lateScore * 0.2));

        return match(true) {
            $score >= 90 => ['label'=>'Outstanding',  'color'=>'#07a0cf','bg'=>'#e0f5fc','score'=>$score],
            $score >= 75 => ['label'=>'Good',          'color'=>'#0284c7','bg'=>'#eaf9ff','score'=>$score],
            $score >= 60 => ['label'=>'Average',       'color'=>'#d97706','bg'=>'#fff8e6','score'=>$score],
            $score >= 40 => ['label'=>'Needs Review',  'color'=>'#ff5915','bg'=>'#fff1eb','score'=>$score],
            default      => ['label'=>'Below Average', 'color'=>'#ef4444','bg'=>'#fef2f2','score'=>$score],
        };
    }

    public function getTitle(): string
    {
        return 'Detail Performa: '.$this->record->firstname.' '.$this->record->lastname;
    }
}
