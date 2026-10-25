<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramBot;
use App\Settings\IntegrationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramBot $bot): JsonResponse
    {
        $secret = (string) IntegrationSettings::telegramWebhookSecret();

        // Tanpa secret yang cocok siapa pun bisa memalsukan pesan dari chat
        // yang sudah terhubung dan membuat pengajuan atas nama user lain.
        abort_if(
            $secret === '' || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')),
            403
        );

        try {
            $bot->handle($request->json()->all());
        } catch (Throwable $e) {
            // Tetap balas 200: bila gagal, Telegram mengulang update yang sama
            // terus-menerus dan antrean pesan berikutnya ikut tertahan.
            report($e);
        }

        return response()->json(['ok' => true]);
    }
}
