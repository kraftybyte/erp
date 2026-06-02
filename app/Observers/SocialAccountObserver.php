<?php

namespace App\Observers;

use App\Models\SocialAccount;
use App\Models\User;
use App\Helpers\NotifHelper;

class SocialAccountObserver
{
    /**
     * Handle the SocialAccount "created" event.
     */
    public function created(SocialAccount $account): void
    {
        // Notify when new social account is connected
        $recipients = User::role(['Director', 'super_admin'])->get();

        NotifHelper::send(
            $recipients,
            'Akun Social Baru Terhubung 📱',
            "Akun {$account->platform}: {$account->username} berhasil terhubung.",
            'heroicon-o-user-plus',
            'info',
            '/admin/social-accounts'
        );
    }

    /**
     * Handle the SocialAccount "updated" event.
     */
    public function updated(SocialAccount $account): void
    {
        // Check if token was refreshed
        if ($account->wasChanged('access_token') && $account->sync_status === 'success') {
            $recipients = User::role(['Director', 'super_admin'])->get();

            NotifHelper::send(
                $recipients,
                'Token Social Diperbarui 🔄',
                "Token {$account->platform}: {$account->username} berhasil diperbarui.",
                'heroicon-o-arrow-path',
                'info',
                '/admin/social-accounts'
            );
        }

        // Check for sync errors
        if ($account->wasChanged('sync_status') && $account->sync_status === 'failed') {
            $recipients = User::role(['Director', 'super_admin'])->get();

            NotifHelper::send(
                $recipients,
                'Sync Social Gagal ⚠️',
                "Sync {$account->platform}: {$account->username} gagal. Error: {$account->last_sync_error}",
                'heroicon-o-exclamation-triangle',
                'warning',
                '/admin/social-accounts'
            );
        }
    }
}
