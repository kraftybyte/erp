<?php

namespace App\Filament\Pages;

use App\Enums\TaskStages;
use App\Models\Task;
use App\Models\Project;
use App\Models\User;
use App\Models\Event;
use App\Models\TaskStage;
use App\Helpers\NotifHelper;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Grid;

use Mokhosh\FilamentKanban\Pages\KanbanBoard;

class TaskKanbanBoard extends KanbanBoard
{
    use HasPageShield;
    protected static ?string $navigationIcon = 'heroicon-o-bolt';
    protected static ?int $navigationSort = 0;
    protected static ?string $navigationGroup = 'Project';
    protected static ?string $navigationLabel = 'Task';

    protected static string $model = Task::class;
    protected static string $statusEnum = TaskStages::class;
    protected static string $recordTitleAttribute = 'nama_task';
    protected static string $recordStatusAttribute = 'status';

    public static function getNavigationLabel(): string
    {
        return \App\Helpers\NavigationHelper::getLabel('task_kanban', 'Task');
    }

    protected bool $editModalSlideOver = true;
    public bool $disableEditModal = false;

    public string $viewMode = 'board';

    protected function getEditModalSlideOver(): bool
    {
        return true;
    }

    protected function getEditModalWidth(): string
    {
        return '3xl';
    }

    public function getTitle(): string
    {
        return 'Task';
    }

    /**
     * Override statuses to use database stages instead of enum
     */
    protected function statuses(): \Illuminate\Support\Collection
    {
        $dbStages = TaskStage::getActiveStages();

        if ($dbStages->isNotEmpty()) {
            // Return database stages as array with 'id' and 'title' keys
            return $dbStages->map(function ($stage) {
                return [
                    'id' => $stage->key,
                    'title' => $stage->name,
                ];
            });
        }

        // Fallback to enum
        return static::$statusEnum::statuses();
    }

    /**
     * Load records with eager loading
     */
    protected function records(): Collection
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (!$user) {
            return collect();
        }

        if ($user->hasRole(['super_admin', 'Director'])) {
            return Task::with(['users', 'project', 'stage'])->get();
        }

