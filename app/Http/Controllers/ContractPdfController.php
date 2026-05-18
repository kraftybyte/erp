<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Services\ContractTemplateService;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;

class ContractPdfController extends Controller
{
    public function preview(Contract $contract)
    {
        $service = app(ContractTemplateService::class);
        $html = $service->generate($contract);

        return response($html)->header('Content-Type', 'text/html');
    }

    public function downloadPdf(Contract $contract)
    {
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
}
