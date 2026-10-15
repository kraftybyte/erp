<?php

namespace App\Console\Commands;

use App\Models\PaymentAccount;
use App\Models\Transaction;
use App\Models\Invoice;
use TomatoPHP\FilamentInvoices\Models\InvoiceMeta;
use Illuminate\Console\Command;

class SyncInvoiceTransactions extends Command
{
    protected $signature = 'sync:invoice-transactions
                            {--account= : Specific account ID to link}
                            {--all : Also create missing transactions for paid invoices without any transactions}';
    protected $description = 'Sync invoice transactions to PaymentAccount';

    public function handle(): int
    {
        $specificAccountId = $this->option('account');
        $createMissing = $this->option('all');

        // Get account to use
        if ($specificAccountId) {
            $account = PaymentAccount::find($specificAccountId);
            if (!$account) {
                $this->error("Account #{$specificAccountId} not found");
                return Command::FAILURE;
            }
        } else {
            $account = PaymentAccount::first();
            if (!$account) {
                $this->error('No payment account found');
                return Command::FAILURE;
            }
            $specificAccountId = $account->id;
        }

        $this->info("Using account: {$account->name} (#{$specificAccountId})");

        // Part 1: Fix transactions without account
        $transactionsWithoutAccount = Transaction::where('reference_type', 'invoice')
            ->whereNull('payment_account_id')
            ->get();

        $this->info("Found {$transactionsWithoutAccount->count()} invoice transactions without account");

        $updated = 0;
        foreach ($transactionsWithoutAccount as $transaction) {
            $transaction->update(['payment_account_id' => $specificAccountId]);
            $this->line("Updated Transaction #{$transaction->id} - Rp " . number_format($transaction->amount, 0, ',', '.'));
            $updated++;
        }

        if ($updated > 0) {
            $this->info("Updated {$updated} transactions");
        }

        // Part 2: Create missing transactions for invoices with discrepancies
        if ($createMissing) {
            $this->newLine();
            $this->info("=== Creating missing transactions for paid invoices ===");

            $invoices = Invoice::where('paid', '>', 0)->get();
            $created = 0;
            $totalAmount = 0;

            foreach ($invoices as $invoice) {
                $txTotal = Transaction::where('reference_type', 'invoice')
                    ->where('reference_id', $invoice->id)
                    ->sum('amount');

                $diff = round($invoice->paid, 2) - round($txTotal, 2);

                if ($diff > 0.01) {
                    $this->line("");
                    $this->warn("Invoice #{$invoice->uuid} ({$invoice->nama_perusahaan})");
                    $this->line("  Invoice paid: Rp " . number_format($invoice->paid, 0, ',', '.') . " | Transactions: Rp " . number_format($txTotal, 0, ',', '.'));
                    $this->info("  >>> Creating: Rp " . number_format($diff, 0, ',', '.'));

                    Transaction::create([
                        'payment_account_id' => $specificAccountId,
                        'created_by' => $invoice->user_id ?? 1,
                        'type' => 'in',
                        'amount' => $diff,
                        'description' => "Sync Invoice: {$invoice->uuid} - {$invoice->nama_perusahaan}",
                        'reference_type' => 'invoice',
                        'reference_id' => $invoice->id,
                        'transaction_date' => now()->toDateString(),
                        'category' => 'operational',
                    ]);

                    $created++;
                    $totalAmount += $diff;
                }
            }

            $this->newLine();
            $this->info("Created {$created} missing transactions totaling Rp " . number_format($totalAmount, 0, ',', '.'));
        }

        // Part 3: Sync all account balances
        $this->newLine();
        $this->info("Syncing account balances...");
        PaymentAccount::syncAllBalances();

        // Show final balances
        $accounts = PaymentAccount::all();
        $this->table(
            ['Account', 'Balance'],
            $accounts->map(fn($acc) => [$acc->name, 'Rp ' . number_format($acc->getAvailableBalance(), 0, ',', '.')])->toArray()
        );

        $this->newLine();
        $this->info("Sync completed!");

        return Command::SUCCESS;
    }
}
