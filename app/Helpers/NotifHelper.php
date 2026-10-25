<?php

namespace App\Helpers;

use App\Services\Telegram\TelegramNotifier;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class NotifHelper
{
    public static function send(
        $user,
        string $title,
        string $body = '',
        string $icon = 'heroicon-o-bell',
        string $color = 'info',
        string $url = '',
        // Ikut kirim ke Telegram penerima yang sudah menghubungkan akunnya.
        bool $telegram = false
    ): void {
        if (!$user) return;

        $users = is_iterable($user) ? $user : [$user];

        foreach ($users as $u) {
            if (!$u) continue;
            DB::table('notifications')->insert([
                'id'              => Str::uuid(),
                'type'            => 'Filament\Notifications\DatabaseNotification',
                'notifiable_type' => 'App\Models\User',
                'notifiable_id'   => $u->id,
                'data'            => json_encode([
                    'title'     => $title,
                    'body'      => $body,
                    'icon'      => $icon,
                    'iconColor' => $color,
                    'color'     => $color,
                    'actions'   => $url ? [['label' => 'Lihat', 'url' => $url]] : [],
                    'duration'  => 'persistent',
                    'format'    => 'filament',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($telegram) {
                app(TelegramNotifier::class)->send($u, $title, $body, $url);
            }
        }
    }
}
