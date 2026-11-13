<?php

namespace App\Http\Controllers;

use App\Models\Quotation;
use App\Settings\GeneralSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class QuotationPdfController extends Controller
{
    public function show(Request $request, Quotation $quotation)
    {
        if (! $request->user()?->can('view', $quotation)) {
            abort(403, 'Unauthorized access to quotation');
        }

        $quotation->load('items');

        $settings = app(GeneralSettings::class);

        return view('pdf.quotation-dynamic', [
            'quotation' => $quotation,
            'settings' => $settings->getQuotationSettings(),
            'themeColor' => $settings->quot_theme_color,
        ]);
    }

    public function download(Request $request, Quotation $quotation)
    {
        if (! $request->user()?->can('view', $quotation)) {
            abort(403, 'Unauthorized access to quotation');
        }

        $quotation->load('items');

        $settings = app(GeneralSettings::class);

        $pdf = Pdf::loadView('pdf.quotation-dynamic', [
            'quotation' => $quotation,
            'settings' => $settings->getQuotationSettings(),
            'themeColor' => $settings->quot_theme_color,
        ]);

        return $pdf->download("Quotation-" . str_replace(['#', '/'], ['','-'], $quotation->uuid) . ".pdf");
    }
}