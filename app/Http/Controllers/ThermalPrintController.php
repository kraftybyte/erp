<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Settings\GeneralSettings;

class ThermalPrintController extends Controller
{
    /**
     * Display the thermal print preview
     */
    public function show($projectId)
    {
        $project = Project::with('deal')->findOrFail((int) $projectId);
        $settings = app(GeneralSettings::class);

        // Generate QR URL - uses custom prefix if set, otherwise defaults to app URL
        $baseUrl = $settings->packing_qr_url_prefix ?? config('app.url');
        $qrUrl = rtrim($baseUrl, '/') . '/admin/projects/' . $project->id;

        // Auto-fill from settings if not set
        $project->sender_name = $project->sender_name ?? $settings->inv_company_name;
        $project->sender_address = $project->sender_address ?? $settings->inv_company_address;
        $project->sender_phone = $project->sender_phone ?? $settings->inv_company_phone;

        // Auto-fill recipient from deal if not set
        $deal = $project->deal;
        $project->recipient_name = $project->recipient_name ?? ($deal->nama_perusahaan ?? '-');
        $project->recipient_address = $project->recipient_address ?? ($deal->alamat ?? '-');
        $project->recipient_phone = $project->recipient_phone ?? ($deal->telephone ?? $deal->nama_pic ?? '-');

        return view('pdf.thermal-print', [
            'project' => $project,
            'qrUrl' => $qrUrl,
            'packingTitle' => $settings->packing_title ?? 'PACKING SLIP',
        ]);
    }

    /**
     * Download as PDF
     */
    public function downloadPdf($projectId)
    {
        $project = Project::with('deal')->findOrFail((int) $projectId);
        $settings = app(GeneralSettings::class);

        // Generate QR URL - uses custom prefix if set, otherwise defaults to app URL
        $baseUrl = $settings->packing_qr_url_prefix ?? config('app.url');
        $qrUrl = rtrim($baseUrl, '/') . '/admin/projects/' . $project->id;

        // Auto-fill from settings if not set
        $project->sender_name = $project->sender_name ?? $settings->inv_company_name;
        $project->sender_address = $project->sender_address ?? $settings->inv_company_address;
        $project->sender_phone = $project->sender_phone ?? $settings->inv_company_phone;

        // Auto-fill recipient from deal if not set
        $deal = $project->deal;
        $project->recipient_name = $project->recipient_name ?? ($deal->nama_perusahaan ?? '-');
        $project->recipient_address = $project->recipient_address ?? ($deal->alamat ?? '-');
        $project->recipient_phone = $project->recipient_phone ?? ($deal->telephone ?? $deal->nama_pic ?? '-');

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.thermal-print', [
            'project' => $project,
            'qrUrl' => $qrUrl,
            'packingTitle' => $settings->packing_title ?? 'PACKING SLIP',
        ]);

        // Set paper size to thermal width (80mm = approximately 3.15 inches)
        $pdf->setPaper([0, 0, 226.77, 1000], 'portrait'); // 80mm width, auto height

        return $pdf->download('packing-slip-' . $project->id . '.pdf');
    }

    /**
     * Quick thermal print (opens print dialog directly)
     */
    public function print($projectId)
    {
        return $this->show($projectId);
    }
}
