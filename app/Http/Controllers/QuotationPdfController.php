<?php

namespace App\Http\Controllers;

use App\Models\Quotation;
use App\Settings\GeneralSettings;
use Barryvdh\DomPDF\Facade\Pdf;

class QuotationPdfController extends Controller
{
    public function show(Quotation $quotation)
    {
        $quotation->load('items');

        $settings = app(GeneralSettings::class);

        return view('pdf.quotation-dynamic', [
            'quotation' => $quotation,
            'settings' => $settings->getQuotationSettings(),
            'themeColor' => $settings->quot_theme_color,
        ]);
    }

    public function download(Quotation $quotation)
    {
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