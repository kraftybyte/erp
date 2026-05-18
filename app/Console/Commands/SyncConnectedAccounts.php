<?php

namespace App\Console\Commands;

use App\Models\ConnectedAccount;
use App\Services\InstagramSyncService;
use Illuminate\Console\Command;

class SyncConnectedAccounts extends Command
{
    protected $signature = 'social:sync
                            {--account= : Specific account ID to sync}
                            {--provider= : Filter by provider (instagram, facebook, etc.)}
                            {--all : Sync all accounts regardless of last sync time}';

    protected $description = 'Sync social media account data';

    public function handle(InstagramSyncService $syncService): int
    {
        $query = ConnectedAccount::where('is_active', true);

        if ($accountId = $this->option('account')) {
            $query->where('id', $accountId);
        }

        if ($provider = $this->option('provider')) {
            $query->where('provider', $provider);
        }

        $accounts = $query->get();

        if ($accounts->isEmpty()) {
            $this->warn('No active accounts found to sync.');
            return self::SUCCESS;
        }

        $this->info("Found {$accounts->count()} account(s) to sync.");
        $bar = $this->output->createProgressBar($accounts->count());
        $bar->start();

        $success = 0;
        $failed = 0;

        foreach ($accounts as $account) {
            try {
                $this->line("\n  Syncing @{$account->username}...");

                $syncService->syncAccount($account);

                $success++;
                $this->line(" <info>✓</info>");

            } catch (\Exception $e) {
                $failed++;
                $this->line(" <error>✗</error> {$e->getMessage()}");

                // Check if token is expired
                if (str_contains(strtolower($e->getMessage()), 'token') ||
                    str_contains(strtolower($e->getMessage()), 'expired') ||
                    str_contains(strtolower($e->getMessage()), 'invalid')) {
                    $account->update(['is_active' => false]);
                    $this->warn("    Token invalid - account deactivated");
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Sync complete: {$success} successful, {$failed} failed");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}