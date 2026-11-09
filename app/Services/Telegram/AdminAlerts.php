<?php

namespace App\Services\Telegram;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Peringatan ke super_admin lewat Telegram: error di bot, Gemini yang gagal
 * (saldo habis, batas pemakaian, key salah). Pesan yang sama tidak dikirim
 * ulang selama jedanya, supaya error berulang tidak membanjiri chat.
 */
class AdminAlerts
{
    public static function once(string $key, string $html, int $minutes = 360): void
    {
        if (! Cache::add("admin-alert:{$key}", true, now()->addMinutes($minutes))) {
            return;
        }

        try {
            $alerts = app(ManagementAlerts::class);

            User::role('super_admin')->where('is_active', true)->whereNotNull('telegram_chat_id')->get()
                ->each(fn (User $user) => $alerts->deliver($user, $html, [], ''));
        } catch (Throwable) {
            // Peringatan tidak boleh ikut membuat request gagal.
        }
    }

    public static function exception(Throwable $e, string $where): void
    {
        self::once(
            'exception-' . md5(get_class($e) . $e->getMessage()),
            "🚨 <b>Error di {$where}</b>\n" . e(class_basename($e)) . ': ' . e(Str::limit($e->getMessage(), 300))
                . "\n" . e(basename($e->getFile())) . ':' . $e->getLine() . "\n\n<i>Detail lengkap di ERP → Exceptions.</i>",
            30
        );
    }
}
