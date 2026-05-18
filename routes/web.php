<?php

use Illuminate\Support\Facades\Route;
use Filament\Facades\Filament;
use App\Http\Controllers\QuotationPdfController;
use App\Http\Controllers\WablasController;
use App\Http\Controllers\InvoicePdfController;
use App\Http\Controllers\Auth\InstagramConnectController;
use App\Http\Controllers\PushNotificationController;
use App\Http\Controllers\ContractPdfController;
use Filament\Notifications\Notification;


/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/
Route::get('/quotations/{quotation}', [QuotationPdfController::class, 'show'])->name('quotation.show');
Route::get('/quotations/{quotation}/download', [QuotationPdfController::class, 'download'])->name('quotation.download');
Route::middleware('auth')->group(function () {
    Route::post('/send-whatsapp', [WablasController::class, 'sendMessage']);
});
// Invoice PDF Download
Route::get('/invoices/{invoice}/download', [InvoicePdfController::class, 'download'])->name('invoices.download');

// Contract Routes
Route::get('/contracts/{contract}/preview', [ContractPdfController::class, 'preview'])
    ->name('filament.admin.resources.contracts.preview')
    ->middleware('auth');
Route::get('/contracts/{contract}/download-pdf', [ContractPdfController::class, 'downloadPdf'])
    ->name('filament.admin.resources.contracts.download-pdf')
    ->middleware('auth');

// Timeline Update API
Route::post('/timeline/update', function (\Illuminate\Http\Request $request) {
    $timeline = \App\Models\ProjectTimeline::find($request->id);

    if (!$timeline) {
        return response()->json(['success' => false]);
    }

    $timeline->update([
        'start_date' => $request->start,
        'end_date'   => $request->end,
        'updated_by' => auth()->id(),
    ]);

    $user = auth()->user();

    return response()->json([
        'success' => true,
        'message' => 'Perubahan terakhir: '
            . $user->name
            . ' – '
            . now()->format('d F Y, H:i')
    ]);
})->middleware('auth')->name('timeline.update');

// PWA Routes
Route::get('/manifest.json', function () {
    return response()->json([
        'name' => 'ERP Krafty',
        'short_name' => 'ERP',
        'start_url' => '/admin',
        'display' => 'standalone',
        'background_color' => '#ffffff',
        'theme_color' => '#6366f1',
        'orientation' => 'portrait-primary',
        'icons' => [
            [
                'src' => '/icons/icon-512x512.svg',
                'sizes' => '512x512',
                'type' => 'image/svg+xml',
                'purpose' => 'any maskable'
            ]
        ]
    ]);
});

// Instagram OAuth Authentication
Route::prefix('auth')->group(function () {
    Route::get('/instagram', [InstagramConnectController::class, 'redirect'])->name('auth.instagram.redirect');
    Route::get('/instagram/callback', [InstagramConnectController::class, 'callback'])->name('auth.instagram.callback');

    // Business account flow (via Meta)
    Route::get('/instagram-business', [InstagramConnectController::class, 'redirect'])->name('auth.instagram.business');
    Route::get('/instagram-business/callback', [InstagramConnectController::class, 'businessCallback']);
});

// Connected Account API (for AJAX actions)
Route::prefix('api/connected-accounts')->middleware('auth')->group(function () {
    Route::post('/{account}/sync', [InstagramConnectController::class, 'sync'])->name('api.connected-accounts.sync');
    Route::post('/{account}/disconnect', [InstagramConnectController::class, 'disconnect'])->name('api.connected-accounts.disconnect');
    Route::post('/{account}/refresh-token', [InstagramConnectController::class, 'refreshToken'])->name('api.connected-accounts.refresh-token');
});

Route::get('test', function () {
    // Get the user with ID 1
    $recipient = \App\Models\User::find(1);  // Find user with ID = 1

    // Check if the user exists
    if ($recipient) {
        // Create and send the notification
        Notification::make()
            ->title('Sending test notification')
            ->sendToDatabase($recipient)  // Send the notification to the database
            ->broadcast($recipient);      // Optionally broadcast the notification to the recipient

        // Output to confirm the notification was sent
        dd('Notification sent to user with ID 1');
    } else {
        // Handle case where user is not found
        dd('User with ID 1 not found');
    }
});


Route::get('/admin/{any}', function () {
    return redirect()->route('filament.admin.auth.login');
})->where('any', '.*')->middleware('guest');


Route::get('/', function () {
    return redirect(Filament::getUrl());
});