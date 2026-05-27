<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Models\Task;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget\Card;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;

class EmployeeProductivity extends BaseWidget
{
    protected ?string $heading = 'Employee Productivity';
    protected int|string|array $columnSpan = 2;

    public static function canView(): bool
    {
        return true;
    }

    protected function getCards(): array
    {
        $currentMonth = Carbon::now()->startOfMonth();

        // Tasks completed this month
        $completedTasks = Task::where('status', 'Done')
            ->whereBetween('selesai_task', [$currentMonth, now()])
            ->count();

        // On-time completion rate
        $completedOnTime = Task::where('status', 'Done')
            ->whereBetween('selesai_task', [$currentMonth, now()])
            ->where('selesai_task', '>=', 'updated_at')
            ->count();

        $onTimeRate = $completedTasks > 0 ? round(($completedOnTime / $completedTasks) * 100, 1) : 0;

        // Total employees
        $totalEmployees = User::role(['Team', 'Head'])->count();

        // Active employees (with tasks this month)
        $activeEmployees = Task::whereBetween('updated_at', [$currentMonth, now()])
            ->distinct('creator_id')
            ->count('creator_id');

        // Avg tasks per employee
        $avgTasksPerEmployee = $totalEmployees > 0 ? round($completedTasks / $totalEmployees, 1) : 0;

        // In-progress tasks
        $inProgressTasks = Task::whereIn('status', ['Log', 'Process', 'Review'])->count();

        // Overdue tasks
        $overdueTasks = Task::whereIn('status', ['Log', 'Process', 'Review'])
            ->where('selesai_task', '<', now())
            ->count();

        return [
            Card::make('Completed This Month', $completedTasks)
                ->description('On-time: ' . $onTimeRate . '%')
                ->descriptionColor($onTimeRate >= 80 ? 'success' : ($onTimeRate >= 60 ? 'warning' : 'danger'))
                ->icon('heroicon-o-check-circle'),

            Card::make('Active Employees', $activeEmployees)
                ->description('of ' . $totalEmployees . ' total')
                ->icon('heroicon-o-users'),

            Card::make('Avg Tasks/Employee', $avgTasksPerEmployee)
                ->description('This month')
                ->icon('heroicon-o-chart-bar'),

            Card::make('In Progress', $inProgressTasks)
                ->description($overdueTasks . ' overdue')
                ->descriptionColor($overdueTasks > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-clock'),

            Card::make('Completion Rate', $onTimeRate . '%')
                ->description('On-time delivery')
                ->descriptionColor($onTimeRate >= 80 ? 'success' : ($onTimeRate >= 60 ? 'warning' : 'danger'))
                ->icon('heroicon-o-sparkles'),
        ];
    }
}