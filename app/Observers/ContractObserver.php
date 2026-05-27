<?php

namespace App\Observers;

use App\Models\Contract;
use App\Models\User;
use App\Helpers\NotifHelper;
use Carbon\Carbon;

class ContractObserver
{
    /**
     * Handle the Contract "created" event.
     */
    public function created(Contract $contract): void
    {
        $url = '/admin/contracts';
        $recipients = User::role(['Director', 'Finance', 'super_admin'])->get();
        $value = 'Rp ' . number_format($contract->total_value ?? 0, 0, ',', '.');

        NotifHelper::send(
            $recipients,
            'Kontrak Baru 📑',
            "Kontrak baru: {$contract->client_name_full} - Nilai {$value}",
            'heroicon-o-document-check',
            'info',
            $url
        );
    }

    /**
     * Handle the Contract "updated" event.
     */
    public function updated(Contract $contract): void
    {
        $url = '/admin/contracts';

        // Check for expiring contracts (within 30 days)
        if ($contract->wasChanged('expiration_date') && $contract->expiration_date) {
            $daysUntil = Carbon::now()->diffInDays($contract->expiration_date, false);

            if ($daysUntil > 0 && $daysUntil <= 30) {
                $recipients = User::role(['Director', 'Finance', 'super_admin'])->get();

                NotifHelper::send(
                    $recipients,
                    'Kontrak Akan Berakhir ⚠️',
                    "Kontrak {$contract->client_name_full} akan berakhir dalam {$daysUntil} hari ({$contract->expiration_date->format('d M Y')})",
                    'heroicon-o-exclamation-triangle',
                    'warning',
                    $url
                );
            }
        }
    }

    /**
     * Handle the Contract "deleted" event.
     */
    public function deleted(Contract $contract): void
    {
        $recipients = User::role(['Director', 'super_admin'])->get();

        NotifHelper::send(
            $recipients,
            'Kontrak Dihapus 🗑️',
            "Kontrak {$contract->client_name_full} telah dihapus.",
            'heroicon-o-trash',
            'danger',
            '/admin/contracts'
        );
    }
}