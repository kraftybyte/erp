<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Services\InstagramSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class InstagramConnectController extends Controller
{
    public function __construct(
        protected InstagramSyncService $syncService
    ) {}

    /**
     * Redirect user to Instagram OAuth
     */
    public function redirect(Request $request): RedirectResponse
    {
        $type = $request->get('type', 'basic');

        // Store in session for callback reference
        Session::put('instagram_connect_type', $type);

        if ($type === 'business') {
            // Business account - full permissions for insights
            return $this->redirectToInstagramBusiness();
        }

        // Basic account
        return $this->redirectToInstagramBasic();
    }

    /**
     * Handle Instagram OAuth callback
     */
    public function callback(Request $request): RedirectResponse
    {
        try {
            // Verify state for security
            if ($request->has('error')) {
                Log::warning('Instagram OAuth error', [
                    'error' => $request->get('error'),
                    'error_reason' => $request->get('error_reason'),
                ]);

                return redirect()->route('filament.pages.connected-accounts')
                    ->with('error', 'Instagram connection failed: ' . $request->get('error_reason', 'Unknown error'));
            }

            // Get access token from Instagram
            $tokenResponse = $this->exchangeCodeForToken($request->get('code'));

            if (!$tokenResponse) {
                return redirect()->route('filament.pages.connected-accounts')
                    ->with('error', 'Failed to get access token from Instagram');
            }

            // Get user info from Instagram
            $instagramUser = $this->getInstagramUser($tokenResponse['access_token']);

            // Determine account type
            $accountType = $this->determineAccountType($instagramUser);

            // Save connected account
            $account = ConnectedAccount::updateOrCreate(
                [
                    'user_id' => Auth::id(),
                    'provider' => 'instagram',
                    'account_id' => $instagramUser['id'],
                ],
                [
                    'username' => $instagramUser['username'],
                    'name' => $instagramUser['name'] ?? null,
                    'profile_picture' => $instagramUser['profile_picture_url'] ?? null,
                    'access_token' => $tokenResponse['access_token'],
                    'token_expires_at' => isset($tokenResponse['expires_in'])
                        ? now()->addSeconds($tokenResponse['expires_in'])
                        : null,
                    'account_type' => $accountType,
                    'is_active' => true,
                    'metadata' => [
                        'token_type' => $tokenResponse['token_type'] ?? 'bearer',
                        'connected_at' => now()->toDateTimeString(),
                    ],
                ]
            );

            // Trigger initial sync
            try {
                $this->syncService->syncAccount($account);
            } catch (\Exception $e) {
                Log::warning('Initial Instagram sync failed', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return redirect()->route('filament.pages.connected-accounts')
                ->with('success', "Instagram @{$account->username} berhasil terhubung!");

        } catch (\Exception $e) {
            Log::error('Instagram OAuth callback error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->route('filament.pages.connected-accounts')
                ->with('error', 'Failed to connect Instagram: ' . $e->getMessage());
        }
    }

    /**
     * Handle Instagram Business OAuth callback (via Meta)
     */
    public function businessCallback(Request $request): RedirectResponse
    {
        try {
            if ($request->has('error')) {
                Log::warning('Instagram Business OAuth error', [
                    'error' => $request->get('error'),
                    'error_reason' => $request->get('error_reason'),
                ]);

                return redirect()->route('filament.pages.connected-accounts')
                    ->with('error', 'Instagram Business connection failed');
            }

            // Exchange code for token via Meta
            $clientId = config('services.meta.app_id');
            $clientSecret = config('services.meta.app_secret');
            $redirectUri = url('/auth/instagram-business/callback');

            $response = \Illuminate\Support\Facades\Http::asForm()->post('https://graph.facebook.com/v18.0/oauth/access_token', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $redirectUri,
                'code' => $request->get('code'),
            ]);

            if (!$response->successful()) {
                throw new \Exception('Failed to exchange code for token');
            }

            $tokenData = $response->json();
            $accessToken = $tokenData['access_token'];

            // Get Instagram business account
            $accountsResponse = \Illuminate\Support\Facades\Http::withToken($accessToken)
                ->get("https://graph.facebook.com/v18.0/me/accounts");

            $accounts = $accountsResponse->json()['data'] ?? [];

            foreach ($accounts as $page) {
                // Get Instagram account connected to this page
                $igResponse = \Illuminate\Support\Facades\Http::withToken($accessToken)
                    ->get("https://graph.facebook.com/v18.0/{$page['id']}", [
                        'fields' => 'instagram_business_account',
                    ]);

                $igAccount = $igResponse->json()['instagram_business_account'] ?? null;

                if ($igAccount) {
                    // Get Instagram account details
                    $igDetails = \Illuminate\Support\Facades\Http::withToken($accessToken)
                        ->get("https://graph.facebook.com/v18.0/{$igAccount['id']}", [
                            'fields' => 'id,username,name,account_type,media_count,profile_picture_url',
                        ])->json();

                    ConnectedAccount::updateOrCreate(
                        [
                            'user_id' => Auth::id(),
                            'provider' => 'instagram',
                            'account_id' => $igAccount['id'],
                        ],
                        [
                            'username' => $igDetails['username'] ?? $page['name'],
                            'name' => $igDetails['name'] ?? $page['name'],
                            'profile_picture' => $igDetails['profile_picture_url'] ?? null,
                            'access_token' => $accessToken,
                            'account_type' => 'business',
                            'is_active' => true,
                            'metadata' => [
                                'facebook_page_id' => $page['id'],
                                'connected_at' => now()->toDateTimeString(),
                            ],
                        ]
                    );
                }
            }

            return redirect()->route('filament.pages.connected-accounts')
                ->with('success', 'Instagram Business account(s) connected successfully!');

        } catch (\Exception $e) {
            Log::error('Instagram Business callback error', ['error' => $e->getMessage()]);

            return redirect()->route('filament.pages.connected-accounts')
                ->with('error', 'Failed to connect Instagram Business: ' . $e->getMessage());
        }
    }

    /**
     * Disconnect an Instagram account
     */
    public function disconnect(ConnectedAccount $account): JsonResponse
    {
        // Verify ownership
        if ($account->user_id !== Auth::id()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $account->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => "{$account->username} has been disconnected"
        ]);
    }

    /**
     * Refresh token for an account
     */
    public function refreshToken(ConnectedAccount $account): JsonResponse
    {
        if ($account->user_id !== Auth::id()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $this->syncService->refreshToken($account);

            return response()->json([
                'success' => true,
                'message' => 'Token refreshed successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Trigger manual sync for an account
     */
    public function sync(ConnectedAccount $account): JsonResponse
    {
        if ($account->user_id !== Auth::id()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $this->syncService->syncAccount($account);

            return response()->json([
                'success' => true,
                'message' => 'Synced successfully',
                'last_synced_at' => $account->fresh()->last_synced_at
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    // ========== Private Methods ==========

    private function redirectToInstagramBasic(): RedirectResponse
    {
        $clientId = config('services.instagram.client_id');
        $redirectUri = config('services.instagram.redirect');

        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'user_profile,user_media',
            'response_type' => 'code',
            'state' => $this->generateState(),
        ]);

        return redirect()->away("https://api.instagram.com/oauth/authorize?{$params}");
    }

    private function redirectToInstagramBusiness(): RedirectResponse
    {
        // For business features, we need to go through Meta
        $clientId = config('services.meta.app_id');
        $redirectUri = url('/auth/instagram-business/callback');

        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'instagram_basic,instagram_content_publish,instagram_manage_insights,pages_read_engagement',
            'response_type' => 'code',
            'state' => $this->generateState(),
        ]);

        return redirect()->away("https://www.facebook.com/v18.0/dialog/oauth?{$params}");
    }

    private function generateState(): string
    {
        $state = bin2hex(random_bytes(16));
        session(['instagram_oauth_state', $state]);

        return $state;
    }

    private function exchangeCodeForToken(string $code): ?array
    {
        $clientId = config('services.instagram.client_id');
        $clientSecret = config('services.instagram.client_secret');
        $redirectUri = config('services.instagram.redirect');

        $response = \Illuminate\Support\Facades\Http::asForm()->post('https://api.instagram.com/oauth/access_token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('Instagram token exchange failed', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return null;
    }

    private function getInstagramUser(string $accessToken): array
    {
        $response = \Illuminate\Support\Facades\Http::withToken($accessToken)
            ->get('https://graph.instagram.com/me', [
                'fields' => 'id,username,name,account_type,media_count,profile_picture_url',
            ]);

        if ($response->successful()) {
            return $response->json();
        }

        throw new \Exception('Failed to get Instagram user info');
    }

    private function determineAccountType(array $user): string
    {
        return match ($user['account_type'] ?? 'PERSONAL') {
            'BUSINESS' => 'business',
            'CREATOR' => 'creator',
            default => 'personal',
        };
    }
}