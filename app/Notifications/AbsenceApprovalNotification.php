<?php

namespace App\Notifications;

use App\Models\Absence;
use Illuminate\Notifications\Notification;

class AbsenceApprovalNotification extends Notification
{
    public function __construct(
        public Absence $absence,
        public string $status
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $isApproved = $this->status === 'approved';
        $categoryLabel = match ($this->absence->category) {
            'cuti' => 'Cuti',
            'wfa' => 'WFA',
            'tukar_hari' => 'Tukar Hari',
            'sakit' => 'Sakit',
            default => ucfirst($this->absence->category),
        };

        return [
            'title' => $isApproved ? 'Pengajuan Disetujui' : 'Pengajuan Ditolak',
            'body' => "Pengajuan {$categoryLabel} ({$this->absence->start_time->format('d M')} - {$this->absence->end_time->format('d M Y')}) telah " . ($isApproved ? 'disetujui' : 'ditolak'),
            'icon' => $isApproved ? 'heroicon-o-check-circle text-success' : 'heroicon-o-x-circle text-danger',
            'url' => '/admin/absences',
        ];
    }
}