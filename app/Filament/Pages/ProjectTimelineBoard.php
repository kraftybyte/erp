<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ProjectResource;
use App\Models\Project;
use App\Models\ProjectTimeline;
use App\Models\ProjectTimelineLog;
use App\Models\Task;
use App\Models\TaskStage;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;

/**
 * Board Project Timeline: phase setiap project (dan task-nya) di satu garis
 * waktu. Geser bar untuk memindah tanggal, tarik ujungnya untuk mengubah
 * durasi, tarik di baris kosong untuk menambah phase, klik bar untuk mengubah.
 *
 * Kalender memuat data per periode yang tampil lewat boardData(). Setiap
 * perubahan lewat method/aksi Livewire yang memeriksa policy Shield
 * (ProjectTimeline, Task); tanggal di kalender eksklusif di ujung, di
 * database inklusif.
 */
class ProjectTimelineBoard extends Page
{
    use HasPageShield;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static string $view = 'filament.pages.project-timeline-board';

    protected static ?string $navigationGroup = 'Project';

    public const FOCUSES = ['all', 'running', 'overdue'];

    /** Status task bila tahap Kanban belum diatur di database. */
    private const TASK_STATUSES = ['log' => 'To Do', 'process' => 'Progress', 'review' => 'Review', 'done' => 'Done', 'hold' => 'Hold'];

    private const TASK_COLORS = ['log' => '#f59e0b', 'process' => '#0891b2', 'review' => '#9333ea', 'done' => '#16a34a', 'hold' => '#ef4444'];

    private const PHASE_SUGGESTIONS = ['Content Planning', 'Design', 'Shooting', 'Video Editing', 'Review Klien', 'Revisi'];

