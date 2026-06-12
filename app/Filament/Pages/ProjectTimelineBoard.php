<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use App\Models\ProjectTimeline;
use App\Models\Event;
use App\Models\Task;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Actions\Action;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Auth;

class ProjectTimelineBoard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';
    protected static string $view = 'filament.pages.project-timeline-board';
    protected static ?string $navigationLabel = 'Board Project Timeline';
    protected static ?string $navigationGroup = 'Project';
    public ?string $lastHistoryText = null;
    public string $viewMode = 'board'; // board | list
    public ?string $lastUpdatedAt = null;

    public static function canAccess(): bool
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        return Gate::allows('view_page_project_timeline_board') || $user->hasRole('super_admin');
    }


    public array $resources = [];
    public array $events = [];

   public function mount(): void
{
    // Get all projects that have timeline OR tasks synced to calendar
    $syncedTaskProjectIds = Event::whereNotNull('task_id')
        ->with('task.project')
        ->get()
        ->pluck('task.project_id')
        ->filter()
        ->unique()
        ->toArray();

    $timelineProjects = ProjectTimeline::with('project')->get()->groupBy(fn ($item) => $item->project->id);

    // Build resources from timeline
    $timelineResources = $timelineProjects->map(function ($items, $projectId) {
        $projectName = $items->first()->project->nama_perusahaan;
        return [
            'id' => 'project-' . $projectId,
            'title' => $projectName,
            'children' => $items->map(fn ($item) => [
                'id' => 'phase-' . $item->id,
                'title' => $item->nama_phase,
            ])->values()->toArray(),
        ];
    })->values()->toArray();

    // Add projects from synced tasks (if not already in resources)
    $existingProjectIds = array_map(fn($r) => (int) str_replace('project-', '', $r['id']), $timelineResources);
    $projectsFromTasks = Project::whereIn('id', $syncedTaskProjectIds)
        ->whereNotIn('id', $existingProjectIds)
        ->get()
        ->map(fn($project) => [
            'id' => 'project-' . $project->id,
            'title' => $project->nama_perusahaan,
            'children' => [],
        ])
        ->toArray();

    $this->resources = array_merge($timelineResources, $projectsFromTasks);

    // Timeline events
    $timelineEvents = ProjectTimeline::all()
    ->map(fn($item) => [
        'id' => 'timeline-' . $item->id,
        'resourceId' => 'phase-' . $item->id,
        'title' => $item->nama_phase,
        'start' => $item->start_date->format('Y-m-d'),
        'end'   => $item->end_date->format('Y-m-d'),
        'backgroundColor' => $item->color ?? '#295661',
    ])->toArray();

    // Task-based events (from kanban sync)
    $taskEvents = Event::whereNotNull('task_id')
        ->with('task.project')
        ->get()
        ->map(function ($event) {
            $task = $event->task;
            if (!$task || !$task->project) return null;

            return [
                'id' => 'task-' . $event->id,
                'resourceId' => 'project-' . $task->project_id,
                'title' => $task->nama_task,
                'start' => $task->mulai_task ? Carbon::parse($task->mulai_task)->format('Y-m-d') : null,
                'end' => $task->selesai_task ? Carbon::parse($task->selesai_task)->format('Y-m-d') : null,
                'backgroundColor' => $event->color ?? '#6366f1',
            ];
        })
        ->filter()
        ->toArray();

    // Merge timeline events and task events
    $this->events = array_merge($timelineEvents, $taskEvents);

    $latest = ProjectTimeline::with('updater')
    ->latest('updated_at')
    ->first();

if ($latest && $latest->updater) {
    $this->lastHistoryText =
        $latest->updater->name .
        ' – ' .
        $latest->updated_at->translatedFormat('d F Y, H:i');
}

}


protected function getHeaderActions(): array
{
    return [
    
        Action::make('create')
            ->label('Tambah Timeline')
            ->icon('heroicon-o-plus')
            ->modalHeading('Tambah Timeline')
            ->modalWidth('lg')
            ->form([
            Forms\Components\Select::make('project_id')
            ->label('Perusahaan')
            ->options(
                \App\Models\Project::query()
                    ->whereHas('Deal', function ($q) {
                        $q->where('status', 'Dalam Tahap Project');
                    })
                    ->pluck('nama_perusahaan', 'id')
                    ->toArray()
            )
            ->searchable()
            ->required(),
            Forms\Components\Select::make('nama_phase')
                    ->label('Nama Phase')
                    ->options([
                        'Design' => 'Design',
                        'Content Planning' => 'Content Planning',
                        'Video Editing' => 'Video Editing',
                    ])
                    ->searchable()
                    ->preload()
                    ->allowHtml()
                    ->createOptionForm([
                        Forms\Components\TextInput::make('nama_phase')
                            ->label('Phase Baru')
                            ->required(),
                    ])
                    ->createOptionUsing(function (array $data) {
                        return $data['nama_phase'];
                    })
                    ->required(),


                Forms\Components\DatePicker::make('start_date')
                    ->required(),

                Forms\Components\DatePicker::make('end_date')
                    ->required(),

                Forms\Components\Select::make('status')
                    ->options([
                        'ongoing' => 'Ongoing',
                        'completed' => 'Completed',
                        'pending' => 'Pending',
                    ])
                    ->default('ongoing')
                    ->required(),

                Forms\Components\Textarea::make('notes'),
            ])
         ->action(function (array $data) {

            $phase = strtolower(trim($data['nama_phase']));

            $colorMap = [
                'content planning' => '#2394b8',
                'design' => '#ff5915',
                'video editing' => '#fcc530',
            ];

            $data['color'] = $colorMap[$phase] ?? '#295661';

            $timeline = ProjectTimeline::create($data);

           $this->dispatch('timeline-created', 
            id: $timeline->id,
            title: $timeline->nama_phase,
            start: $timeline->start_date->format('Y-m-d'),
            end: $timeline->end_date->format('Y-m-d'),
            resourceId: 'phase-' . $timeline->id,
            projectId: $timeline->project_id, // TAMBAHAN PENTING
            backgroundColor: $timeline->color,
        );


        }),
    ];
}

}
