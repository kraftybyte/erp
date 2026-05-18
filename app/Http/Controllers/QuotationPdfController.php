<?php

namespace App\Http\Controllers;

use App\Models\Quotation;
use Barryvdh\DomPDF\Facade\Pdf;

class QuotationPdfController extends Controller
{
    public function show(Quotation $quotation)
    {
        // Pastikan relasi items sudah di-load
        $quotation->load('items');

        // Render tampilan HTML untuk ditampilkan di browser
        return view('pdf.quotation', [
            'quotation' => $quotation // Kirim variable ke view
        ]);
    }

    public function download(Quotation $quotation)
    {
        // Pastikan relasi items sudah di-load
        $quotation->load('items');

        // Generate PDF dan langsung didownload
        $pdf = Pdf::loadView('pdf.quotation', [
            'quotation' => $quotation
        ]);

        return $pdf->download("Quotation-" . str_replace(['#', '/'], ['','-'], $quotation->uuid) . ".pdf");
    }
}
