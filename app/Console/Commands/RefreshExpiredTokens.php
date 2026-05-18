<?php

namespace App\Console\Commands;

use App\Models\ConnectedAccount;
use App\Services\InstagramSyncService;
use Illuminate\Console\Command;

class RefreshExpiredTokens extends Command
{
    protected $signature = 'social:refresh-tokens {--account= : Specific account ID}';

    protected $description = 'Refresh expired or expiring social media account tokens';

    public function handle(InstagramSyncService $syncService): int
    {
        $query = ConnectedAccount::where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('token_expires_at')
                    ->orWhere('token_expires_at', '<=', now()->addDays(7));
            });

        if ($accountId = $this->option('account')) {
            $query->where('id', $accountId);
        }

        $accounts = $query->get();

        if ($accounts->isEmpty()) {
            $this->info('No accounts need token refresh.');
            return self::SUCCESS;
        }

        $this->info("Found {$accounts->count()} account(s) needing token refresh.");

        foreach ($accounts as $account) {
            try {
                $this->line("Refreshing token for @{$account->username}...");
                $syncService->refreshToken($account);
                $this->info("  ✓ Token refreshed successfully");

            } catch (\Exception $e) {
                $this->error("  ✗ Failed: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}