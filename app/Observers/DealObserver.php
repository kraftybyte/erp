<?php

namespace App\Observers;

use App\Models\Deal;
use App\Models\User;
use App\Helpers\NotifHelper;

class DealObserver
{
    /**
     * Handle the Deal "created" event.
     */
    public function created(Deal $deal): void
    {
        // Notify Directors when new deal is created (converted from Lead)
        if ($deal->lead_id) {
            $recipients = User::role(['Director', 'Sales', 'super_admin'])->get();

            NotifHelper::send(
                $recipients,
                'Deal Baru dari Lead 🎯',
                "Deal baru: {$deal->nama_perusahaan} (Lead #{$deal->lead_id})",
                'heroicon-o-currency-dollar',
                'info',
                '/admin/deals'
            );
        }
    }

    /**
     * Handle the Deal "updated" event.
     */
    public function updated(Deal $deal): void
    {
        $url = '/admin/deals';

        // Status change notifications
        if ($deal->wasChanged('status')) {
            $recipients = User::role(['Director', 'Finance', 'super_admin'])->get();

            match ($deal->status) {
                'Negosiasi' => NotifHelper::send(
                    $recipients,
                    'Deal dalam Negosiasi 📝',
                    "Deal {$deal->nama_perusahaan} sekarang dalam tahap negosiasi.",
                    'heroicon-o-chat-bubble-left-ellipsis',
                    'info',
                    $url
                ),
                'Menunggu Pembayaran' => NotifHelper::send(
                    $recipients,
                    'Deal Menunggu Pembayaran 💰',
                    "Deal {$deal->nama_perusahaan} menunggu pembayaran.",
                    'heroicon-o-clock',
                    'warning',
                    $url
                ),
                'Dalam Tahap Project' => NotifHelper::send(
                    $recipients,
                    'Deal: Project Dimulai 🚀',
                    "Deal {$deal->nama_perusahaan} sudah masuk tahap project.",
                    'heroicon-o-rocket-launch',
                    'success',
                    $url
                ),
                'Selesai' => NotifHelper::send(
                    $recipients,
                    'Deal Selesai ✅',
                    "Deal {$deal->nama_perusahaan} telah selesai!",
                    'heroicon-o-check-circle',
                    'success',
                    $url
                ),
                'Hold' => NotifHelper::send(
                    $recipients,
                    'Deal di-Hold ⏸️',
                    "Deal {$deal->nama_perusahaan} di-hold sementara.",
                    'heroicon-o-pause-circle',
                    'warning',
                    $url
                ),
                default => null,
            };
        }
    }
}