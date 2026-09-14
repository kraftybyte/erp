<?php

namespace App\Console\Commands;

use App\Models\PaymentAccount;
use Illuminate\Console\Command;

class SyncPaymentAccountBalances extends Command
{
    protected $signature = 'payment-accounts:sync-balances {--dry-run : Show what would be synced without making changes}';

    protected $description = 'Sync payment account balances from transactions';

    public function handle()
    {
        $dryRun = $this->option('dry-run');

        $accounts = PaymentAccount::all();

        $this->info("Found {$accounts->count()} payment accounts.");

        $synced = 0;

        foreach ($accounts as $account) {
            $storedBalance = (float) $account->current_balance;
            $calculatedBalance = $account->getAvailableBalance();
            $difference = $calculatedBalance - $storedBalance;

            if ($dryRun) {
                $this->line("Account: {$account->name}");
                $this->line("  Stored Balance: Rp " . number_format($storedBalance, 0, ',', '.'));
                $this->line("  Calculated Balance: Rp " . number_format($calculatedBalance, 0, ',', '.'));
                $this->line("  Difference: Rp " . number_format($difference, 0, ',', '.'));

                if (abs($difference) > 0.01) {
                    $this->warn("  -> NEEDS SYNC");
                } else {
                    $this->info("  -> OK");
                }
                $this->line("");
                continue;
            }

            // Update the stored balance
            $account->update([
                'current_balance' => $calculatedBalance,
            ]);

            $synced++;
            $this->info("Synced {$account->name}: Rp " . number_format($calculatedBalance, 0, ',', '.') . " (was: Rp " . number_format($storedBalance, 0, ',', '.') . ")");
        }

        if ($dryRun) {
            $this->warn("(Dry run - no actual changes made)");
        } else {
            $this->info("");
            $this->info("Synced {$synced} accounts.");
        }
    }
}
