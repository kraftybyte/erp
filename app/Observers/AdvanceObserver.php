<?php

namespace App\Observers;

use App\Models\Advance;
use App\Models\User;
use App\Helpers\NotifHelper;

class AdvanceObserver
{
    // ── 1. Submit advance baru → Finance & Director ───────────────
    public function created(Advance $advance): void
    {
        $user    = $advance->user;
        $nama    = $user ? trim("{$user->firstname} {$user->lastname}") : 'Seseorang';
        $nominal = 'Rp ' . number_format($advance->amount, 0, ',', '.');
        $url     = '/admin/advances';

        $recipients = User::role(['Finance', 'Director', 'super_admin'])->get()
            ->filter(fn($u) => $u->id !== $advance->user_id);

        NotifHelper::send(
            $recipients,
            'Pengajuan Advance Baru 💸',
            "{$nama} mengajukan advance {$nominal} — {$advance->keterangan_advance}",
            'heroicon-o-banknotes',
            'info',
            $url
        );
    }

    public function updated(Advance $advance): void
    {
        $user = $advance->user;
        $url  = '/admin/advances';

        if (!$advance->wasChanged('status')) return;

        $nominal = 'Rp ' . number_format($advance->amount, 0, ',', '.');

        switch ($advance->status) {

            // ── 2. Disetujui → notif ke user ──────────────────────
            case Advance::STATUS_DISETUJUI:
                NotifHelper::send(
                    $user,
                    'Advance Disetujui ✅',
                    "Pengajuan advance {$nominal} kamu telah disetujui. Menunggu pembayaran dari Finance.",
                    'heroicon-o-check-circle',
                    'success',
                    $url
                );
                break;

            // ── 2. Ditolak → notif ke user ────────────────────────
            case Advance::STATUS_DITOLAK:
                $alasan = $advance->rejection_reason ? " Alasan: {$advance->rejection_reason}" : '';
                NotifHelper::send(
                    $user,
                    'Advance Ditolak ❌',
                    "Pengajuan advance {$nominal} kamu ditolak.{$alasan}",
                    'heroicon-o-x-circle',
                    'danger',
                    $url
                );
                break;

            // ── 3. Dibayarkan → notif ke user, minta realisasi ────
            case Advance::STATUS_DIBAYARKAN:
                $paidBy = $advance->paid_by ? User::find($advance->paid_by) : null;
                $byName = $paidBy ? trim("{$paidBy->firstname} {$paidBy->lastname}") : 'Finance';
                $dibayar = 'Rp ' . number_format($advance->paid ?? $advance->amount, 0, ',', '.');

                NotifHelper::send(
                    $user,
                    'Advance Sudah Dibayarkan 💰',
                    "Advance {$dibayar} telah dibayarkan oleh {$byName}. Silakan lakukan realisasi setelah selesai.",
                    'heroicon-o-banknotes',
                    'success',
                    $url
                );
                break;

            // ── 4. Sudah direalisasi user → Finance & Director ────
            // Pakai konstanta, bukan string literal: nilainya pernah berubah
            // ("Sudah" → "Sedang") dan case ini jadi tidak pernah cocok.
            case Advance::STATUS_REALIZED_USER:
                $nama = $user ? trim("{$user->firstname} {$user->lastname}") : 'Seseorang';

                $recipients = User::role(['Finance', 'Director', 'super_admin'])->get()
                    ->filter(fn($u) => $u->id !== $advance->user_id);

                NotifHelper::send(
                    $recipients,
                    'Advance Sudah Direalisasi 📎',
                    "{$nama} telah merealisasi advance {$nominal}. Silakan verifikasi dan selesaikan.",
                    'heroicon-o-paper-clip',
                    'warning',
                    $url
                );
                break;

            // ── 5. Selesai → notif ke user ────────────────────────
            case Advance::STATUS_SELESAI:
                NotifHelper::send(
                    $user,
                    'Advance Selesai ✅',
                    "Advance {$nominal} kamu telah selesai diverifikasi oleh Finance.",
                    'heroicon-o-check-badge',
                    'success',
                    $url
                );
                break;
        }
    }
}
