<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Models\Project;
use App\Models\User;
use App\Helpers\NotifHelper;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DeadlineReminder extends Command
{
    protected $signature = 'deadline:reminder {--days=3}';
    protected $description = 'Send reminders for tasks/projects due in X days';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $threshold = Carbon::now()->addDays($days)->endOfDay();
        $startDate = Carbon::now()->startOfDay();

        $this->info("Checking deadlines for next {$days} days...");

        $count = 0;

        // ===== TASKS =====
        $tasks = Task::whereIn('status', ['log', 'process', 'review'])
            ->whereBetween('selesai_task', [$startDate, $threshold])
            ->with(['users', 'project'])
            ->get();

        foreach ($tasks as $task) {
            $daysLeft = Carbon::now()->diffInDays($task->selesai_task, false);

            // Notify each assigned user
            $assignees = $task->users;

            foreach ($assignees as $user) {
                NotifHelper::send(
                    $user,
                    'Task Deadline Reminder ⏰',
                    "Task \"{$task->nama_task}\" deadline dalam {$daysLeft} hari ({$task->selesai_task->format('d M Y')}).",
                    'heroicon-o-calendar',
                    'warning',
                    '/admin/tasks'
                );
            }

            // Also notify creator if different from assignees
            if ($task->creator_id) {
                $creator = User::find($task->creator_id);
                if ($creator && !$assignees->contains('id', $creator->id)) {
                    NotifHelper::send(
                        $creator,
                        'Task Deadline Reminder (Creator) ⏰',
                        "Task \"{$task->nama_task}\" deadline dalam {$daysLeft} hari ({$task->selesai_task->format('d M Y')}).",
                        'heroicon-o-calendar',
                        'warning',
                        '/admin/tasks'
                    );
                }
            }

            $count++;
        }

        $this->info("Sent {$count} task deadline reminders.");

        // ===== PROJECTS =====
        $projects = Project::whereBetween('timeline_selesai', [$startDate, $threshold])
            ->with(['deal', 'tasks'])
            ->get();

        $projectCount = 0;
        foreach ($projects as $project) {
            $daysLeft = Carbon::now()->diffInDays($project->timeline_selesai, false);

            // Notify project creator / assigned users
            $notifyUsers = User::role(['Director', 'Project Manager', 'super_admin'])->get();

            // Add task assignees
            $taskAssignees = collect();
            foreach ($project->tasks as $task) {
                $taskAssignees = $taskAssignees->merge($task->users);
            }
            $notifyUsers = $notifyUsers->merge($taskAssignees)->unique();

            $clientName = $project->deal ? $project->deal->nama_perusahaan : 'No Client';
            foreach ($notifyUsers as $user) {
                NotifHelper::send(
                    $user,
                    'Project Deadline Reminder',
                    "Project \"{$project->nama_project}\" ({$clientName}) deadline dalam {$daysLeft} hari ({$project->timeline_selesai->format('d M Y')}).",
                    'heroicon-o-building-office',
                    'warning',
                    '/admin/projects'
                );
            }

            $projectCount++;
        }

        $this->info("Sent {$projectCount} project deadline reminders.");

        Log::info('DeadlineReminder completed', [
            'tasks_notified' => $count,
            'projects_notified' => $projectCount,
            'days_ahead' => $days,
        ]);

        return Command::SUCCESS;
    }
}