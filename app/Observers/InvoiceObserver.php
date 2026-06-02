<?php

namespace App\Observers;

use App\Models\Transaction;
use App\Models\PaymentAccount;
use App\Models\Invoice;
use Illuminate\Support\Facades\Log;

class InvoiceObserver
{
    /**
     * Handle the Invoice "updated" event.
     */
    public function updated(Invoice $invoice): void
    {
        Log::info('InvoiceObserver::updated triggered', [
            'invoice_id' => $invoice->getKey(),
            'invoice_uuid' => $invoice->uuid,
            'isDirty_paid' => $invoice->isDirty('paid'),
            'paid' => $invoice->paid,
            'payment_account_id' => $invoice->payment_account_id,
        ]);

        // Only process if paid changed and has positive amount
        if (!$invoice->isDirty('paid') || $invoice->paid <= 0) {
            Log::info('InvoiceObserver: Skipped - not dirty paid or paid <= 0');
            return;
        }

        $originalPaid = $invoice->getOriginal('paid') ?? 0;
        $paidChange = $invoice->paid - $originalPaid;

        // Only create transaction if there's a positive change
        if ($paidChange <= 0) {
            Log::info('InvoiceObserver: Skipped - no positive change');
            return;
        }

        // Only create transaction if payment_account_id is set [v2.2]
        $accountId = $invoice->payment_account_id;
        if (empty($accountId)) {
            Log::info('InvoiceObserver: Skipped - no payment_account_id');
            return;
        }

        // Verify PaymentAccount exists [v2.2]
        $account = PaymentAccount::find($accountId);
        if (!$account) {
            Log::info('InvoiceObserver: Skipped - account not found');
            return;
        }

        Log::info('InvoiceObserver: Creating transaction', [
            'account_id' => $accountId,
            'amount' => $paidChange,
        ]);

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

        Log::info('InvoiceObserver: Complete', [
            'account_balance' => $account->current_balance,
        ]);
    }
}
