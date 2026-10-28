<?php

namespace App\Observers;

use App\Models\Absence;
use App\Models\User;
use App\Helpers\NotifHelper;
use Carbon\Carbon;

class AbsenceObserver
{
    public function created(Absence $absence): void
    {
        $karyawan = $absence->user;
        $nama     = $karyawan ? trim("{$karyawan->firstname} {$karyawan->lastname}") : 'Seseorang';
        $kategori = ucfirst($absence->category);
        $dari     = Carbon::parse($absence->start_time)->format('d M Y');
        $sampai   = Carbon::parse($absence->end_time)->format('d M Y');
        $url      = '/admin/absences';

        // Tambahkan catatan jika ada surat sakit
        $extraNote = '';
        if ($absence->category === 'sakit' && $absence->sick_letter) {
            $extraNote = ' [📎 Surat dokter terlampir]';
        }

        $recipients = User::role(['Head', 'Director', 'super_admin'])->get()
            ->filter(fn($u) => $u->id !== $absence->user_id);

        NotifHelper::send(
            $recipients,
            'Pengajuan Absensi Baru',
            "{$nama} mengajukan {$kategori} ({$dari} – {$sampai}){$extraNote}",
            'heroicon-o-calendar',
            'info',
            $url
        );
    }

    public function updated(Absence $absence): void
    {
        if (!$absence->wasChanged('approval_status')) return;

        $karyawan = $absence->user;
        if (!$karyawan) return;

        $status     = $absence->approval_status;
        $kategori   = ucfirst($absence->category);
        $dari       = Carbon::parse($absence->start_time)->format('d M Y');
        $sampai     = Carbon::parse($absence->end_time)->format('d M Y');
        $isApproved = strtolower($status) === 'approved';
        $url        = '/admin/absences';

        $alasan = ! $isApproved && filled($absence->rejection_reason) ? " Alasan: {$absence->rejection_reason}" : '';

        NotifHelper::send(
            $karyawan,
            $isApproved ? 'Absensi Disetujui ✅' : 'Absensi Ditolak ❌',
            "Pengajuan {$kategori} ({$dari} – {$sampai}) telah " . ($isApproved ? 'disetujui' : 'ditolak') . ".{$alasan}",
            $isApproved ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle',
            $isApproved ? 'success' : 'danger',
            $url
        );
    }
}
