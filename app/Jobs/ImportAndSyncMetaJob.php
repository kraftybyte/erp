<?php

namespace App\Jobs;

use App\Models\Deal;
use App\Models\SocialAccount;
use App\Services\FacebookPageService;
use App\Services\InstagramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ImportAndSyncMetaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600; // 1 jam
    public int $tries   = 1;

    public function handle(): void
    {
        $baseUrl    = 'https://graph.facebook.com/v19.0';
        $token      = config('services.meta.default_token');
        $businessId = config('services.meta.business_id');
        $appId      = config('services.meta.app_id');
        $appSecret  = config('services.meta.app_secret');

        $status = [
            'status'   => 'running',
            'step'     => 'Mengambil data dari Meta Business Manager...',
            'imported' => 0,
            'updated'  => 0,
            'synced'   => 0,
            'failed'   => 0,
            'errors'   => [],
        ];

        Cache::put('meta_sync_status', $status, now()->addHour());

        // ── STEP 1: Import dari Meta BM ──
        $pagesResponse = Http::get("{$baseUrl}/{$businessId}/owned_pages", [
            'fields'       => 'id,name,access_token,fan_count,followers_count,instagram_business_account',
            'access_token' => $token,
            'limit'        => 100,
        ]);

        if ($pagesResponse->ok() && !isset($pagesResponse->json()['error'])) {
            foreach ($pagesResponse->json()['data'] ?? [] as $page) {
                $status['step'] = "Import: {$page['name']}...";
                Cache::put('meta_sync_status', $status, now()->addHour());

                $pageToken = $page['access_token'] ?? $token;

                $ltResponse     = Http::get("{$baseUrl}/oauth/access_token", [
                    'grant_type'        => 'fb_exchange_token',
                    'client_id'         => $appId,
                    'client_secret'     => $appSecret,
                    'fb_exchange_token' => $pageToken,
                ]);
                $longLivedToken = $ltResponse->json()['access_token'] ?? $pageToken;
                $expiresIn      = $ltResponse->json()['expires_in'] ?? 5184000;

                $deal = Deal::where('nama_perusahaan', 'like', '%' . $page['name'] . '%')->first()
                    ?? Deal::first();

                // Facebook Page
                $existingFb = SocialAccount::where('platform', 'facebook')
                    ->where('platform_account_id', $page['id'])->first();

                $fbData = [
                    'client_id'           => $deal->id,
                    'platform'            => 'facebook',
                    'platform_account_id' => $page['id'],
                    'page_name'           => $page['name'],
                    'access_token'        => $longLivedToken,
                    'token_expires_at'    => now()->addSeconds($expiresIn),
                    'fan_count'           => $page['fan_count'] ?? 0,
                    'followers_count'     => $page['followers_count'] ?? 0,
                    'is_active'           => true,
                ];

                if ($existingFb) {
                    $existingFb->update($fbData);
                    $status['updated']++;
                } else {
                    SocialAccount::create($fbData);
                    $status['imported']++;
                }

                // Instagram
                if (isset($page['instagram_business_account']['id'])) {
                    $igId       = $page['instagram_business_account']['id'];
                    $igResponse = Http::get("{$baseUrl}/{$igId}", [
                        'fields'       => 'id,username,followers_count',
                        'access_token' => $longLivedToken,
                    ]);
                    $igData = $igResponse->json();

                    $existingIg = SocialAccount::where('platform', 'instagram')
                        ->where('platform_account_id', $igId)->first();

                    $igAccountData = [
                        'client_id'           => $deal->id,
                        'platform'            => 'instagram',
                        'platform_account_id' => $igId,
                        'username'            => $igData['username'] ?? null,
                        'page_name'           => $page['name'],
                        'access_token'        => $longLivedToken,
                        'token_expires_at'    => now()->addSeconds($expiresIn),
                        'followers_count'     => $igData['followers_count'] ?? 0,
                        'is_active'           => true,
                    ];

                    if ($existingIg) {
                        $existingIg->update($igAccountData);
                        $status['updated']++;
                    } else {
                        SocialAccount::create($igAccountData);
                        $status['imported']++;
                    }
                }

                Cache::put('meta_sync_status', $status, now()->addHour());
            }
        }

        // ── STEP 2: Sync semua akun aktif ──
        $allAccounts = SocialAccount::where('is_active', true)->get();
        $total       = $allAccounts->count();
        $current     = 0;

        foreach ($allAccounts as $account) {
            $current++;
            $name            = $account->username ?? $account->page_name;
            $status['step']  = "Sync ({$current}/{$total}): {$name}...";
            Cache::put('meta_sync_status', $status, now()->addHour());

            try {
                if ($account->platform === 'instagram') {
                    $service = new InstagramService();
                } else {
                    $service = new FacebookPageService();
                }

                $result = $service->syncAll($account);

                if ($result['success']) {
                    $status['synced']++;
                } else {
                    $status['failed']++;
                    $status['errors'][] = "{$name}: {$result['message']}";
                    Log::warning("Sync gagal [{$name}]: {$result['message']}");
                }
            } catch (\Exception $e) {
                $status['failed']++;
                $status['errors'][] = "{$name}: {$e->getMessage()}";
                Log::error("Sync error [{$name}]: {$e->getMessage()}");
            }

            Cache::put('meta_sync_status', $status, now()->addHour());
        }

        $status['status'] = 'done';
        $status['step']   = 'Selesai!';
        Cache::put('meta_sync_status', $status, now()->addHour());
    }
}