<?php

namespace App\Observers;

use App\Models\Transaction;
use App\Models\PaymentAccount;
use App\Models\Invoice;

class InvoiceObserver
{
    /**
     * Handle the Invoice "updated" event.
     */
    public function updated(Invoice $invoice): void
    {
        // Only process if paid changed and has positive amount
        if (!$invoice->isDirty('paid') || $invoice->paid <= 0) {
            return;
        }

        $originalPaid = $invoice->getOriginal('paid') ?? 0;
        $paidChange = $invoice->paid - $originalPaid;

        // Only create transaction if there's a positive change
        if ($paidChange <= 0) {
            return;
        }

        // Only create transaction if payment_account_id is set [v2.2]
        $accountId = $invoice->payment_account_id;
        if (empty($accountId)) {
            return;
        }

        // Verify PaymentAccount exists [v2.2]
        $account = PaymentAccount::find($accountId);
        if (!$account) {
            return;
        }

        // Create transaction record [v2.2]
        Transaction::create([
            'payment_account_id' => $accountId,
            'user_id' => auth()->id() ?? 1,
            'type' => 'in',
            'amount' => $paidChange,
            'description' => "Payment Invoice: {$invoice->uuid} - {$invoice->nama_perusahaan}",
            'reference_type' => 'invoice',
            'reference_id' => $invoice->getKey(),
            'transaction_date' => now()->toDateString(),
            'category' => 'operational',
        ]);

        // Sync PaymentAccount balance after transaction [v2.2]
        $account->syncBalanceFromTransactions();
    }
}
