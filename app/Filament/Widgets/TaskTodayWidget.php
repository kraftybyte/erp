<?php
namespace App\Filament\Widgets;
use App\Models\Task;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class TaskTodayWidget extends Widget
{
    protected static ?int $sort = 1;
    protected static string $view = 'filament.widgets.task-today-widget';
    protected int|string|array $columnSpan = [
        'default' => '1/2',
        'md'      => 1,
        'lg'      => 1,
        'xl'      => 1,
    ];

    public string $filter      = 'all';
    public bool   $showModal   = false;
    public ?int   $modalTaskId = null;
    public string $modalTaskName = '';
    public string $doneNote    = '';

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
    }

    /*
    |--------------------------------------------------------------------------
    | Modal
    |--------------------------------------------------------------------------
    */
    public function openDoneModal(int $taskId): void
    {
        $task = Task::find($taskId);
        if (!$task) return;

        if ($task->status === 'done') {
            $task->status = 'process';
            $task->save();
            return;
        }

        $this->modalTaskId   = $taskId;
        $this->modalTaskName = $task->nama_task;
        $this->doneNote      = '';
        $this->showModal     = true;
    }

    public function closeModal(): void
    {
        $this->showModal   = false;
        $this->modalTaskId = null;
        $this->doneNote    = '';
    }

    public function confirmDone(): void
    {
        if (!$this->modalTaskId) return;

        $task = Task::find($this->modalTaskId);
        if (!$task) return;

        $task->status = 'done';

        if ($this->doneNote && \Illuminate\Support\Facades\Schema::hasColumn('tasks', 'done_note')) {
            $task->done_note = $this->doneNote;
        }

        $task->save();
        $this->closeModal();
    }

    /*
    |--------------------------------------------------------------------------
    | Base Query
    |--------------------------------------------------------------------------
    */
    private function baseQueryWithoutFilters()
    {
        $user  = Auth::user();
        $today = Carbon::today();

        $query = $user->hasRole(['Director', 'Finance', 'super_admin'])
            ? Task::query()
            : Task::query()->whereHas('users', fn ($q) => $q->where('users.id', $user->id));

        $query->where(function ($q) use ($user) {
            $q->where('creator_id', '!=', $user->id)
              ->orWhere(function ($q2) {
                  $q2->whereRaw('(SELECT COUNT(*) FROM task_user WHERE task_user.task_id = tasks.id) = 1');
              });
        });

        return $query->with(['project', 'users'])
            ->whereDate('mulai_task', '<=', $today)
            ->whereDate('selesai_task', '>=', $today);
    }

    private function baseQuery()
    {
        $query = $this->baseQueryWithoutFilters();
        return $query->orderBy('mulai_task', 'asc');
    }

    public function getFilteredTasks()
    {
        $query = $this->baseQuery();
        $threeDaysAgo = Carbon::today()->subDays(3);

        return match ($this->filter) {
            'done' => $query
                ->where('status', 'done')
                ->limit(10)
                ->get(),

            'pending' => $query
                ->where('status', '!=', 'done')
                ->limit(10)
                ->get(),

            // 'all' — tampilkan semua, tapi done yang lebih dari 3 hari disembunyikan
            default => $query
                ->where(function ($q) use ($threeDaysAgo) {
                    $q->where('status', '!=', 'done')
                      ->orWhere(function ($q2) use ($threeDaysAgo) {
                          $q2->where('status', 'done')
                             ->where('updated_at', '>=', $threeDaysAgo);
                      });
                })
                ->limit(10)
                ->get(),
        };
    }

    public array $counts = [];

    public function mount(): void
    {
        $this->loadCounts();
    }

    private function loadCounts(): void
    {
        $baseQuery = $this->baseQueryWithoutFilters();

        $this->counts = [
            'all' => $baseQuery->count(),
            'pending' => (clone $baseQuery)->where('status', '!=', 'done')->count(),
            'done' => (clone $baseQuery)->where('status', 'done')->count(),
        ];
    }

    public function countAll(): int     { return $this->counts['all']; }
    public function countPending(): int { return $this->counts['pending']; }
    public function countDone(): int    { return $this->counts['done']; }

    public static function canView(): bool
    {
        return Auth::check();
    }

    protected function getPollingInterval(): ?string
    {
        return '30s';
    }
}