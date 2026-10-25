<?php

namespace App\Observers;

use App\Models\Expense;
use App\Models\User;
use App\Helpers\NotifHelper;

class ExpenseObserver
{
    // ── 1. User submit expense baru → Finance & Director ─────────────
    public function created(Expense $expense): void
    {
        $user    = $expense->user;
        $nama    = $user ? trim("{$user->firstname} {$user->lastname}") : 'Seseorang';
        $nominal = 'Rp ' . number_format($expense->amount, 0, ',', '.');
        $url     = '/admin/expenses';

        $recipients = User::role(['Finance', 'Director', 'super_admin'])->get()
            ->filter(fn($u) => $u->id !== $expense->user_id);

        NotifHelper::send(
            $recipients,
            'Pengajuan Expense Baru 💰',
            "{$nama} mengajukan expense {$nominal} — {$expense->keterangan_expense}",
            'heroicon-o-banknotes',
            'info',
            $url
        );
    }

    public function updated(Expense $expense): void
    {
        $user = $expense->user;
        $url  = '/admin/expenses';

        // ── 2. Status berubah → approved/rejected → notif ke user ────
        if ($expense->wasChanged('status')) {
            $nominal = 'Rp ' . number_format($expense->amount, 0, ',', '.');

            if ($expense->status === 'Disetujui') {
                NotifHelper::send(
                    $user,
                    'Expense Disetujui ✅',
                    "Pengajuan expense {$nominal} kamu telah disetujui. Silakan upload invoice pembelian.",
                    'heroicon-o-check-circle',
                    'success',
                    $url,
                    telegram: true
                );
            }

            if ($expense->status === 'Ditolak') {
                $alasan = $expense->rejection_reason ? " Alasan: {$expense->rejection_reason}" : '';
                NotifHelper::send(
                    $user,
                    'Expense Ditolak ❌',
                    "Pengajuan expense {$nominal} kamu ditolak.{$alasan}",
                    'heroicon-o-x-circle',
                    'danger',
                    $url,
                    telegram: true
                );
            }

            // ── 4. Status Dibayarkan → notif ke user ──────────────────
            if ($expense->status === 'Dibayarkan') {
                $paidBy = $expense->paid_by ? User::find($expense->paid_by) : null;
                $byName = $paidBy ? trim("{$paidBy->firstname} {$paidBy->lastname}") : 'Finance';
                $nominal = 'Rp ' . number_format($expense->paid ?? $expense->amount, 0, ',', '.');

                NotifHelper::send(
                    $user,
                    'Expense Sudah Dibayarkan 🎉',
                    "Expense kamu sebesar {$nominal} telah dibayarkan oleh {$byName}.",
                    'heroicon-o-banknotes',
                    'success',
                    $url,
                    telegram: true
                );
            }
        }

        // ── 3. User upload invoice (attachment berubah) → Finance ─────
        if ($expense->wasChanged('attachment') && !empty($expense->attachment)) {
            $nama    = $user ? trim("{$user->firstname} {$user->lastname}") : 'Seseorang';
            $nominal = 'Rp ' . number_format($expense->amount, 0, ',', '.');

            $recipients = User::role(['Finance', 'Director', 'super_admin'])->get()
                ->filter(fn($u) => $u->id !== $expense->user_id);

            NotifHelper::send(
                $recipients,
                'Invoice Expense Diunggah 📎',
                "{$nama} telah upload invoice untuk expense {$nominal}. Siap untuk dibayarkan.",
                'heroicon-o-paper-clip',
                'warning',
                $url
            );
        }
    }
}
