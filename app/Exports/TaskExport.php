<?php

namespace App\Exports;

use App\Models\Task;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Illuminate\Database\Eloquent\Builder;

class TaskExport implements FromQuery, WithHeadings, WithMapping
{
    public function query(): Builder
    {
        return Task::query()
            ->with(['users', 'project', 'deal'])
            ->orderBy('created_at', 'desc');
    }

    public function headings(): array
    {
        return [
            'ID',
            'Nama Task',
            'Project',
            'Client',
            'Status',
            'Prioritas',
            'Team Members',
            'Mulai',
            'Deadline',
            'Dibuat',
        ];
    }

    public function map($task): array
    {
        return [
            $task->id,
            $task->nama_task,
            $task->project ? $task->project->nama_project : '-',
            $task->deal ? $task->deal->nama_perusahaan : '-',
            $task->status,
            $task->proritas,
            $task->users->pluck('firstname')->implode(', '),
            $task->mulai_task?->format('d M Y'),
            $task->selesai_task?->format('d M Y'),
            $task->created_at?->format('d M Y H:i'),
        ];
    }
}
