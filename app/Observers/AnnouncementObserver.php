<?php

namespace App\Observers;

use App\Models\Announcement;
use App\Models\User;
use App\Helpers\NotifHelper;

class AnnouncementObserver
{
    public function created(Announcement $announcement): void
    {
        $pembuat  = $announcement->user;
        $namaUser = $pembuat ? trim("{$pembuat->firstname} {$pembuat->lastname}") : 'Admin';
        $content  = strip_tags($announcement->content);
        $preview  = mb_strlen($content) > 80 ? mb_substr($content, 0, 80) . '...' : $content;
        $url      = '/admin/';

        $allUsers = User::where('id', '!=', $announcement->user_id)->get();

        NotifHelper::send(
            $allUsers,
            '📢 Pengumuman Baru',
            "{$namaUser}: {$preview}",
            'heroicon-o-megaphone',
            'warning',
            $url
        );
    }
}
