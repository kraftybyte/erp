<?php
// app/Jobs/SyncSocialAccountJob.php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Services\InstagramService;
use App\Services\FacebookPageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncSocialAccountJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 60, 120];
    public int $timeout = 180;

    public function tags(): array
    {
        return [
            'social-sync',
            'platform:' . $this->account->platform,
            'account:' . $this->account->id,
        ];
    }

    public function __construct(
        public SocialAccount $account,
        public ?string $syncType = 'all'
    ) {}

    public function handle(InstagramService $instagram, FacebookPageService $facebook): void
    {
        // Update status to syncing
        $this->account->update([
            'sync_status' => 'syncing',
            'last_sync_started_at' => now(),
        ]);

        try {
            if ($this->account->isInstagram()) {
                $result = match($this->syncType) {
                    'profile' => $instagram->syncProfile($this->account),
                    'insights' => $instagram->syncInsights($this->account),
                    'posts' => $instagram->syncPosts($this->account),
                    default => $instagram->syncAll($this->account),
                };
            } elseif ($this->account->isFacebook()) {
                $result = match($this->syncType) {
                    'profile' => $facebook->syncProfile($this->account),
                    'insights' => $facebook->syncInsights($this->account),
                    'posts' => $facebook->syncPosts($this->account),
                    default => $facebook->syncAll($this->account),
                };
            } else {
                throw new \Exception("Platform {$this->account->platform} not supported");
            }

            // Update success status
            $this->account->update([
                'sync_status' => 'completed',
                'last_synced_at' => now(),
                'last_sync_error' => null,
                'sync_metadata' => [
                    'last_result' => $result,
                    'sync_type' => $this->syncType,
                ],
            ]);

            Log::info("Social sync completed [{$this->account->platform}:{$this->account->id}]", [
                'type' => $this->syncType,
                'result' => $result,
            ]);

        } catch (\Throwable $e) {
            $this->handleFailure($e);
            throw $e;
        }
    }

    protected function handleFailure(\Throwable $e): void
    {
        $errorMessage = Str::limit($e->getMessage(), 500);
        
        $this->account->update([
            'sync_status' => 'failed',
            'last_sync_error' => $errorMessage,
            'last_sync_attempt' => now(),
        ]);

        Log::error("Social sync failed [{$this->account->platform}:{$this->account->id}]", [
            'error' => $e->getMessage(),
            'sync_type' => $this->syncType,
            'attempt' => $this->attempts(),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning("Social sync job failed permanently [{$this->account->id}]: " . $e->getMessage());
    }
}