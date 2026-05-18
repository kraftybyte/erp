<?php

namespace App\Services;

use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountMetric;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramSyncService
{
    private const API_BASE = 'https://graph.instagram.com';
    private const API_VERSION = 'v18.0';

    /**
     * Sync all data for an account
     */
    public function syncAccount(ConnectedAccount $account): void
    {
        $token = $account->access_token_decrypted;

        if (!$token) {
            throw new \Exception('No access token available');
        }

        $this->syncProfile($account, $token);

        if ($account->account_type !== 'personal') {
            $this->syncInsights($account, $token);
        }

        $account->update(['last_synced_at' => now()]);
    }

    /**
     * Sync basic profile data
     */
    public function syncProfile(ConnectedAccount $account, ?string $token = null): void
    {
        $token = $token ?? $account->access_token_decrypted;

        $response = Http::withToken($token)
            ->get($this->getEndpoint('/me'), [
                'fields' => 'id,username,name,account_type,media_count,profile_picture_url,biography,website',
            ]);

        if (!$response->successful()) {
            Log::error('Instagram profile sync failed', [
                'account_id' => $account->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return;
        }

        $data = $response->json();

        $account->update([
            'username' => $data['username'],
            'name' => $data['name'] ?? null,
            'profile_picture' => $data['profile_picture_url'] ?? null,
            'account_type' => $this->normalizeAccountType($data['account_type'] ?? null),
            'metadata' => array_merge($account->metadata ?? [], [
                'biography' => $data['biography'] ?? null,
                'website' => $data['website'] ?? null,
                'media_count' => $data['media_count'] ?? 0,
            ]),
        ]);
    }

    /**
     * Sync insights/analytics data
     */
    public function syncInsights(ConnectedAccount $account, ?string $token = null): void
    {
        $token = $token ?? $account->access_token_decrypted;

        $this->syncDailyInsights($account, $token, 7);
    }

    /**
     * Sync daily metrics for past N days
     */
    public function syncDailyInsights(ConnectedAccount $account, string $token, int $days = 7): void
    {
        $endDate = now();
        $startDate = now()->subDays($days);

        $metrics = $this->getAccountInsights($token, $startDate->format('Y-m-d'), $endDate->format('Y-m-d'));

        foreach ($metrics as $date => $data) {
            ConnectedAccountMetric::updateOrCreate(
                [
                    'connected_account_id' => $account->id,
                    'date' => $date,
                ],
                $data
            );
        }
    }

    /**
     * Refresh an expired token
     */
    public function refreshToken(ConnectedAccount $account): void
    {
        $token = $account->access_token_decrypted;

        if (!$token) {
            throw new \Exception('No token to refresh');
        }

        $response = Http::asForm()->post($this->getEndpoint('/refresh_access_token'), [
            'grant_type' => 'ig_refresh_token',
            'access_token' => $token,
        ]);

        if (!$response->successful()) {
            throw new \Exception('Failed to refresh token: ' . $response->body());
        }

        $data = $response->json();

        $account->update([
            'access_token' => $data['access_token'],
            'token_expires_at' => now()->addSeconds($data['expires_in']),
        ]);
    }

    /**
     * Get media/posts from account
     */
    public function getMedia(ConnectedAccount $account, int $limit = 50): array
    {
        $token = $account->access_token_decrypted;

        $response = Http::withToken($token)
            ->get($this->getEndpoint("/{$account->account_id}/media"), [
                'fields' => 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,username,comments_count,like_count',
                'limit' => $limit,
            ]);

        if (!$response->successful()) {
            Log::error('Instagram media fetch failed', [
                'account_id' => $account->id,
                'body' => $response->body(),
            ]);
            return [];
        }

        return $response->json()['data'] ?? [];
    }

    /**
     * Get insights for media items
     */
    public function getMediaInsights(ConnectedAccount $account, string $mediaId): array
    {
        $token = $account->access_token_decrypted;

        $response = Http::withToken($token)
            ->get($this->getEndpoint("/{$mediaId}/insights"), [
                'metric' => 'reach,impressions,engagement,saved,video_views',
            ]);

        if (!$response->successful()) {
            return [];
        }

        return $response->json()['data'] ?? [];
    }

    /**
     * Get account-level insights
     */
    private function getAccountInsights(string $token, string $since, string $until): array
    {
        $response = Http::withToken($token)
            ->get($this->getEndpoint('/me/insights'), [
                'metric' => 'followers_count,follows_count,media_count,reach,impressions,profile_views',
                'period' => 'day',
                'since' => $since,
                'until' => $until,
            ]);

        if (!$response->successful()) {
            Log::error('Instagram insights fetch failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return [];
        }

        return $this->normalizeInsightsData($response->json());
    }

    /**
     * Normalize insights data from API response to metric format
     */
    private function normalizeInsightsData(array $response): array
    {
        $data = $response['data'] ?? [];
        $metrics = [];

        $followersCount = $this->extractMetric($data, 'followers_count');
        $reach = $this->extractMetric($data, 'reach');
        $impressions = $this->extractMetric($data, 'impressions');
        $profileViews = $this->extractMetric($data, 'profile_views');

        foreach ($reach as $date => $value) {
            $followers = $followersCount[$date] ?? ($metrics[$date]['followers'] ?? 0);
            $reachValue = $value ?? 0;
            $impressionsValue = $impressions[$date] ?? 0;

            $metrics[$date] = [
                'followers' => $followers,
                'reach' => $reachValue,
                'impressions' => $impressionsValue,
                'profile_visits' => $profileViews[$date] ?? 0,
                'engagement_rate' => $this->calculateEngagementRate($followers, $reachValue),
            ];
        }

        return $metrics;
    }

    /**
     * Extract a specific metric from API response
     */
    private function extractMetric(array $data, string $metricName): array
    {
        $result = [];

        foreach ($data as $metric) {
            if (($metric['title'] ?? '') === $metricName && isset($metric['values'])) {
                foreach ($metric['values'] as $value) {
                    $date = $value['end_time'] ?? '';
                    if ($date) {
                        $result[date('Y-m-d', strtotime($date))] = $value['value'];
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Calculate engagement rate
     */
    private function calculateEngagementRate(int $followers, int $reach): float
    {
        if ($followers === 0 || $reach === 0) {
            return 0.0;
        }

        return round(($reach / $followers) * 100, 4);
    }

    /**
     * Get full API endpoint URL
     */
    private function getEndpoint(string $path): string
    {
        return self::API_BASE . '/' . self::API_VERSION . $path;
    }

    /**
     * Normalize account type from API response
     */
    private function normalizeAccountType(?string $type): string
    {
        return match (strtoupper($type ?? '')) {
            'BUSINESS' => 'business',
            'CREATOR' => 'creator',
            default => 'personal',
        };
    }
}