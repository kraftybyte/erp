<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Filament\Facades\Filament;
use App\Http\Controllers\QuotationPdfController;
use App\Http\Controllers\InvoicePdfController;
use App\Http\Controllers\PayslipPdfController;
use App\Http\Controllers\ContractPdfController;
use App\Http\Controllers\FakturController;
use App\Http\Controllers\AssetDepreciationController;
use App\Services\ItemImportTemplate;


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
Route::get('/payslips/{payslip}/pdf', [PayslipPdfController::class, 'download'])
    ->name('payslip.pdf')
    ->middleware(['auth', 'throttle:pdf-downloads']);

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

// Template Excel untuk Import karyawan (HR → Employee); izin Shield create/update user.
Route::get('/import-template/karyawan', function (\App\Services\EmployeeImport $import) {
    abort_unless(auth()->user()->can('create', \App\Models\User::class) || auth()->user()->can('update_user'), 403);

    return response()->download($import->template(), 'Template Import Karyawan.xlsx')->deleteFileAfterSend();
})->middleware('auth')->name('import-template.employees');

// Template Excel untuk tombol "Import dari Excel" di quotation & invoice.
Route::get('/import-template/{type}', function (ItemImportTemplate $template, string $type) {
    $path = tempnam(sys_get_temp_dir(), 'template-');
    $template->save($path, $type);

    return response()->download($path, ItemImportTemplate::filename($type))->deleteFileAfterSend();
})->whereIn('type', array_keys(ItemImportTemplate::TYPES))->middleware('auth')->name('import-template.items');

// Halaman Faktur: rekap Excel dan ZIP faktur masukan satu bulan.
Route::middleware(['auth', 'throttle:pdf-downloads'])->group(function () {
    Route::get('/faktur/{bulan}/excel', [FakturController::class, 'excel'])
        ->where('bulan', '\d{4}-(0[1-9]|1[0-2])')->name('faktur.excel');
    Route::get('/faktur/{bulan}/zip', [FakturController::class, 'zip'])
        ->where('bulan', '\d{4}-(0[1-9]|1[0-2])')->name('faktur.zip');
});

// Daftar penyusutan aset satu tahun buku (Excel).
Route::get('/aset/penyusutan/{tahun}', AssetDepreciationController::class)
    ->whereNumber('tahun')->middleware(['auth', 'throttle:pdf-downloads'])->name('asset.depreciation');

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

Route::get('/', function () {
    return redirect(Filament::getUrl());
});