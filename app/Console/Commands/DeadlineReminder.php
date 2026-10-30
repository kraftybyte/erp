<?php

namespace App\Console\Commands;

use App\Helpers\NotifHelper;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Ingatkan deadline task (ke assignee dan pembuatnya) dan project (ke Director,
 * super_admin, dan assignee task-nya) yang jatuh dalam X hari, lewat lonceng
 * ERP dan Telegram penerima yang sudah terhubung. Dijalankan setiap pagi.
 */
class DeadlineReminder extends Command
{
    protected $signature = 'deadline:reminder {--days=3}';
    protected $description = 'Send reminders for tasks/projects due in X days';

    /** Task yang tidak perlu diingatkan: selesai atau sengaja ditahan. */
    private const TASK_CLOSED = ['done', 'hold'];

    private const PROJECT_DONE = ['completed', 'selesai', 'done'];

    public function handle(): int
    {
        $days  = (int) $this->option('days');
        $today = Carbon::today();
        $until = $today->copy()->addDays($days)->endOfDay();

        $this->info("Checking deadlines for next {$days} days...");

        // ===== TASKS =====
        $tasks = Task::whereNotIn('status', self::TASK_CLOSED)
            ->whereBetween('selesai_task', [$today, $until])
            ->with(['users', 'project'])
            ->get();

        foreach ($tasks as $task) {
            $deadline = Carbon::parse($task->selesai_task);
            $body     = "Task \"{$task->nama_task}\" deadline " . $this->when($deadline, $today) . " ({$deadline->locale('id')->translatedFormat('j M Y')}).";

            $recipients = $task->users;
            $creator    = $task->creator_id ? User::find($task->creator_id) : null;

            if ($creator && ! $recipients->contains('id', $creator->id)) {
                $recipients = $recipients->push($creator);
            }

            NotifHelper::send($recipients, 'Deadline task', $body, 'heroicon-o-calendar', 'warning', '/admin/tasks', telegram: true);
        }

        $this->info("Sent {$tasks->count()} task deadline reminders.");

        // ===== PROJECTS =====
        $projects = Project::whereBetween('timeline_selesai', [$today, $until])
            ->where(fn (Builder $query) => $query->whereNull('status')->orWhereNotIn('status', self::PROJECT_DONE))
            ->with(['deal', 'tasks.users'])
            ->get();

        $managers = User::where('is_active', true)
            ->whereHas('roles', fn (Builder $query) => $query->whereIn('name', ['Director', 'super_admin']))
            ->get();

        foreach ($projects as $project) {
            $recipients = $managers->merge($project->tasks->flatMap->users)->unique('id');
            $clientName = $project->nama_perusahaan ?: ($project->deal?->nama_perusahaan ?: 'tanpa klien');
            $body       = "Project \"{$project->nama_project}\" ({$clientName}) deadline " . $this->when($project->timeline_selesai, $today)
                . " ({$project->timeline_selesai->locale('id')->translatedFormat('j M Y')}).";

            NotifHelper::send($recipients, 'Deadline project', $body, 'heroicon-o-building-office', 'warning', '/admin/projects/' . $project->id, telegram: true);
        }

        $this->info("Sent {$projects->count()} project deadline reminders.");

        Log::info('DeadlineReminder completed', [
            'tasks_notified'    => $tasks->count(),
            'projects_notified' => $projects->count(),
            'days_ahead'        => $days,
        ]);

        return Command::SUCCESS;
    }

    /** "hari ini", "besok", "dalam 3 hari". */
    private function when(Carbon $deadline, Carbon $today): string
    {
        $days = (int) $today->diffInDays($deadline->copy()->startOfDay(), false);

        return match ($days) {
            0       => 'hari ini',
            1       => 'besok',
            default => "dalam {$days} hari",
        };
    }
}
