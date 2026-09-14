<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\PaymentAccount;
use Illuminate\Console\Command;

class GenerateInvoiceTransactions extends Command
{
    protected $signature = 'invoices:generate-transactions {--dry-run : Show what would be created without creating}';

    protected $description = 'Generate transactions for existing paid invoices';

    public function handle()
    {
        $dryRun = $this->option('dry-run');

        // Get all paid invoices
        $invoices = Invoice::where('paid', '>', 0)
            ->whereIn('status', ['paid', 'partial'])
            ->get();

        $this->info("Found {$invoices->count()} paid invoices.");

        $created = 0;
        $skipped = 0;

        foreach ($invoices as $invoice) {
            // Check if transaction already exists for this invoice
            $existingTransaction = Transaction::where('category', 'invoice')
                ->where('description', 'like', '%' . $invoice->uuid . '%')
                ->first();

            if ($existingTransaction) {
                $this->line("Skipping Invoice {$invoice->uuid} - transaction already exists (ID: {$existingTransaction->id})");
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $this->line("Would create transaction for Invoice {$invoice->uuid} - Paid: Rp " . number_format($invoice->paid, 0, ',', '.'));
                $created++;
                continue;
            }

            // Create transaction for this invoice
            Transaction::create([
                'payment_account_id' => $invoice->payment_account_id,
                'user_id' => $invoice->user_id ?? 1,
                'type' => 'in',
                'category' => 'invoice',
                'amount' => $invoice->paid,
                'transaction_date' => $invoice->updated_at ?? now(),
                'description' => 'Pembayaran Invoice ' . $invoice->uuid . ' dari ' . ($invoice->nama_perusahaan ?? '-'),
            ]);

            $this->info("Created transaction for Invoice {$invoice->uuid} - Paid: Rp " . number_format($invoice->paid, 0, ',', '.'));

            // Update payment account balance if set
            if ($invoice->payment_account_id) {
                $paymentAccount = PaymentAccount::find($invoice->payment_account_id);
                if ($paymentAccount) {
                    $paymentAccount->update(['current_balance' => $paymentAccount->getBalance()]);
                }
            }

            $created++;
        }

        $this->info("");
        $this->info("Summary:");
        $this->info("- Created: {$created}");
        $this->info("- Skipped: {$skipped}");

        if ($dryRun) {
            $this->warn("(Dry run - no actual changes made)");
        }
    }
}
