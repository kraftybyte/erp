<?php

namespace App\Observers;

use App\Models\Task;
use App\Helpers\NotifHelper;
use App\Services\WablasService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class TaskObserver
{
    /**
     * ❌ JANGAN dipakai untuk notif (users belum ada)
     */
    public function created(Task $task): void
    {
        Log::info('Task created (no notif here)', ['task_id' => $task->id]);
    }

    /**
     * 🔄 Update task (status / deadline / WA)
     */
    public function updated(Task $task): void
    {
        Log::info('Observer UPDATED jalan', ['task_id' => $task->id]);

        // 🔥 Anti spam
        $cacheKey = "task_notif_{$task->id}";
        if (Cache::has($cacheKey)) return;
        Cache::put($cacheKey, true, now()->addSeconds(3));

        if (!$task->wasChanged(['status', 'proritas', 'selesai_task'])) return;

        $task = $task->fresh(['users', 'project']);

        $users       = $task->users;
        $project     = $task->project;
        $klien       = $project?->nama_perusahaan ?? 'N/A';
        $selesaiTask = Carbon::parse($task->selesai_task);
        $mulaiTask   = Carbon::parse($task->mulai_task);
        $durasiHari  = max(1, $mulaiTask->diffInDays($selesaiTask));
        $isDone      = $task->status === 'Done';
        $url         = '/admin/task-kanban-board';

        if ($users->isEmpty()) {
            Log::warning('User kosong saat update', ['task_id' => $task->id]);
            return;
        }

        foreach ($users as $user) {

            /**
             * 🔥 STATUS BERUBAH
             */
            if ($task->wasChanged('status')) {
                NotifHelper::send(
                    $user,
                    $isDone ? 'Task Selesai! 🎉' : 'Status Task Diperbarui',
                    "\"{$task->nama_task}\" — status: {$task->status}",
                    $isDone ? 'heroicon-o-check-circle' : 'heroicon-o-arrow-path',
                    $isDone ? 'success' : 'info',
                    $url
                );
            }

            /**
             * 🔥 DEADLINE BERUBAH
             */
            if ($task->wasChanged('selesai_task')) {
                NotifHelper::send(
                    $user,
                    'Deadline Task Berubah ⚠️',
                    "\"{$task->nama_task}\" — deadline baru: {$selesaiTask->format('d M Y')}",
                    'heroicon-o-calendar-days',
                    'warning',
                    $url
                );
            }

            /**
             * 🔥 WHATSAPP
             */
            if ($user->phone) {
                try {
                    app(WablasService::class)->sendMessage(
                        $user->phone,
                        "Hai {$user->firstname}, ada update tugas buat kamu! 🚀\n"
                        . "Tugas: *{$task->nama_task}*\n"
                        . "Klien: *{$klien}*\n"
                        . "Status: *{$task->status}*\n"
                        . "Prioritas: *{$task->proritas}*\n"
                        . "Deadline: *{$selesaiTask->format('d M Y')}* ({$durasiHari} hari)\n\n"
                        . "Semangat 💪"
                    );
                } catch (\Throwable $e) {
                    Log::error('Gagal kirim WA', [
                        'task_id' => $task->id,
                        'error'   => $e->getMessage()
                    ]);
                }
            }
        }

        /**
         * 🔥 NOTIF KE CREATOR
         */
        if ($task->wasChanged('status') && $task->creator_id) {

            $creator   = \App\Models\User::find($task->creator_id);
            $changedBy = auth()->user();
            $byName    = $changedBy ? $changedBy->firstname : 'Seseorang';

            if ($creator) {
                NotifHelper::send(
                    $creator,
                    $isDone ? 'Task Selesai! ✅' : 'Status Task Diperbarui',
                    "\"{$task->nama_task}\" diubah ke {$task->status} oleh {$byName}.",
                    $isDone ? 'heroicon-o-check-badge' : 'heroicon-o-arrow-path',
                    $isDone ? 'success' : 'info',
                    $url
                );
            }
        }

        Log::info("Task {$task->id} notif update selesai");
    }
}