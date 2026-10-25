<?php

namespace App\Services\Telegram;

use App\Models\User;

/**
 * Meneruskan notifikasi ERP ke Telegram user yang sudah menghubungkan akunnya.
 */
class TelegramNotifier
{
    public function __construct(private TelegramClient $telegram) {}

    public function send(User $user, string $title, string $body = '', string $url = ''): void
    {
        if (! $user->telegram_chat_id || ! $this->telegram->isConfigured()) {
            return;
        }

        $chatId = $user->telegram_chat_id;
        $html   = '<b>' . e($title) . '</b>' . ($body !== '' ? "\n" . e($body) : '');
        $link   = $url !== '' ? url($url) : '';

        // Telegram menolak tombol URL yang bukan https (mis. saat APP_URL lokal).
        $buttons = str_starts_with($link, 'https://') ? [[['text' => 'Lihat di ERP', 'url' => $link]]] : [];

        $deliver = fn () => $this->telegram->sendMessage($chatId, $html, $buttons);

        // Di request web, kirim setelah respons selesai supaya tombol Setujui/Tolak
        // di ERP tidak ikut menunggu Telegram.
        app()->runningInConsole() ? $deliver() : app()->terminating($deliver);
    }
}
