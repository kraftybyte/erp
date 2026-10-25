<?php

namespace App\Services\Telegram;

use App\Settings\IntegrationSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pembungkus tipis Telegram Bot API.
 *
 * Semua method mengembalikan null bila gagal dan hanya mencatat log: gagal
 * mengirim pesan Telegram tidak boleh menggagalkan proses ERP yang memanggilnya.
 */
class TelegramClient
{
    private const USERNAME_CACHE = 'telegram:bot-username';

    public function isConfigured(): bool
    {
        return filled(IntegrationSettings::telegramBotToken());
    }

    public function call(string $method, array $params = []): mixed
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $token = IntegrationSettings::telegramBotToken();

        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$token}/{$method}", $params);
        } catch (ConnectionException $e) {
            // Pesan error Guzzle menyertakan URL, dan token bot ada di URL itu.
            Log::warning('Telegram API tidak bisa dihubungi', [
                'method' => $method,
                'error'  => str_replace($token, '***', $e->getMessage()),
            ]);

            return null;
        }

        if (! $response->successful() || ! $response->json('ok')) {
            $description = (string) $response->json('description');

            // Muncul saat tombol ditekan tapi isi pesannya tidak berubah; bukan kesalahan.
            if (str_contains($description, 'message is not modified')) {
                return true;
            }

            Log::warning('Telegram API Error', [
                'method'      => $method,
                'status'      => $response->status(),
                'description' => $description,
            ]);

            return null;
        }

        return $response->json('result');
    }

    /**
     * @param  array<int, array<int, array{text: string, callback_data?: string, url?: string}>>  $buttons  baris-baris tombol inline
     */
    public function sendMessage(int|string $chatId, string $html, array $buttons = []): ?array
    {
        $params = $this->message($html) + ['chat_id' => $chatId];

        if ($buttons) {
            $params['reply_markup'] = ['inline_keyboard' => $buttons];
        }

        $result = $this->call('sendMessage', $params);

        return is_array($result) ? $result : null;
    }

    public function editMessage(int|string $chatId, int $messageId, string $html, array $buttons = []): bool
    {
        // Keyboard selalu dikirim, termasuk yang kosong, supaya tombol lama hilang.
        return $this->call('editMessageText', $this->message($html) + [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'reply_markup' => ['inline_keyboard' => $buttons],
        ]) !== null;
    }

    public function removeButtons(int|string $chatId, int $messageId): void
    {
        $this->call('editMessageReplyMarkup', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'reply_markup' => ['inline_keyboard' => []],
        ]);
    }

    public function answerCallback(string $callbackId, ?string $text = null): void
    {
        $this->call('answerCallbackQuery', array_filter([
            'callback_query_id' => $callbackId,
            'text'              => $text,
        ]));
    }

    public function typing(int|string $chatId): void
    {
        $this->call('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
    }

    public function botUsername(): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        return Cache::remember(self::USERNAME_CACHE, now()->addDay(), fn () => $this->call('getMe')['username'] ?? null);
    }

    /**
     * Dipanggil saat token bot diganti, supaya link Hubungkan Telegram tidak
     * menunjuk ke bot yang lama.
     */
    public function forgetBotUsername(): void
    {
        Cache::forget(self::USERNAME_CACHE);
    }

    private function message(string $html): array
    {
        return [
            'text'                 => $html,
            'parse_mode'           => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ];
    }
}
