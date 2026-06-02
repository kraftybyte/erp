<?php

namespace App\Observers;

use App\Models\Transaction;
use App\Models\PaymentAccount;
use TomatoPHP\FilamentInvoices\Models\Invoice;
use Illuminate\Support\Facades\DB;

class InvoiceObserver
{
    /**
     * Handle the Invoice "updated" event.
     */
    public function updated(Invoice $invoice): void
    {
        // Check if paid was changed
        if ($invoice->isDirty('paid') && $invoice->paid > 0) {
            $originalPaid = $invoice->getOriginal('paid') ?? 0;
            $paidChange = $invoice->paid - $originalPaid;

            if ($paidChange > 0) {
                // Get payment account from invoice or default
                $accountId = $invoice->payment_account_id ?? null;

                // Create transaction record
                Transaction::create([
                    'payment_account_id' => $accountId,
                    'user_id' => auth()->id() ?? 1,
                    'type' => 'in',
                    'amount' => $paidChange,
                    'description' => "Payment Invoice: {$invoice->uuid} - {$invoice->nama_perusahaan}",
                    'reference_type' => 'invoice',
                    'reference_id' => $invoice->id,
                    'transaction_date' => now()->toDateString(),
                    'category' => 'operational',
                ]);
            }
        }
    }
}
