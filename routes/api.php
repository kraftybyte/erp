<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WablasController;
use App\Http\Controllers\PushNotificationController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Routing\Middleware\ThrottleRequests;


/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/send-message', [WablasController::class, 'sendMessage']);

    // Push Notifications
    Route::get('/push/vapid-public-key', [PushNotificationController::class, 'getVapidPublicKey']);
    Route::post('/push/subscribe', [PushNotificationController::class, 'subscribe']);
    Route::post('/push/unsubscribe', [PushNotificationController::class, 'unsubscribe']);
    Route::post('/push/test', [PushNotificationController::class, 'test']);
    Route::post('/push/broadcast', [PushNotificationController::class, 'broadcast']);
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Webhook bot Telegram. Diautentikasi lewat secret token di header, bukan sesi.
// Throttle api dilepas: semua update datang dari sedikit IP milik Telegram,
// sehingga batas 60/menit per IP bisa menahan pesan dari seluruh tim.
Route::post('/telegram/webhook', TelegramWebhookController::class)
    ->withoutMiddleware(ThrottleRequests::class)
    ->name('telegram.webhook');
