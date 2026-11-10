<?php

namespace App\Http\Controllers;

use App\Models\Payslip;
use App\Services\Payroll;
use Illuminate\Http\Request;

/**
 * Unduh slip gaji: Director/Finance/super_admin semua slip; karyawan hanya
 * slipnya sendiri yang sudah final. PDF dibuat saat diminta, tidak disimpan
 * di storage publik.
 */
class PayslipPdfController extends Controller
{
    public function download(Request $request, Payslip $payslip)
    {
        $user = $request->user();

        abort_unless(
            Payroll::canManage($user) || ($payslip->user_id === $user?->id && ! $payslip->isDraft()),
            403
        );

        return app(Payroll::class)->pdf($payslip)->download($payslip->fileName());
    }
}