        return Task::with(['users', 'project', 'stage'])
            ->whereHas('users', fn ($q) => $q->where('user_id', $user->id))
            ->get();
    }

    /**
     * HEADER ACTION - Create New Task
     */
    protected function getHeaderActions(): array
    {
        /** @var User|null $currentUser */
        $currentUser = Auth::user();

        return [
            Action::make('new_task')
                ->label('Tambah Task')
                ->icon('heroicon-o-plus-circle')
                ->authorize(fn () => $currentUser?->hasAnyRole(['Head', 'Director', 'super_admin']))
                ->form($this->getCreateFormSchema())
                ->action(function (array $data) use ($currentUser) {

                    $task = Task::create([
                        'nama_task'    => $data['nama_task'],
                        'project_id'   => $data['project_id'],
                        'nama_layanan' => $data['nama_layanan'],
                        'status'       => $data['status'],
                        'stage_key'    => $data['status'], // Also set stage_key
                        'proritas'     => $data['proritas'],
                        'detail_task'  => $data['detail_task'] ?? null,
                        'mulai_task'   => $data['mulai_task'],
                        'selesai_task' => $data['selesai_task'],
                        'creator_id'   => $currentUser?->id,
                    ]);

                    if (!empty($data['user_id'])) {
                        $task->users()->sync(array_unique($data['user_id']));

                        $users = User::whereIn('id', $data['user_id'])->get();

                        $project = $task->project;
                        $klien   = $project?->nama_perusahaan ?? 'N/A';
                        $selesai = Carbon::parse($task->selesai_task)->format('d M Y');
                        $url     = '/admin/task-kanban-board';

                        foreach ($users as $user) {
                            if ($user->id === $currentUser?->id) continue;

                            NotifHelper::send(
                                $user,
                                'Task Baru Ditugaskan 📋',
                                "Kamu ditugaskan ke \"{$task->nama_task}\" — Klien: {$klien}. Deadline: {$selesai}.",
                                'heroicon-o-clipboard-document-list',
                                'info',
                                $url
                            );
                        }
                    }

                    Notification::make()
                        ->title('Task berhasil dibuat')
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * FORM CREATE - Tabbed Form
     */
    protected function getCreateFormSchema(): array
    {
        // Get stages from database (dynamic) or fallback to enum
        $stageOptions = TaskStage::getOptions();

        if (empty($stageOptions)) {
            // Fallback to enum if no DB stages
            $stageOptions = array_combine(
                array_map(fn ($case) => $case->value, TaskStages::cases()),
                array_map(fn ($case) => $case->label(), TaskStages::cases())
            );
        }

        return [
            Tabs::make('Task Form')
                ->tabs([
                    Tabs\Tab::make('Informasi Dasar')
                        ->schema([
                            TextInput::make('nama_task')
                                ->required()
                                ->placeholder('Nama task...'),

                            Select::make('project_id')
                                ->label('Nama Klien')
                                ->searchable()
                                ->options(Project::pluck('nama_perusahaan', 'id'))
                                ->required()
                                ->reactive()
                                ->afterStateUpdated(function ($state, callable $set) {
                                    if ($state) {
                                        $project = Project::find($state);
                                        $set('nama_layanan', $project?->nama_project ?? '');
                                    } else {
                                        $set('nama_layanan', '');
                                    }
                                }),

                            TextInput::make('nama_layanan')
                                ->required()
                                ->placeholder('Nama layanan...'),
                        ]),

                    Tabs\Tab::make('Tim & Status')
                        ->schema([
                            Select::make('user_id')
                                ->multiple()
                                ->searchable()
                                ->options(fn () => User::query()
                                    ->orderByRaw("COALESCE(firstname, lastname, 'Unknown')")
                                    ->get()
                                    ->mapWithKeys(fn ($user) => [$user->id => $user->name])
                                )
                                ->default(fn () => [Auth::user()?->id])
                                ->required(),

                            Select::make('status')
                                ->label('Stage')
                                ->options($stageOptions)
                                ->required(),

                            Select::make('proritas')
                                ->label('Prioritas')
                                ->options([
                                    'Urgent' => 'Urgent',
                                    'Normal' => 'Normal',
                                    'Low'    => 'Low',
                                ])
                                ->default('Normal')
                                ->required(),
                        ]),

                    Tabs\Tab::make('Detail')
                        ->schema([
                            RichEditor::make('detail_task')
                                ->placeholder('Detail task...'),
                        ]),

                    Tabs\Tab::make('Jadwal')
                        ->schema([
                            DatePicker::make('mulai_task')
                                ->required(),
                            DatePicker::make('selesai_task')
                                ->required(),
                        ]),
                ]),
        ];
    }

    /**
     * EDIT MODAL - View Only
     */
    protected function getEditModalFormSchema(string|int|null $recordId): array
    {
        // Get dynamic stage options
        $stageOptions = TaskStage::getOptions();

        if (empty($stageOptions)) {
            $stageOptions = array_combine(
                array_map(fn ($case) => $case->value, TaskStages::cases()),
                array_map(fn ($case) => $case->label(), TaskStages::cases())
            );
        }

        return [
            Tabs::make('Detail Task')
                ->tabs([
                    Tabs\Tab::make('Informasi')
                        ->schema([
                            TextInput::make('nama_task')->disabled(),
                            Select::make('project_id')
                                ->relationship('project', 'nama_perusahaan')
                                ->disabled(),
                            TextInput::make('nama_layanan')->disabled(),
                        ]),

                    Tabs\Tab::make('Status')
                        ->schema([
                            Select::make('status')
                                ->label('Stage')
                                ->options($stageOptions)
                                ->disabled(),
                            TextInput::make('proritas')->disabled(),
                        ]),

                    Tabs\Tab::make('Detail')
                        ->schema([
                            RichEditor::make('detail_task')->disabled(),
                            Grid::make(2)->schema([
                                DatePicker::make('mulai_task')->disabled(),
                                DatePicker::make('selesai_task')->disabled(),
                            ]),
                        ]),

                    Tabs\Tab::make('Tim')
                        ->schema([
                            $this->getAssigneesField(),
                        ]),
                ]),
        ];
    }

    /**
     * Get assignees field for edit modal
     */
    protected function getAssigneesField(): \Filament\Forms\Components\Placeholder
    {
        return \Filament\Forms\Components\Placeholder::make('assignees')
            ->label('Tim')
            ->content(function ($record) {
                // Handle null record (should not happen but safety check)
                if (!$record) {
                    return 'Loading...';
                }

                $users = $record->users;
                if ($users->isEmpty()) {
                    return 'Tidak ada assignee';
                }

                $html = '<div style="display: flex; flex-wrap: wrap; gap: 6px;">';
                foreach ($users as $user) {
                    $initial = strtoupper(substr($user->firstname ?? 'U', 0, 1));
                    $html .= '<span style="display: inline-flex; align-items: center; padding: 4px 10px; background: #f1f5f9; border-radius: 99px; font-size: 12px;">';
                    $html .= '<span style="width: 20px; height: 20px; border-radius: 50%; background: #0891b2; color: white; display: inline-flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; margin-right: 6px;">' . $initial . '</span>';
                    $html .= $user->name;
                    $html .= '</span>';
                }
                $html .= '</div>';

                return new \Illuminate\Support\HtmlString($html);
            });
    }

    /**
     * ENUM HELPER - Get options from TaskStages enum
     */
    protected function getEnumOptions(string $enumClass): array
    {
        return TaskStage::getOptions();
    }

    /**
     * SWITCH VIEW
     */
    public function switchView(string $mode): void
    {
        if ($mode === 'list') {
            $this->redirect('/admin/tasks');
            return;
        }

        $this->viewMode = $mode;
    }

    /**
     * GO TO TIMELINE
     */
    public function goToTimeline(): void
    {
        $this->redirect('/admin/project-timeline-board');
    }

    /**
     * TOGGLE CALENDAR EVENT
     * Create or remove Event from Task
     */
    public function toggleCalendarEvent(string|int $recordId): void
    {
        $task = Task::with('event')->findOrFail($recordId);

        if ($task->event) {
            $task->event->delete();
            Notification::make()
                ->title('Dihapus dari Calendar')
                ->success()
                ->send();
        } else {
            Event::create([
                'title' => $task->nama_task,
                'description' => $task->detail_task ?? '',
                'start_time' => $task->mulai_task,
                'end_time' => $task->selesai_task ?? $task->mulai_task,
                'color' => $this->getEventColor($task->status),
                'task_id' => $task->id,
                'create_by' => Auth::id(),
            ]);
            Notification::make()
                ->title('Ditambahkan ke Calendar')
                ->success()
                ->send();
        }
    }

    /**
     * Get event color based on task status
     * Now uses database colors if available
     */
    protected function getEventColor(string $status): string
    {
        // Try to get from database
        $stage = TaskStage::getByKey($status);

        if ($stage) {
            return $stage->color;
        }

        // Fallback to hardcoded colors
        return match ($status) {
            'log' => '#f59e0b',
            'process' => '#0891b2',
            'review' => '#9333ea',
            'done' => '#16a34a',
            'hold' => '#ef4444',
            default => '#6366f1',
        };
    }

    /**
     * Get stage colors for view
     * Returns array of key => [color, background]
     */
    public function getStageColors(): array
    {
        return TaskStage::getColors();
    }
}
