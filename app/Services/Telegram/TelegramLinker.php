<?php

namespace App\Services\Telegram;

use App\Helpers\NotifHelper;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Menghubungkan akun Telegram ke user ERP lewat link t.me sekali pakai.
 *
 * Chat ID Telegram dipakai sebagai identitas di bot, jadi satu-satunya jalan
 * mengisinya adalah token yang dibuat dari sesi ERP yang sudah login.
 */
class TelegramLinker
{
    public const TTL_MINUTES = 15;

    public function __construct(private TelegramClient $telegram) {}

    /**
     * Null bila bot belum dikonfigurasi atau token bot salah.
     */
    public function linkFor(User $user): ?string
    {
        $username = $this->telegram->botUsername();

        if (! $username) {
            return null;
        }

        $token = Str::random(40);
        Cache::put($this->key($token), $user->id, now()->addMinutes(self::TTL_MINUTES));

        return "https://t.me/{$username}?start={$token}";
    }

    public function consume(string $token, int|string $chatId): ?User
    {
        $userId = Cache::pull($this->key($token));
        $user   = $userId ? User::find($userId) : null;

        if (! $user) {
            return null;
        }

        DB::transaction(function () use ($user, $chatId) {
            // Akun Telegram yang sama berpindah ke user ini, bukan dobel.
            User::where('telegram_chat_id', (string) $chatId)
                ->whereKeyNot($user->id)
                ->update(['telegram_chat_id' => null, 'telegram_linked_at' => null]);

            $user->forceFill([
                'telegram_chat_id'   => (string) $chatId,
                'telegram_linked_at' => now(),
            ])->save();
        });

        NotifHelper::send(
            $user,
            'Telegram Terhubung 🔗',
            'Akun Telegram berhasil dihubungkan ke ERP. Bila ini bukan kamu, putuskan dari halaman Profil.',
            'heroicon-o-chat-bubble-left-right',
            'success',
            '/admin/my-profile'
        );

        return $user;
    }

    public function unlink(User $user): void
    {
        // refresh() dulu: instance user dari sesi bisa dimuat sebelum akun
        // terhubung, dan tanpa ini save() menganggap tidak ada yang berubah.
        $user->refresh()->forceFill(['telegram_chat_id' => null, 'telegram_linked_at' => null])->save();
    }

    private function key(string $token): string
    {
        return "telegram:link:{$token}";
    }
}
