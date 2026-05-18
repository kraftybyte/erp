<?php

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

    public function __construct(
        public SocialAccount $account,
        public ?string $syncType = 'all'
    ) {}

    public function handle(): void
    {
        // Resolve services manually (lebih stabil untuk queue)
        $instagram = app(InstagramService::class);
        $facebook = app(FacebookPageService::class);

        // Update status to syncing
        $this->account->update([
            'sync_status' => 'syncing',
            'last_sync_started_at' => now(),
        ]);

        try {
            // Pilih service berdasarkan platform
            if ($this->account->isInstagram()) {
                $service = $instagram;
            } elseif ($this->account->isFacebook()) {
                $service = $facebook;
            } else {
                throw new \Exception("Platform {$this->account->platform} not supported");
            }

            // Execute sync berdasarkan type
            $result = match($this->syncType) {
                'profile' => $service->syncProfile($this->account),
                'insights' => $service->syncInsights($this->account),
                'posts', 'metrics_only' => $service->syncPosts($this->account),
                default => $service->syncAll($this->account),
            };

            // Cek apakah ada error di result
            if (isset($result['success']) && !$result['success']) {
                throw new \Exception($result['error'] ?? $result['message'] ?? 'Sync failed');
            }

            // Update success status
            $this->account->update([
                'sync_status' => 'completed',
                'last_synced_at' => now(),
                'last_sync_error' => null,
            ]);

            Log::info("Social sync completed [{$this->account->platform}:{$this->account->id}]", [
                'type' => $this->syncType,
                'result' => $result,
            ]);

        } catch (\Throwable $e) {
            $this->handleFailure($e);
            throw $e; // Re-throw untuk retry mechanism
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