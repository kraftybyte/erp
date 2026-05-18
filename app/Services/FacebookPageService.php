<?php
// app/Services/FacebookPageService.php

namespace App\Services;

use App\Models\SocialAccount;
use App\Models\SocialAccountInsight;
use App\Models\SocialPost;
use App\Models\SocialPostMetric;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookPageService
{
    private string $baseUrl = 'https://graph.facebook.com/v19.0';

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

    public function syncProfile(SocialAccount $account): array
    {
        try {
            $data = $this->get($account->platform_account_id, [
                'fields' => 'id,name,username,fan_count,link,picture.type(large)',
            ], $account->access_token);

            $account->update([
                'page_name'           => $data['name'] ?? $account->page_name,
                'username'            => $data['username'] ?? $account->username,
                'fan_count'           => $data['fan_count'] ?? 0,
                'followers_count'     => $data['fan_count'] ?? 0,
                'profile_picture_url' => $data['picture']['data']['url'] ?? null,
            ]);

            Log::info("Facebook page synced [{$account->id}]: {$account->page_name}");
            
            return ['success' => true, 'data' => $data];

        } catch (\Exception $e) {
            Log::warning("Facebook syncProfile failed [{$account->id}]: " . $e->getMessage());
            throw $e;
        }
    }

    public function syncInsights(SocialAccount $account, int $days = 29): array
    {
        try {
            $days = min($days, 29);
            $since = now()->subDays($days)->startOfDay()->timestamp;
            $until = now()->startOfDay()->timestamp;

            $byDate = [];

            // Page impressions
            try {
                $data = $this->get("{$account->platform_account_id}/insights", [
                    'metric' => 'page_impressions_unique',
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
                Log::warning("Page impressions gagal [{$account->id}]: " . $e->getMessage());
            }

            // Page reach
            try {
                $data = $this->get("{$account->platform_account_id}/insights", [
                    'metric' => 'page_posts_engaged_users',
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
                Log::warning("Page reach gagal [{$account->id}]: " . $e->getMessage());
            }

            // Fan count
            try {
                $pageData = $this->get($account->platform_account_id, ['fields' => 'fan_count'], $account->access_token);
                $fanCount = $pageData['fan_count'] ?? 0;

                foreach ($byDate as $date => &$values) {
                    $values['follower_count'] = $fanCount;
                }
                unset($values);
            } catch (\Exception $e) {
                Log::warning("Fan count gagal [{$account->id}]: " . $e->getMessage());
            }

            // Save to database
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

            Log::info("Facebook insights synced [{$account->id}]: " . count($byDate) . " days");
            return ['success' => true, 'count' => count($byDate)];

        } catch (\Exception $e) {
            Log::warning("Facebook syncInsights failed [{$account->id}]: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function syncPosts(SocialAccount $account): array
    {
        try {
            $data = $this->get("{$account->platform_account_id}/posts", [
                'fields' => 'id,message,created_time,permalink_url,full_picture',
                'limit'  => 50,
            ], $account->access_token);

            $processed = 0;

            foreach ($data['data'] ?? [] as $postData) {
                $post = SocialPost::updateOrCreate(
                    ['platform_post_id' => $postData['id']],
                    [
                        'social_account_id' => $account->id,
                        'media_type'        => 'POST',
                        'caption'           => $postData['message'] ?? null,
                        'permalink'         => $postData['permalink_url'] ?? null,
                        'thumbnail_url'     => $postData['full_picture'] ?? null,
                        'media_url'         => $postData['full_picture'] ?? null,
                        'posted_at'         => $postData['created_time'] ?? null,
                    ]
                );

                $this->syncPostMetrics($post, $account);
                $processed++;
            }

            Log::info("Facebook posts synced [{$account->id}]: {$processed} posts");
            return ['success' => true, 'processed' => $processed];

        } catch (\Exception $e) {
            Log::warning("Facebook syncPosts failed [{$account->id}]: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function syncPostMetrics(SocialPost $post, SocialAccount $account): void
    {
        try {
            $media = $this->get($post->platform_post_id, [
                'fields' => 'reactions.summary(true),comments.summary(true),shares',
            ], $account->access_token);

            $likes = (int) ($media['reactions']['summary']['total_count'] ?? 0);
            $comments = (int) ($media['comments']['summary']['total_count'] ?? 0);
            $shares = (int) ($media['shares']['count'] ?? 0);

            $followers = max((int) ($account->fan_count ?? 1), 1);
            $engagementRate = round((($likes + $comments + $shares) / $followers) * 100, 4);

            SocialPostMetric::updateOrCreate(
                ['social_post_id' => $post->id],
                [
                    'likes'           => $likes,
                    'comments'        => $comments,
                    'shares'          => $shares,
                    'reach'           => 0,
                    'impressions'     => 0,
                    'engagement_rate' => $engagementRate,
                    'synced_at'       => now(),
                ]
            );

        } catch (\Exception $e) {
            Log::warning("FB post metrics gagal [{$post->platform_post_id}]: " . $e->getMessage());
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