<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\MetaAdAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncMetaAds extends Command
{
    protected $signature = 'meta:sync {--account-id=}';
    protected $description = 'Sync Meta Ads data';

    public function handle(): int
    {
        $accountId = $this->option('account-id');

        $query = MetaAdAccount::query();

        if ($accountId) {
            $query->where('id', $accountId);
        }

        $accounts = $query->where('is_active', true)->get();

        if ($accounts->isEmpty()) {
            $this->info('No Meta Ad accounts to sync.');
            return Command::SUCCESS;
        }

        $this->info("Syncing {$accounts->count()} Meta Ad account(s)...");

        foreach ($accounts as $account) {
            $this->line("Syncing Ad Account: {$account->account_id}");

            try {
                $this->syncAdAccount($account);
            } catch (\Throwable $e) {
                $this->error("Failed to sync {$account->account_id}: {$e->getMessage()}");
                Log::error('Meta Ads sync failed', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info('Meta Ads sync complete!');
        return Command::SUCCESS;
    }

    private function syncAdAccount($account): void
    {
        $accessToken = $account->access_token;

        if (!$accessToken) {
            throw new \Exception('No access token available');
        }

        // Get account insights
        $response = Http::withToken($accessToken)
            ->get("https://graph.facebook.com/v19.0/{$account->account_id}/insights", [
                'fields' => 'impressions,reach,spend,clicks,ctr,inline_link_clicks',
                'date_preset' => 'last_7_days',
            ]);

        if ($response->successful()) {
            $data = $response->json();

            // Update account with latest data
            $account->update([
                'last_sync_at' => now(),
                'sync_data' => $data,
            ]);

            $this->info("  Updated insights for {$account->account_id}");
        }

        // Cache the sync status
        Cache::put("meta_account_{$account->id}_last_sync", now(), 3600);
    }
}
