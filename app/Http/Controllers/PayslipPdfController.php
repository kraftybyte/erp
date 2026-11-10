<?php

namespace App\Http\Controllers;

use App\Models\Payslip;
use App\Services\Payroll;
use Illuminate\Http\Request;

/**
 * Unduh slip gaji menurut PayslipPolicy::view (izin Shield): pengelola gaji
 * semua slip, karyawan hanya slipnya sendiri yang sudah final. PDF dibuat saat
 * diminta, tidak disimpan di storage publik.
 */
class PayslipPdfController extends Controller
{
    public function download(Request $request, Payslip $payslip)
    {
        abort_unless($request->user()?->can('view', $payslip), 403);

        return app(Payroll::class)->pdf($payslip)->download($payslip->fileName());
    }
}
