<?php

namespace App\Http\Controllers;

use App\Settings\GeneralSettings;
use TomatoPHP\FilamentInvoices\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class InvoicePdfController extends Controller
{
    public function download(Request $request, Invoice $invoice)
    {
        if (!$this->userCanAccess($request->user(), $invoice)) {
            abort(403, 'Unauthorized access to invoice');
        }

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

    private function userCanAccess($user, Invoice $invoice): bool
    {
        if (!$user) {
            return false;
        }

        if ($user->hasRole(['super_admin', 'Director', 'Finance'])) {
            return true;
        }

        if ($invoice->user_id === $user->id) {
            return true;
        }

        // Check if user owns the deal associated with this invoice
        if ($invoice->deals && $invoice->deals->lead_id) {
            $lead = $invoice->deals->lead;
            if ($lead && $lead->user_id === $user->id) {
                return true;
            }
        }

        return false;
    }
}
