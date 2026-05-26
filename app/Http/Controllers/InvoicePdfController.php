<?php

namespace App\Http\Controllers;

use App\Settings\GeneralSettings;
use TomatoPHP\FilamentInvoices\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;

class InvoicePdfController extends Controller
{
    public function download(Invoice $invoice)
    {
        // Ensure relationship is loaded
        $invoice->load('invoicesItems');

        // Load settings
        $settings = app(GeneralSettings::class);

        // Generate PDF with dynamic template
        $pdf = Pdf::loadView('pdf.invoice-dynamic', [
            'invoice' => $invoice,
            'settings' => $settings->getInvoiceSettings(),
            'themeColor' => $settings->inv_theme_color,
        ]);

        return $pdf->download("Invoice-" . str_replace(['#', '/'], ['','-'], $invoice->uuid) . ".pdf");
    }
}
