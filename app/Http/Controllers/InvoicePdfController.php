<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\InvoicePdf;
use Illuminate\Http\Request;

class InvoicePdfController extends Controller
{
    public function download(Request $request, Invoice $invoice)
    {
        if (! $request->user()?->can('view', $invoice)) {
            abort(403, 'Unauthorized access to invoice');
        }

        $invoice->load('invoicesItems');

        return InvoicePdf::make($invoice)->download("Invoice-" . str_replace(['#', '/'], ['','-'], $invoice->uuid) . ".pdf");
    }
}
