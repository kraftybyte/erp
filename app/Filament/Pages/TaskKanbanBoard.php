<?php

namespace App\Filament\Pages;

use App\Enums\TaskStages;
use App\Models\Task;
use App\Models\Project;
use App\Models\User;
use App\Helpers\NotifHelper;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Forms;
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
     * Load records
     */
    protected function records(): Collection
    {
        $user = auth()->user();

        if ($user->hasRole(['super_admin', 'Director'])) {
            return Task::with(['users', 'project'])->get();
        }

        return Task::with(['users', 'project'])
            ->whereHas('users', fn ($q) => $q->where('user_id', $user->id))
            ->get();
    }

    /**
     * HEADER ACTION
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('new_task')
                ->label('Tambah Task')
                ->icon('heroicon-o-plus-circle')
                ->authorize(fn () => auth()->user()?->hasAnyRole(['Head', 'Director', 'super_admin']))
                ->form($this->getCreateFormSchema())
                ->action(function (array $data) {

                    $task = Task::create([
                        'nama_task'    => $data['nama_task'],
                        'project_id'   => $data['project_id'],
                        'nama_layanan' => $data['nama_layanan'],
                        'status'       => $data['status'],
                        'proritas'     => $data['proritas'],
                        'detail_task'  => $data['detail_task'],
                        'mulai_task'   => $data['mulai_task'],
                        'selesai_task' => $data['selesai_task'],
                        'creator_id'   => auth()->id(),
                    ]);

                    /**
                     * 🔥 NOTIF TASK BARU (FIX)
                     */
                    if (!empty($data['user_id'])) {

                        $task->users()->sync(array_unique($data['user_id']));

                        $users = User::whereIn('id', $data['user_id'])->get();

                        $project = $task->project;
                        $klien   = $project?->nama_perusahaan ?? 'N/A';
                        $selesai = Carbon::parse($task->selesai_task)->format('d M Y');
                        $url     = '/admin/task-kanban-board';

                        foreach ($users as $user) {
                            if ($user->id === auth()->id()) continue;

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
     * FORM CREATE
     */
    protected function getCreateFormSchema(): array
    {
        return [
            Tabs::make('Task Form')
                ->tabs([
                    Tabs\Tab::make('Informasi Dasar')
                        ->schema([
                            TextInput::make('nama_task')->required(),

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

                            TextInput::make('nama_layanan')->required(),
                        ]),

                    Tabs\Tab::make('Tim & Status')
                        ->schema([
                            Select::make('user_id')
                                ->multiple()
                                ->searchable()
                                ->options(User::pluck('firstname', 'id'))
                                ->default([auth()->id()])
                                ->required(),

                            Select::make('status')
                                ->options($this->getEnumOptions(TaskStages::class))
                                ->required(),

                            Select::make('proritas')
                                ->options([
                                    'Urgent' => 'Urgent',
                                    'Normal' => 'Normal',
                                    'Low'    => 'Low',
                                ])
                                ->required(),
                        ]),

                    Tabs\Tab::make('Detail')
                        ->schema([
                            RichEditor::make('detail_task')->required(),
                        ]),

                    Tabs\Tab::make('Jadwal')
                        ->schema([
                            DatePicker::make('mulai_task')->required(),
                            DatePicker::make('selesai_task')->required(),
                        ]),
                ]),
        ];
    }

    /**
     * EDIT MODAL
     */
    protected function getEditModalFormSchema(string|int|null $recordId): array
    {
        return [
            Tabs::make('Detail Task')
                ->tabs([
                    Tabs\Tab::make('Informasi')
                        ->schema([
                            TextInput::make('nama_task')->disabled(),
                            Select::make('project_id')->relationship('project', 'nama_perusahaan')->disabled(),
                            TextInput::make('nama_layanan')->disabled(),
                        ]),

                    Tabs\Tab::make('Status')
                        ->schema([
                            Select::make('status')
                                ->options($this->getEnumOptions(TaskStages::class))
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
                ]),
        ];
    }

    /**
     * ENUM HELPER
     */
    protected function getEnumOptions(string $enumClass): array
    {
        return array_combine(
            array_map(fn ($case) => $case->value, $enumClass::cases()),
            array_map(fn ($case) => $case->name, $enumClass::cases())
        );
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
}