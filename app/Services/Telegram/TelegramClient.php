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
    /**
     * @param  \Closure(): ?string|null  $tokenResolver  token bot; default bot pengajuan
     */
    public function __construct(private ?\Closure $tokenResolver = null) {}

    /**
     * Client untuk bot laporan keuangan (token terpisah dari bot pengajuan).
     */
    public static function forFinance(): self
    {
        return new self(fn () => IntegrationSettings::financeBotToken());
    }

    public function token(): ?string
    {
        return $this->tokenResolver
            ? ($this->tokenResolver)()
            : IntegrationSettings::telegramBotToken();
    }

    private function usernameCacheKey(): string
    {
        // Per token, supaya link "Hubungkan Telegram" tidak tertukar antar bot.
        return 'telegram:bot-username:' . md5((string) $this->token());
    }

    public function isConfigured(): bool
    {
        return filled($this->token());
    }

    public function call(string $method, array $params = []): mixed
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $token = $this->token();

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
    /**
     * @param  int|null  $replyTo  pesan yang dikutip, mis. foto struk yang sedang ditanyakan nominalnya
     */
    public function sendMessage(int|string $chatId, string $html, array $buttons = [], ?int $replyTo = null): ?array
    {
        $params = $this->message($html) + ['chat_id' => $chatId];

        if ($buttons) {
            $params['reply_markup'] = ['inline_keyboard' => $buttons];
        }

        if ($replyTo) {
            $params['reply_parameters'] = ['message_id' => $replyTo, 'allow_sending_without_reply' => true];
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

    /**
     * Unduh file yang dikirim user (foto/dokumen). Null bila gagal atau lebih
     * besar dari $maxBytes.
     */
    public function download(string $fileId, int $maxBytes): ?string
    {
        $file = $this->call('getFile', ['file_id' => $fileId]);

        if (! is_array($file) || empty($file['file_path']) || ($file['file_size'] ?? 0) > $maxBytes) {
            return null;
        }

        $token = $this->token();

        try {
            $response = Http::timeout(30)->get("https://api.telegram.org/file/bot{$token}/{$file['file_path']}");
        } catch (ConnectionException $e) {
            Log::warning('Gagal mengunduh file Telegram', ['error' => str_replace($token, '***', $e->getMessage())]);

            return null;
        }

        if (! $response->successful() || strlen($response->body()) > $maxBytes) {
            Log::warning('Gagal mengunduh file Telegram', ['status' => $response->status()]);

            return null;
        }

        return $response->body();
    }

    /**
     * Kirim file (mis. laporan Excel). True bila terkirim.
     */
    public function sendDocument(int|string $chatId, string $path, string $filename, string $caption = ''): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $token = $this->token();

        try {
            $response = Http::timeout(60)
                ->attach('document', file_get_contents($path), $filename)
                ->post("https://api.telegram.org/bot{$token}/sendDocument", [
                    'chat_id'    => $chatId,
                    'caption'    => $caption,
                    'parse_mode' => 'HTML',
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Gagal mengirim file Telegram', ['error' => str_replace($token, '***', $e->getMessage())]);

            return false;
        }

        if (! $response->successful() || ! $response->json('ok')) {
            Log::warning('Telegram API Error', ['method' => 'sendDocument', 'description' => $response->json('description')]);

            return false;
        }

        return true;
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

        return Cache::remember($this->usernameCacheKey(), now()->addDay(), fn () => $this->call('getMe')['username'] ?? null);
    }

    /**
     * Dipanggil saat token bot diganti, supaya link Hubungkan Telegram tidak
     * menunjuk ke bot yang lama.
     */
    public function forgetBotUsername(): void
    {
        Cache::forget($this->usernameCacheKey());
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
