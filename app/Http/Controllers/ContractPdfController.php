<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Services\ContractTemplateService;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ContractPdfController extends Controller
{
    public function preview(Request $request, Contract $contract)
    {
        if (!$this->userCanAccess($request->user(), $contract)) {
            abort(403, 'Unauthorized access to contract');
        }

        // For uploaded file type, show message
        if ($contract->type === 'file' && $contract->file_path) {
            return response('Contract file uploaded. Please download from the contract management page.', 200)
                ->header('Content-Type', 'text/plain');
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

        // For uploaded file type, serve the actual file [v2.2]
        if ($contract->type === 'file' && $contract->file_path) {
            $filePath = storage_path('app/' . $contract->file_path);
            if (file_exists($filePath)) {
                $filename = 'kontrak-' . $contract->id . '-' . Str::slug($contract->deal?->nama_perusahaan ?? 'client') . '.pdf';
                return response()->download($filePath, $filename);
            }
        }

        try {
            $service = app(ContractTemplateService::class);
            $html = $service->generate($contract);

            $pdf = Pdf::loadHTML($html);
            $pdf->setPaper('A4', 'portrait');

            $output = $pdf->output();
            if (empty($output)) {
                throw new \Exception('PDF generation produced empty output');
            }

            // Sanitize filename [v2.2]
            $filename = 'kontrak-' . $contract->id . '-' . Str::slug($contract->deal?->nama_perusahaan ?? 'client') . '.pdf';

            return response()->streamDownload(
                fn () => print($output),
                $filename,
                ['Content-Type' => 'application/pdf']
            );
        } catch (\Exception $e) {
            Log::error('Contract PDF generation failed: ' . $e->getMessage(), [
                'contract_id' => $contract->id,
                'user_id' => $request->user()?->id,
                'trace' => $e->getTraceAsString(),
            ]);
            abort(500, 'Gagal membuat PDF kontrak: ' . $e->getMessage());
        }
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