    /** all | running | overdue */
    #[Url(except: 'all')]
    public string $focus = 'all';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: true)]
    public bool $showTasks = true;

    #[Url(except: false)]
    public bool $showDone = false;

    public static function getNavigationLabel(): string
    {
        return \App\Helpers\NavigationHelper::getLabel('timeline', 'Board Project Timeline');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function updated(string $property): void
    {
        if (! in_array($this->focus, self::FOCUSES, true)) {
            $this->focus = 'all';
        }

        if (in_array($property, ['focus', 'search', 'showTasks', 'showDone'], true)) {
            $this->dispatch('timeline-refresh');
        }
    }

    /**
     * Jumlah untuk tab Berjalan hari ini dan Terlambat (seluruh periode,
     * mengikuti pencarian dan pilihan Task).
     *
     * @return array{running: int, overdue: int}
     */
    #[Computed]
    public function counts(): array
    {
        $today = CarbonImmutable::today();

        return [
            'running' => $this->phaseQuery()->running($today)->count()
                + ($this->showTasks ? $this->runningTasks($this->taskQuery(), $today)->count() : 0),
            'overdue' => $this->phaseQuery()->overdue($today)->count()
                + ($this->showTasks ? $this->overdueTasks($this->taskQuery(), $today)->count() : 0),
        ];
    }

    public function canEditBoard(): bool
    {
        $user = auth()->user();

        return $user->can('create', ProjectTimeline::class) || $user->can('update', new ProjectTimeline());
    }

    /**
     * Baris (project → phase, task) dan bar untuk periode yang tampil.
     * $start dan $end tanggal Y-m-d; $end eksklusif.
     *
     * @return array{resources: list<array>, events: list<array>}
     */
    #[Renderless]
    public function boardData(string $start, string $end): array
    {
        $from  = $this->parseDate($start) ?? CarbonImmutable::today()->startOfMonth();
        $until = $this->parseDate($end) ?? $from->addMonth();
        $today = CarbonImmutable::today();
        $user  = auth()->user();

        // Phase/task terlambat tetap tampil sampai hari ini, bila hari ini ada di periode itu.
        $lateVisible = $from->lte($today);

        $phases = $this->phaseQuery()
            ->with(['project', 'updater'])
            ->whereDate('start_date', '<', $until)
            ->where(fn (Builder $q) => $q->whereDate('end_date', '>=', $from)
                ->when($lateVisible, fn (Builder $q) => $q->orWhere(fn (Builder $late) => $late->overdue($today))))
            ->when(! $this->showDone, fn (Builder $q) => $q->unfinished())
            ->when($this->focus === 'running', fn (Builder $q) => $q->running($today))
            ->when($this->focus === 'overdue', fn (Builder $q) => $q->overdue($today))
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        $tasks = ! $this->showTasks ? collect() : $this->taskQuery()
            ->with('project')
            ->where('mulai_task', '<', $until)
            ->where(fn (Builder $q) => $q->where('selesai_task', '>=', $from)
                ->when($lateVisible, fn (Builder $q) => $q->orWhere(fn (Builder $late) => $this->overdueTasks($late, $today))))
            ->when(! $this->showDone, fn (Builder $q) => $this->openTasks($q))
            ->when($this->focus === 'running', fn (Builder $q) => $this->runningTasks($q, $today))
            ->when($this->focus === 'overdue', fn (Builder $q) => $this->overdueTasks($q, $today))
            ->orderBy('mulai_task')
            ->get();

        $projectIds = $phases->pluck('project_id')->merge($tasks->pluck('project_id'))->unique()->values();
        $progress   = ProjectTimeline::query()
            ->whereIn('project_id', $projectIds)
            ->selectRaw('project_id, count(*) as total, sum(case when status = ? then 1 else 0 end) as done', [ProjectTimeline::DONE])
            ->groupBy('project_id')
            ->get()
            ->keyBy('project_id');
        $lateCount = ProjectTimeline::query()
            ->whereIn('project_id', $projectIds)
            ->overdue($today)
            ->selectRaw('project_id, count(*) as late')
            ->groupBy('project_id')
            ->pluck('late', 'project_id');

        // Project diurutkan dari yang paling awal dimulai di periode ini. toBase():
        // koleksi Eloquent yang kosong tetap Eloquent setelah map(), dan merge()
        // miliknya memanggil getKey() pada setiap baris (di sini array).
        $firstStart = $phases->toBase()->map(fn ($p) => [$p->project_id, $p->start_date->toDateString()])
            ->merge($tasks->toBase()->map(fn ($t) => [$t->project_id, Carbon::parse($t->mulai_task)->toDateString()]))
            ->groupBy(0)
            ->map(fn (Collection $rows) => $rows->min(1));
        $projects = Project::query()->whereIn('id', $projectIds)->get()
            ->sortBy(fn (Project $p) => [$firstStart[$p->id] ?? '9999', Str::lower($this->projectName($p))])
            ->values();

        $resources = [];
        $events    = [];

        foreach ($projects as $order => $project) {
            $children = [];

            foreach ($phases->where('project_id', $project->id) as $phase) {
                $editable   = $user->can('update', $phase);
                $children[] = [
                    'id'        => "phase-{$phase->id}",
                    'title'     => $phase->nama_phase,
                    'kind'      => 'phase',
                    'recordId'  => $phase->id,
                    'projectId' => $project->id,
                    'editable'  => $editable,
                    'sortKey'   => count($children),
                ];
                array_push($events, ...$this->phaseEvents($phase, $from, $today, $editable));
            }

            $projectTasks = $tasks->where('project_id', $project->id);

            if ($projectTasks->isNotEmpty()) {
                $children[] = [
                    'id'        => "tasks-{$project->id}",
                    'title'     => 'Task',
                    'kind'      => 'tasks',
                    'count'     => $projectTasks->count(),
                    'projectId' => $project->id,
                    'sortKey'   => count($children),
                ];

                foreach ($projectTasks as $task) {
                    array_push($events, ...$this->taskEvents($task, $from, $today, $user->can('update', $task)));
                }
            }

            $name        = $this->projectName($project);
            $resources[] = [
                'id'        => "project-{$project->id}",
                'title'     => $name,
                'subtitle'  => filled($project->nama_project) && $project->nama_project !== $name ? $project->nama_project : null,
                'kind'      => 'project',
                'projectId' => $project->id,
                'url'       => ProjectResource::getUrl('view', ['record' => $project]),
                'done'      => (int) ($progress[$project->id]->done ?? 0),
                'total'     => (int) ($progress[$project->id]->total ?? 0),
                'late'      => (int) ($lateCount[$project->id] ?? 0),
                'sortKey'   => $order,
                'children'  => $children,
            ];

            // Rentang project (dari data project) sebagai latar di baris project.
            if ($project->timeline_mulai && $project->timeline_selesai && $project->timeline_selesai->gte($project->timeline_mulai)) {
                $events[] = [
                    'id'         => "span-{$project->id}",
                    'resourceId' => "project-{$project->id}",
                    'start'      => $project->timeline_mulai->toDateString(),
                    'end'        => $project->timeline_selesai->copy()->addDay()->toDateString(),
                    'display'    => 'background',
                    'classNames' => ['tl-project-span'],
                ];
            }
        }

        return ['resources' => $resources, 'events' => $events];
    }

    /**
     * Bar digeser atau ujungnya ditarik. $end eksklusif (tanggal sesudah hari terakhir).
     *
     * @return array{ok: bool}
     */
    public function move(string $id, string $start, string $end = ''): array
    {
        [$kind, $key] = array_pad(explode('-', $id, 2), 2, null);
        $from         = $this->parseDate($start);
        $last         = $this->parseDate($end)?->subDay() ?? $from;

        if (! $from || ! $last || $last->lt($from) || ! ctype_digit((string) $key)) {
            return $this->moveFailed('Tanggal tidak terbaca. Muat ulang halaman lalu coba lagi.');
        }

        $user = auth()->user();

        if ($kind === 'phase' && ($phase = ProjectTimeline::find($key))) {
            if (! $user->can('update', $phase)) {
                return $this->moveFailed('Anda tidak punya izin mengubah timeline.');
            }

            $phase->update(['start_date' => $from, 'end_date' => $last, 'updated_by' => $user->id]);
            $this->boardChanged("{$phase->nama_phase}: " . $this->rangeLabel($from, $last));

            return ['ok' => true];
        }

        if ($kind === 'task' && ($task = Task::find($key))) {
            if (! $user->can('update', $task)) {
                return $this->moveFailed('Anda tidak punya izin mengubah task.');
            }

            // Jam mulai/selesai task dipertahankan; hanya tanggalnya yang pindah.
            $this->updateTask($task, [
                'mulai_task'   => $this->keepTime($from, $task->mulai_task),
                'selesai_task' => $this->keepTime($last, $task->selesai_task ?? $task->mulai_task),
            ]);
            $this->boardChanged("{$task->nama_task}: " . $this->rangeLabel($from, $last));

            return ['ok' => true];
        }

        return $this->moveFailed('Data tidak ditemukan; mungkin sudah dihapus. Muat ulang halaman.');
    }

    protected function getHeaderActions(): array
    {
        return [$this->historyAction(), $this->addPhaseAction()];
    }

    /** Riwayat perubahan (mengikuti pencarian); hanya bisa dibaca, tidak bisa dihapus. */
    public function historyAction(): Action
    {
        return Action::make('history')
            ->label('Riwayat')
            ->icon('heroicon-o-clock')
            ->color('gray')
            ->authorize(fn () => auth()->user()->can('viewAny', ProjectTimeline::class))
            ->slideOver()
            ->modalWidth('md')
            ->modalHeading('Riwayat perubahan')
            ->modalDescription(fn () => filled(trim($this->search))
                ? 'Project yang cocok dengan "' . trim($this->search) . '", 100 perubahan terakhir.'
                : '100 perubahan terakhir di semua project.')
            ->modalContent(fn () => view('filament.pages.partials.timeline-history', ['logs' => $this->historyLogs(), 'compact' => false]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Tutup');
    }

    /**
     * Tambah phase. Dari kalender (tarik di baris kosong) membawa arguments
     * project_id, start, dan end (eksklusif).
     */
    public function addPhaseAction(): Action
    {
        return Action::make('addPhase')
            ->label('Tambah phase')
            ->icon('heroicon-o-plus')
            ->authorize(fn () => auth()->user()->can('create', ProjectTimeline::class))
            ->modalHeading('Tambah phase')
            ->modalWidth('lg')
            ->fillForm(function (array $arguments): array {
                $start = $this->parseDate($arguments['start'] ?? '') ?? CarbonImmutable::today();
                $last  = $this->parseDate($arguments['end'] ?? '')?->subDay() ?? $start->addDays(6);

                return [
                    'project_id' => $arguments['project_id'] ?? null,
                    'start_date' => $start->toDateString(),
                    'end_date'   => $last->max($start)->toDateString(),
                    'status'     => 'ongoing',
                ];
            })
            ->form(fn () => [
                Forms\Components\Select::make('project_id')
                    ->label('Project')
                    ->options(fn () => $this->projectOptions())
                    ->searchable()
                    ->required(),
                ...$this->phaseFields(),
            ])
            ->modalSubmitActionLabel('Tambah')
            ->action(function (array $data): void {
                ProjectTimeline::create($data + [
                    'color'      => ProjectTimeline::colorFor($data['nama_phase']),
                    'updated_by' => auth()->id(),
                ]);
                $this->boardChanged('Phase ditambahkan.');
            });
    }

    public function editPhaseAction(): Action
    {
        return Action::make('editPhase')
            ->record(fn (array $arguments) => ProjectTimeline::with('project')->find($arguments['id'] ?? 0))
            ->authorize(fn (?ProjectTimeline $record) => $record !== null && auth()->user()->can('update', $record))
            ->modalHeading(fn (ProjectTimeline $record) => $record->nama_phase)
            ->modalDescription(fn (ProjectTimeline $record) => $record->project ? $this->projectName($record->project) : null)
            ->modalWidth('lg')
            ->fillForm(fn (ProjectTimeline $record): array => [
                'nama_phase' => $record->nama_phase,
                'start_date' => $record->start_date->toDateString(),
                'end_date'   => $record->end_date->toDateString(),
                'status'     => array_key_exists((string) $record->status, ProjectTimeline::STATUSES) ? $record->status : 'ongoing',
                'notes'      => $record->notes,
            ])
            ->form(fn (ProjectTimeline $record) => [
                ...$this->phaseFields(),
                Forms\Components\Placeholder::make('history')
                    ->label('Riwayat')
                    ->content(fn () => view('filament.pages.partials.timeline-history', [
                        'logs'    => $record->logs()->with('user')->limit(10)->get(),
                        'compact' => true,
                    ])),
            ])
            ->modalSubmitActionLabel('Simpan')
            ->action(function (ProjectTimeline $record, array $data): void {
                $record->update(Arr::only($data, ['nama_phase', 'start_date', 'end_date', 'status', 'notes']) + [
                    'color'      => ProjectTimeline::colorFor($data['nama_phase']),
                    'updated_by' => auth()->id(),
                ]);
                $this->boardChanged('Phase disimpan.');
            })
            ->extraModalFooterActions(fn (?ProjectTimeline $record): array => $record === null ? [] : [
                // Hapus hanya untuk yang punya izin Shield delete_project::timeline.
                Action::make('deletePhase')
                    ->label('Hapus phase')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Hapus phase ini?')
                    ->modalDescription("{$record->nama_phase} akan dihapus dari timeline. Penghapusan tercatat di riwayat.")
                    ->modalSubmitActionLabel('Hapus')
                    ->authorize(fn () => auth()->user()->can('delete', $record))
                    ->action(function () use ($record): void {
                        abort_unless(auth()->user()->can('delete', $record), 403);
                        $record->delete();
                        $this->boardChanged('Phase dihapus.');
                    })
                    ->cancelParentActions(),
            ]);
    }

    public function editTaskAction(): Action
    {
        return Action::make('editTask')
            ->record(fn (array $arguments) => Task::with('project')->find($arguments['id'] ?? 0))
            ->authorize(fn (?Task $record) => $record !== null && auth()->user()->can('update', $record))
            ->modalHeading(fn (Task $record) => $record->nama_task)
            ->modalDescription(fn (Task $record) => $record->project ? $this->projectName($record->project) : null)
            ->modalWidth('lg')
            ->fillForm(fn (Task $record): array => [
                'mulai'   => Carbon::parse($record->mulai_task)->toDateString(),
                'selesai' => Carbon::parse($record->selesai_task ?? $record->mulai_task)->toDateString(),
                'status'  => strtolower((string) $record->status),
            ])
            ->form(fn () => [
                Forms\Components\Grid::make(2)->schema([
                    $this->dateInput('mulai', 'Mulai'),
                    $this->dateInput('selesai', 'Selesai')->afterOrEqual('mulai'),
                ]),
                Forms\Components\ToggleButtons::make('status')
                    ->label('Status')
                    ->options($this->taskStatusOptions())
                    ->inline()
                    ->required(),
            ])
            ->modalSubmitActionLabel('Simpan')
            ->action(function (Task $record, array $data): void {
                $this->updateTask($record, [
                    'mulai_task'   => $this->keepTime(CarbonImmutable::parse($data['mulai']), $record->mulai_task),
                    'selesai_task' => $this->keepTime(CarbonImmutable::parse($data['selesai']), $record->selesai_task ?? $record->mulai_task),
                    'status'       => $data['status'],
                ]);
                $this->boardChanged('Task disimpan.');
            })
            ->extraModalFooterActions([
                Action::make('openKanban')
                    ->label('Buka Task Kanban')
                    ->color('gray')
                    ->url(TaskKanbanBoard::getUrl()),
            ]);
    }

    /** Ubah task dari board, dicatat di riwayat (tanggal dan status lama → baru). */
    private function updateTask(Task $task, array $attributes): void
    {
        $values = fn (Task $t): array => [
            'start'  => ProjectTimelineLog::day($t->mulai_task),
            'end'    => ProjectTimelineLog::day($t->selesai_task ?? $t->mulai_task),
            'status' => strtolower((string) $t->status),
        ];

        $old = $values($task);
        $task->update($attributes);

        ProjectTimelineLog::record('task', $task->getKey(), $task->project_id, (string) $task->nama_task, 'updated', $old, $values($task));
    }

    /** @return Collection<int, ProjectTimelineLog> */
    private function historyLogs(): Collection
    {
        $projectIds = filled(trim($this->search))
            ? $this->searchProjects(Project::query())->pluck('id')
            : null;

        return ProjectTimelineLog::query()
            ->with(['user', 'project'])
            ->when($projectIds !== null, fn (Builder $q) => $q->whereIn('project_id', $projectIds))
            ->latest('id')
            ->limit(100)
            ->get();
    }

    /** @return list<Forms\Components\Component> */
    private function phaseFields(): array
    {
        return [
            Forms\Components\TextInput::make('nama_phase')
                ->label('Nama phase')
                ->required()
                ->maxLength(255)
                ->autocomplete(false)
                ->datalist(fn () => $this->phaseSuggestions()),
            Forms\Components\Grid::make(2)->schema([
                $this->dateInput('start_date', 'Mulai'),
                $this->dateInput('end_date', 'Selesai')->afterOrEqual('start_date'),
            ]),
            Forms\Components\ToggleButtons::make('status')
                ->label('Status')
                ->options(ProjectTimeline::STATUSES)
                ->colors(['pending' => 'gray', 'ongoing' => 'info', 'delayed' => 'warning', 'completed' => 'success'])
                ->inline()
                ->required(),
            Forms\Components\Textarea::make('notes')
                ->label('Catatan')
                ->rows(2)
                ->maxLength(2000),
        ];
    }

    private function dateInput(string $name, string $label): Forms\Components\DatePicker
    {
        return Forms\Components\DatePicker::make($name)
            ->label($label)
            ->required()
            ->native(false)
            ->displayFormat('d M Y')
            ->firstDayOfWeek(1)
            ->closeOnDateSelection();
    }

    /** @return array<int, string> */
    private function projectOptions(): array
    {
        return Project::query()
            ->latest('id')
            ->limit(1000)
            ->get()
            ->mapWithKeys(fn (Project $p) => [$p->id => $this->projectName($p) . (filled($p->nama_project) && $p->nama_project !== $this->projectName($p) ? ' · ' . $p->nama_project : '')])
            ->all();
    }

    /** @return list<string> */
    private function phaseSuggestions(): array
    {
        return collect(self::PHASE_SUGGESTIONS)
            ->merge(ProjectTimeline::query()->distinct()->orderBy('nama_phase')->limit(50)->pluck('nama_phase'))
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique(fn ($name) => Str::lower($name))
            ->values()
            ->all();
    }

    /** @return array<string, string> */
    private function taskStatusOptions(): array
    {
        $options = array_filter(TaskStage::getOptions(), 'filled');

        return $options !== [] ? $options : self::TASK_STATUSES;
    }

    private function taskColor(string $status): string
    {
        $color = TaskStage::getColors()[$status]['color'] ?? null;

        return is_string($color) && preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : (self::TASK_COLORS[$status] ?? '#64748b');
    }

    /** @return list<array> Bar phase, ditambah tanda terlambat sampai hari ini. */
    private function phaseEvents(ProjectTimeline $phase, CarbonImmutable $from, CarbonImmutable $today, bool $editable): array
    {
        $color = preg_match('/^#[0-9a-f]{6}$/i', (string) $phase->color) ? $phase->color : ProjectTimeline::colorFor($phase->nama_phase);
        $start = $phase->start_date->toImmutable()->startOfDay();
        $last  = $phase->end_date->toImmutable()->startOfDay();
        $late  = $phase->daysLate($today);

        $tip = array_filter([
            $phase->nama_phase . ($phase->project ? ' — ' . $this->projectName($phase->project) : ''),
            $this->rangeLabel($start, $last) . ' (' . ($start->diffInDays($last) + 1) . ' hari)',
            'Status: ' . (ProjectTimeline::STATUSES[$phase->status] ?? ($phase->status ?: '-')),
            $late ? "Terlambat {$late} hari" : null,
            filled($phase->notes) ? Str::limit($phase->notes, 200) : null,
            $phase->updater ? 'Diubah ' . $phase->updater->name . ', ' . $phase->updated_at->translatedFormat('d M Y H:i') : null,
        ]);

        $bar = [
            'id'              => "phase-{$phase->id}",
            'resourceId'      => "phase-{$phase->id}",
            'title'           => $phase->nama_phase,
            'start'           => $start->toDateString(),
            'end'             => $last->addDay()->toDateString(),
            'allDay'          => true,
            'backgroundColor' => $color,
            'borderColor'     => $color,
            'textColor'       => self::textOn($color),
            'editable'        => $editable,
            'classNames'      => array_values(array_filter([$phase->isDone() ? 'tl-done' : null, $late ? 'tl-late' : null])),
            'kind'            => 'phase',
            'recordId'        => $phase->id,
            'done'            => $phase->isDone(),
            'late'            => $late,
            'tip'             => implode("\n", $tip),
        ];

        return [$bar, ...$this->lateMarker($bar, $last, $from, $today)];
    }

    /** @return list<array> Bar task, ditambah tanda terlambat sampai hari ini. */
    private function taskEvents(Task $task, CarbonImmutable $from, CarbonImmutable $today, bool $editable): array
    {
        $status = strtolower((string) $task->status);
        $start  = CarbonImmutable::parse($task->mulai_task)->startOfDay();
        $last   = CarbonImmutable::parse($task->selesai_task ?? $task->mulai_task)->startOfDay()->max($start);
        $done   = $status === 'done';
        $late   = ! $done && $last->lt($today) ? (int) $last->diffInDays($today) : 0;
        $color  = $this->taskColor($status);

        $bar = [
            'id'              => "task-{$task->id}",
            'resourceId'      => "tasks-{$task->project_id}",
            'title'           => $task->nama_task,
            'start'           => $start->toDateString(),
            'end'             => $last->addDay()->toDateString(),
            'allDay'          => true,
            'backgroundColor' => $color,
            'borderColor'     => $color,
            'textColor'       => self::textOn($color),
            'editable'        => $editable,
            'classNames'      => array_values(array_filter(['tl-task', $done ? 'tl-done' : null, $late ? 'tl-late' : null])),
            'kind'            => 'task',
            'recordId'        => $task->id,
            'done'            => $done,
            'late'            => $late,
            'tip'             => implode("\n", array_filter([
                'Task: ' . $task->nama_task,
                $this->rangeLabel($start, $last),
                'Status: ' . ($this->taskStatusOptions()[$status] ?? ($status ?: '-')),
                $late ? "Terlambat {$late} hari" : null,
            ])),
        ];

        return [$bar, ...$this->lateMarker($bar, $last, $from, $today)];
    }

    /**
     * Tanda terlambat dari hari sesudah tenggat sampai hari ini. Bila bar-nya
     * sudah di luar periode yang tampil, tandanya berupa bar berlabel yang
     * bisa diklik; bila tidak, cukup arsiran di belakang bar.
     *
     * @return list<array>
     */
    private function lateMarker(array $bar, CarbonImmutable $last, CarbonImmutable $from, CarbonImmutable $today): array
    {
        if (! $bar['late']) {
            return [];
        }

        $id = "late-{$bar['id']}";

        if ($last->lt($from)) {
            return [[
                'id'         => $id,
                'resourceId' => $bar['resourceId'],
                'title'      => $bar['title'],
                'start'      => $from->toDateString(),
                'end'        => $today->addDay()->toDateString(),
                'allDay'     => true,
                'editable'   => false,
                'classNames' => array_values(array_filter(['tl-late-chip', $bar['kind'] === 'task' ? 'tl-task' : null])),
                'kind'       => $bar['kind'],
                'recordId'   => $bar['recordId'],
                'late'       => $bar['late'],
                'tip'        => $bar['tip'],
            ]];
        }

        return [[
            'id'         => $id,
            'resourceId' => $bar['resourceId'],
            'start'      => $last->addDay()->toDateString(),
            'end'        => $today->addDay()->toDateString(),
            'display'    => 'background',
            'classNames' => ['tl-late-zone'],
        ]];
    }

    private function phaseQuery(): Builder
    {
        return ProjectTimeline::query()->whereHas('project', fn (Builder $q) => $this->searchProjects($q));
    }

    private function taskQuery(): Builder
    {
        return Task::query()
            ->whereNotNull('project_id')
            ->whereNotNull('mulai_task')
            ->whereHas('project', fn (Builder $q) => $this->searchProjects($q));
    }

    private function searchProjects(Builder $query): Builder
    {
        $term = trim($this->search);

        if ($term === '') {
            return $query;
        }

        $like = '%' . addcslashes($term, '%_\\') . '%';

        return $query->where(fn (Builder $q) => $q->where('nama_perusahaan', 'like', $like)->orWhere('nama_project', 'like', $like));
    }

    private function openTasks(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereRaw('lower(status) != ?', ['done'])->orWhereNull('status'));
    }

    private function overdueTasks(Builder $query, CarbonImmutable $today): Builder
    {
        return $this->openTasks($query)->where('selesai_task', '<', $today);
    }

    private function runningTasks(Builder $query, CarbonImmutable $today): Builder
    {
        return $this->openTasks($query)->where('mulai_task', '<', $today->addDay())->where('selesai_task', '>=', $today);
    }

    private function projectName(Project $project): string
    {
        return trim((string) ($project->nama_perusahaan ?: $project->nama_project)) ?: "Project #{$project->id}";
    }

    private function boardChanged(string $message): void
    {
        Notification::make()->title($message)->success()->send();
        $this->dispatch('timeline-refresh');
    }

    /** @return array{ok: false} */
    private function moveFailed(string $message): array
    {
        Notification::make()->title('Tidak tersimpan')->body($message)->danger()->send();

        return ['ok' => false];
    }

    private function parseDate(?string $value): ?CarbonImmutable
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) ? CarbonImmutable::createFromFormat('!Y-m-d', $value) : null;
    }

    private function keepTime(CarbonImmutable $date, mixed $original): CarbonImmutable
    {
        $time = $original ? Carbon::parse($original) : null;

        return $time ? $date->setTime($time->hour, $time->minute, $time->second) : $date;
    }

    private function rangeLabel(CarbonImmutable $from, CarbonImmutable $last): string
    {
        if ($from->isSameDay($last)) {
            return $from->translatedFormat('d M Y');
        }

        return $from->translatedFormat($from->isSameYear($last) ? 'd M' : 'd M Y') . ' – ' . $last->translatedFormat('d M Y');
    }

    /** Teks hitam atau putih, mana yang kontrasnya lebih tinggi di atas $hex. */
    private static function textOn(string $hex): string
    {
        $channel = function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $hex = ltrim($hex, '#');
        $lum = 0.2126 * $channel(substr($hex, 0, 2)) + 0.7152 * $channel(substr($hex, 2, 2)) + 0.0722 * $channel(substr($hex, 4, 2));

        // Kontras dengan putih (1.05 / (L + .05)) vs dengan #111827 (L ≈ .0123).
        return 1.05 / ($lum + 0.05) >= ($lum + 0.05) / 0.0623 ? '#ffffff' : '#111827';
    }
}
