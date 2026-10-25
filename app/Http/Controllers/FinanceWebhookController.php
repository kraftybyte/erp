<?php

namespace App\Http\Controllers;

use App\Services\Telegram\FinanceBot;
use App\Settings\IntegrationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Webhook bot laporan keuangan. Secret-nya terpisah dari bot pengajuan,
 * jadi request untuk bot yang satu tidak bisa masuk ke yang lain.
 */
class FinanceWebhookController extends Controller
{
    public function __invoke(Request $request, FinanceBot $bot): JsonResponse
    {
        $secret = (string) IntegrationSettings::financeWebhookSecret();

        abort_if(
            $secret === '' || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')),
            403
        );

        try {
            $bot->handle($request->json()->all());
        } catch (Throwable $e) {
            // Tetap 200 supaya Telegram tidak mengulang update yang sama terus-menerus.
            report($e);
        }

        return response()->json(['ok' => true]);
    }
}
