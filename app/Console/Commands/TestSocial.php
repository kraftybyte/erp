<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SocialAccount;
use App\Jobs\SyncSocialAccountJob;

class TestSocial extends Command
{
    protected $signature = 'test:social {id=1}';
    protected $description = 'Test sync social account';

    public function handle()
    {
        $id = $this->argument('id');
        $account = SocialAccount::find($id);

        if (!$account) {
            $this->error("❌ Account ID {$id} not found!");
            return 1;
        }

        $this->info("✅ Found: {$account->display_name} ({$account->platform})");
        $this->info("📊 Status DB: " . ($account->sync_status ?? 'NULL'));

        $this->warn("🚀 Dispatching job...");
        SyncSocialAccountJob::dispatch($account, 'all');
        
        sleep(1);
        $account->refresh();
        
        $this->info("✨ New Status: " . ($account->sync_status ?? 'NULL'));
        $this->info("⏰ Started At: " . ($account->last_sync_started_at?->format('H:i:s') ?? 'N/A'));
        
        $this->info("✅ Job dispatched! Cek worker untuk progress.");
        
        return 0;
    }
}