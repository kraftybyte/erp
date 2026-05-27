<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Services\ContractTemplateService;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;

class ContractPdfController extends Controller
{
    public function preview(Request $request, Contract $contract)
    {
        if (!$this->userCanAccess($request->user(), $contract)) {
            abort(403, 'Unauthorized access to contract');
        }

        $service = app(ContractTemplateService::class);
        $html = $service->generate($contract);

        return response($html)->header('Content-Type', 'text/html');
    }

    public function downloadPdf(Request $request, Contract $contract)
    {
        if (!$this->userCanAccess($request->user(), $contract)) {
            abort(403, 'Unauthorized access to contract');
        }

        $service = app(ContractTemplateService::class);
        $html = $service->generate($contract);

        $pdf = Pdf::loadHTML($html);
        $pdf->setPaper('A4', 'portrait');

        return response()->streamDownload(
            fn () => print($pdf->output()),
            'kontrak-' . $contract->id . '-' . $contract->deal?->nama_perusahaan . '.pdf',
            ['Content-Type' => 'application/pdf']
        );
    }

    private function userCanAccess($user, Contract $contract): bool
    {
        if (!$user) {
            return false;
        }

        if ($user->hasRole(['super_admin', 'Director', 'Finance'])) {
            return true;
        }

        // Check if user owns the deal associated with this contract
        if ($contract->deal && $contract->deal->lead_id) {
            $lead = $contract->deal->lead;
            if ($lead && $lead->user_id === $user->id) {
                return true;
            }
        }

        return false;
    }
}
