<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

class TaskWidget extends Widget
{
        protected int|string|array $columnSpan = [
    'default' => 'full',
    'md' => 1,
    'lg' => 1,
    'xl' => 1,
    ];
    protected static string $view = 'filament.widgets.task-widget';
    protected static ?int $sort = 1;

    public function getData(): array
    {
        $userId = auth()->id(); // Mendapatkan ID user yang sedang login

        // Mengambil rata-rata waktu pengerjaan task yang diassign, bukan pembuat task dan status 'done'
        $averageDays = DB::table('tasks')
            ->join('task_user', 'tasks.id', '=', 'task_user.task_id')
            ->where('task_user.user_id', $userId) // Menyaring berdasarkan user yang diassign
            ->where('tasks.creator_id', '!=', $userId) // Pastikan user bukan pembuat task
            ->where('tasks.status', 'done') // Hanya task yang sudah selesai
            ->whereNotNull('tasks.selesai_task') // Pastikan selesai_task ada
            ->whereNotNull('tasks.mulai_task') // Pastikan mulai_task ada
            ->select(DB::raw('AVG(DATEDIFF(tasks.selesai_task, tasks.mulai_task)) as avg_days'))
            ->first(); // Ambil rata-rata dalam hari

        // Mengembalikan data rata-rata pengerjaan dalam angka bulat
        // has_data: tanpa task selesai, rata-ratanya kosong, bukan "0 hari, Sangat Baik".
        return [
            'average_days' => round($averageDays->avg_days ?? 0),
            'has_data'     => $averageDays->avg_days !== null,
        ];
    }

    public function render(): \Illuminate\View\View
    {
        return view('filament.widgets.task-average-widget', $this->getData());
    }
}
