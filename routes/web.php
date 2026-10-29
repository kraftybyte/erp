<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Filament\Facades\Filament;
use App\Http\Controllers\QuotationPdfController;
use App\Http\Controllers\WablasController;
use App\Http\Controllers\InvoicePdfController;
use App\Http\Controllers\Auth\InstagramConnectController;
use App\Http\Controllers\PushNotificationController;
use App\Http\Controllers\ContractPdfController;
use App\Http\Controllers\ThermalPrintController;
use App\Services\ItemImportTemplate;
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
// Quotation PDF Routes (protected with auth + rate limiting)
Route::middleware(['auth', 'throttle:pdf-downloads'])->group(function () {
    Route::get('/quotations/{quotation}', [QuotationPdfController::class, 'show'])->name('quotation.show');
    Route::get('/quotations/{quotation}/download', [QuotationPdfController::class, 'download'])->name('quotation.download');
});

// Invoice PDF Download (protected with auth + rate limiting)
Route::get('/invoices/{invoice}/download', [InvoicePdfController::class, 'download'])
    ->name('invoices.download')
    ->middleware(['auth', 'throttle:pdf-downloads']);

// Contract Routes (with rate limiting)
Route::middleware(['auth', 'throttle:pdf-downloads'])->group(function () {
    Route::get('/contracts/{contract}/preview', [ContractPdfController::class, 'preview'])
        ->name('filament.admin.resources.contracts.preview');
    Route::get('/contracts/{contract}/download-pdf', [ContractPdfController::class, 'downloadPdf'])
        ->name('filament.admin.resources.contracts.download-pdf');
});

// Timeline Update API (with rate limiting)
Route::post('/timeline/update', function (\Illuminate\Http\Request $request) {
    $timeline = \App\Models\ProjectTimeline::find($request->id);

    if (!$timeline) {
        return response()->json(['success' => false]);
    }

    /** @var \App\Models\User|null $user */
    $user = Auth::user();
    $userName = $user?->name ?? 'Unknown';

    $timeline->update([
        'start_date' => $request->start,
        'end_date'   => $request->end,
        'updated_by' => $user?->id,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Perubahan terakhir: '
            . $userName
            . ' – '
            . now()->format('d F Y, H:i')
    ]);
})->middleware(['auth', 'throttle:timeline'])->name('timeline.update');

// Thermal Print Routes (protected with auth)
Route::middleware(['auth'])->group(function () {
    Route::get('/thermal/{projectId}/print', [ThermalPrintController::class, 'print'])
        ->name('thermal.print');
    Route::get('/thermal/{projectId}/pdf', [ThermalPrintController::class, 'downloadPdf'])
        ->name('thermal.pdf');
});

// Template Excel untuk tombol "Import dari Excel" di quotation & invoice.
Route::get('/import-template/{type}', function (ItemImportTemplate $template, string $type) {
    $path = tempnam(sys_get_temp_dir(), 'template-');
    $template->save($path, $type);

    return response()->download($path, ItemImportTemplate::filename($type))->deleteFileAfterSend();
})->whereIn('type', array_keys(ItemImportTemplate::TYPES))->middleware('auth')->name('import-template.items');

// WhatsApp Message Route
Route::post('/send-whatsapp', [WablasController::class, 'sendMessage'])->middleware('auth');

// PWA Routes
Route::get('/manifest.json', function () {
    return response()->json([
        'name' => 'ERP Creative',
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


Route::get('/', function () {
    return redirect(Filament::getUrl());
});