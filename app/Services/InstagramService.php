<?php
// app/Services/InstagramService.php

namespace App\Services;

use App\Models\SocialAccount;
use App\Models\SocialAccountInsight;
use App\Models\SocialPost;
use App\Models\SocialPostMetric;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramService
{
    private string $baseUrl = 'https://graph.facebook.com/v19.0';

    /*
    |--------------------------------------------------------------------------
    | HTTP Helper
    |--------------------------------------------------------------------------
    */
    private function get(string $endpoint, array $params, string $token): array
    {
        $response = Http::timeout(30)
            ->retry(3, 1000, throw: false)
            ->get("{$this->baseUrl}/{$endpoint}", array_merge($params, [
                'access_token' => $token
            ]));

        if ($response->failed()) {
            throw new \Exception("Meta API Error [{$response->status()}]: " . $response->body());
        }

        $json = $response->json();

        if (isset($json['error'])) {
            throw new \Exception("Meta API Error: " . $json['error']['message']);
        }

        return $json;
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve Instagram Business ID
    |--------------------------------------------------------------------------
    */
    private function resolveInstagramId(SocialAccount $account): string
    {
        // Coba langsung sebagai IG ID
        try {
            $test = $this->get($account->platform_account_id, [
                'fields' => 'id,username',
            ], $account->access_token);

            if (isset($test['username'])) {
                return $account->platform_account_id;
            }
        } catch (\Exception $e) {
            // Bukan IG ID, coba via Facebook Page
        }

        // Coba via Facebook Page
        $pageData = $this->get($account->platform_account_id, [
            'fields' => 'instagram_business_account',
        ], $account->access_token);

        if (isset($pageData['instagram_business_account']['id'])) {
            $igId = $pageData['instagram_business_account']['id'];
            $account->update(['platform_account_id' => $igId]);
            Log::info("IG ID resolved: {$igId}");
            return $igId;
        }

        throw new \Exception("Instagram Business Account tidak ditemukan untuk akun {$account->platform_account_id}.");
    }

    /*
    |--------------------------------------------------------------------------
    | Sync Profile
    |--------------------------------------------------------------------------
    */
    public function syncProfile(SocialAccount $account): array
    {
        try {
            $igId = $this->resolveInstagramId($account);
            
            $data = $this->get($igId, [
                'fields' => 'id,username,followers_count,follows_count,media_count,profile_picture_url',
            ], $account->access_token);

            $account->update([
                'username'            => $data['username'] ?? $account->username,
                'followers_count'     => $data['followers_count'] ?? 0,
                'following_count'     => $data['follows_count'] ?? 0,
                'media_count'         => $data['media_count'] ?? 0,
                'profile_picture_url' => $data['profile_picture_url'] ?? null,
            ]);

            Log::info("Instagram profile synced [{$account->id}]: {$account->username}");
            
            return ['success' => true, 'data' => $data];

        } catch (\Exception $e) {
            Log::warning("Instagram syncProfile failed [{$account->id}]: " . $e->getMessage());
            throw $e;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Sync Account Insights (Daily)
    |--------------------------------------------------------------------------
    */
    public function syncInsights(SocialAccount $account, int $days = 29): array
    {
        try {
            $igId = $this->resolveInstagramId($account);
            
            // Max 29 days untuk daily insights
            $days = min($days, 29);
            $since = now()->subDays($days)->startOfDay()->timestamp;
            $until = now()->startOfDay()->timestamp;

            $byDate = [];

            // Fetch reach per hari
            try {
                $data = $this->get("{$igId}/insights", [
                    'metric' => 'reach',
                    'period' => 'day',
                    'since'  => $since,
                    'until'  => $until,
                ], $account->access_token);

                foreach ($data['data'] ?? [] as $item) {
                    foreach ($item['values'] ?? [] as $entry) {
                        $date = Carbon::parse($entry['end_time'])->toDateString();
                        $byDate[$date]['reach'] = $entry['value'] ?? 0;
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Reach insights gagal [{$igId}]: " . $e->getMessage());
            }

            // Fetch impressions per hari
            try {
                $data = $this->get("{$igId}/insights", [
                    'metric' => 'impressions',
                    'period' => 'day',
                    'since'  => $since,
                    'until'  => $until,
                ], $account->access_token);

                foreach ($data['data'] ?? [] as $item) {
                    foreach ($item['values'] ?? [] as $entry) {
                        $date = Carbon::parse($entry['end_time'])->toDateString();
                        $byDate[$date]['impressions'] = $entry['value'] ?? 0;
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Impressions insights gagal [{$igId}]: " . $e->getMessage());
            }

            // Fetch follower_count dari profile
            try {
                $profile = $this->get($igId, ['fields' => 'followers_count'], $account->access_token);
                $followerCount = $profile['followers_count'] ?? 0;

                foreach ($byDate as $date => &$values) {
                    $values['follower_count'] = $followerCount;
                }
                unset($values);
            } catch (\Exception $e) {
                Log::warning("Follower count gagal [{$igId}]: " . $e->getMessage());
            }

            // Save ke database
            foreach ($byDate as $date => $values) {
                SocialAccountInsight::updateOrCreate(
                    ['social_account_id' => $account->id, 'date' => $date],
                    [
                        'reach'          => $values['reach'] ?? 0,
                        'impressions'    => $values['impressions'] ?? 0,
                        'follower_count' => $values['follower_count'] ?? 0,
                    ]
                );
            }

            Log::info("Instagram insights synced [{$account->id}]: " . count($byDate) . " days");
            return ['success' => true, 'count' => count($byDate)];

        } catch (\Exception $e) {
            Log::warning("Instagram syncInsights failed [{$account->id}]: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Sync Posts
    |--------------------------------------------------------------------------
    */
    public function syncPosts(SocialAccount $account): array
    {
        try {
            $igId = $this->resolveInstagramId($account);
            
            $data = $this->get("{$igId}/media", [
                'fields' => 'id,media_type,media_product_type,caption,permalink,thumbnail_url,media_url,timestamp',
                'limit'  => 50,
            ], $account->access_token);

            $processed = 0;

            foreach ($data['data'] ?? [] as $postData) {
                // media_product_type: REELS | FEED | AD
                // API kadang return VIDEO untuk Reels — override pakai product type
                $mediaType = $postData['media_type'] ?? null;
                if (($postData['media_product_type'] ?? '') === 'REELS') {
                    $mediaType = 'REELS';
                }

                $post = SocialPost::updateOrCreate(
                    ['platform_post_id' => $postData['id']],
                    [
                        'social_account_id' => $account->id,
                        'media_type'        => $mediaType,
                        'caption'           => $postData['caption'] ?? null,
                        'permalink'         => $postData['permalink'] ?? null,
                        'thumbnail_url'     => $postData['thumbnail_url'] ?? $postData['media_url'] ?? null,
                        'media_url'         => $postData['media_url'] ?? null,
                        'posted_at'         => $postData['timestamp'] ?? null,
                    ]
                );

                $this->syncPostMetrics($post, $account);
                $processed++;
            }

            Log::info("Instagram posts synced [{$account->id}]: {$processed} posts");
            return ['success' => true, 'processed' => $processed];

        } catch (\Exception $e) {
            Log::warning("Instagram syncPosts failed [{$account->id}]: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Sync Post Metrics (Helper)
    |--------------------------------------------------------------------------
    */
    private function syncPostMetrics(SocialPost $post, SocialAccount $account): void
    {
        try {
            // ── Likes & Comments via basic fields ────────────────────
            $media = $this->get($post->platform_post_id, [
                'fields' => 'like_count,comments_count',
            ], $account->access_token);

            $likes    = (int) ($media['like_count']     ?? 0);
            $comments = (int) ($media['comments_count'] ?? 0);

            // ── Metric valid per media type (confirmed v19 API) ───────
            // reach, saved, total_interactions → semua type ✅
            // plays                            → REELS only ✅
            // shares                           → kadang error, skip per type
            // video_views                      → ❌ tidak support feed video
            // impressions                      → ❌ deprecated v22+
            $type = strtoupper($post->media_type ?? '');

            $baseMetrics  = ['reach', 'saved', 'total_interactions'];
            $extraMetrics = match ($type) {
                'REELS' => ['plays', 'shares'],
                default => ['shares'],
            };

            $values = [];

            // Fetch base metrics sekaligus
            try {
                $res = $this->get("{$post->platform_post_id}/insights", [
                    'metric' => implode(',', $baseMetrics),
                ], $account->access_token);

                foreach ($res['data'] ?? [] as $item) {
                    $values[$item['name']] = isset($item['value'])
                        ? (int) $item['value']
                        : (int) ($item['values'][0]['value'] ?? 0);
                }
            } catch (\Exception $e) {
                Log::warning("Base metrics gagal [{$post->platform_post_id}]: " . $e->getMessage());
            }

            // Fetch extra metrics — skip jika tidak support
            foreach ($extraMetrics as $metric) {
                try {
                    $res = $this->get("{$post->platform_post_id}/insights", [
                        'metric' => $metric,
                    ], $account->access_token);

                    foreach ($res['data'] ?? [] as $item) {
                        $values[$item['name']] = isset($item['value'])
                            ? (int) $item['value']
                            : (int) ($item['values'][0]['value'] ?? 0);
                    }
                } catch (\Exception $e) {
                    Log::debug("Metric [{$metric}] skip [{$post->platform_post_id}]");
                }
            }

            $reach             = (int) ($values['reach']              ?? 0);
            $saved             = (int) ($values['saved']              ?? 0);
            $totalInteractions = (int) ($values['total_interactions'] ?? 0);
            $shares            = (int) ($values['shares']             ?? 0);
            $plays             = (int) ($values['plays']              ?? 0); // REELS only

            $followers      = max((int) ($account->followers_count ?? 1), 1);
            $engagementRate = round(($totalInteractions / $followers) * 100, 4);

            SocialPostMetric::updateOrCreate(
                ['social_post_id' => $post->id],
                [
                    'likes'           => $likes,
                    'comments'        => $comments,
                    'reach'           => $reach,
                    'impressions'     => 0,
                    'saved'           => $saved,
                    'video_views'     => $plays,
                    'reactions'       => $totalInteractions,
                    'clicks'          => $shares,
                    'engagement_rate' => $engagementRate,
                    'synced_at'       => now(),
                ]
            );

        } catch (\Exception $e) {
            Log::warning("Post metrics gagal [{$post->platform_post_id}]: " . $e->getMessage());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Public Wrapper Methods
    |--------------------------------------------------------------------------
    */
    public function syncProfileOnly(SocialAccount $account): array
    {
        try {
            return $this->syncProfile($account);
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function syncInsightsOnly(SocialAccount $account, int $days = 29): array
    {
        try {
            return $this->syncInsights($account, $days);
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function syncPostsOnly(SocialAccount $account): array
    {
        try {
            return $this->syncPosts($account);
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function syncAll(SocialAccount $account): array
    {
        $errors = [];
        $results = [];

        try {
            $results['profile'] = $this->syncProfile($account);
        } catch (\Exception $e) {
            $errors[] = 'profile: ' . $e->getMessage();
        }

        try {
            $results['insights'] = $this->syncInsights($account);
        } catch (\Exception $e) {
            $errors[] = 'insights: ' . $e->getMessage();
        }

        try {
            $results['posts'] = $this->syncPosts($account);
        } catch (\Exception $e) {
            $errors[] = 'posts: ' . $e->getMessage();
        }

        if (!empty($results)) {
            $account->update(['last_synced_at' => now()]);
        }

        return [
            'success' => empty($errors),
            'results' => $results,
            'errors'  => $errors,
        ];
    }
}