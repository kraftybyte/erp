<?php

namespace App\Http\Controllers;

use TomatoPHP\FilamentInvoices\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;

class InvoicePdfController extends Controller
{
    public function download(Invoice $invoice)
{
    // Pastikan relasi invoicesItems sudah di-load
    $invoice->load('invoicesItems');

    // Generate PDF
    $pdf = Pdf::loadView('pdf.invoice', [
        'invoice' => $invoice
    ]);

    return $pdf->download("Invoice-" . str_replace(['#', '/'], ['','-'], $invoice->uuid) . ".pdf");
}


}
