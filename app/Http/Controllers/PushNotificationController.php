<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PushNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PushNotificationController extends Controller
{
    protected PushNotificationService $pushService;

    public function __construct(PushNotificationService $pushService)
    {
        $this->pushService = $pushService;
    }

    /**
     * Get public VAPID key for client
     */
    public function getVapidPublicKey(): \Illuminate\Http\JsonResponse
    {
        $publicKey = $this->pushService->getPublicKey();

        if (!$publicKey) {
            return response()->json([
                'error' => 'Push notifications not configured',
            ], 503);
        }

        return response()->json([
            'publicKey' => $publicKey,
        ]);
    }

    /**
     * Subscribe user to push notifications
     */
    public function subscribe(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'endpoint' => 'required|url',
            'keys.p256dh' => 'required|string',
            'keys.auth' => 'required|string',
        ]);

        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $subscription = [
            'endpoint' => $request->endpoint,
            'keys' => [
                'p256dh' => $request->keys['p256dh'],
                'auth' => $request->keys['auth'],
            ],
        ];

        $success = $this->pushService->subscribe($user, $subscription);

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Subscribed to push notifications' : 'Failed to subscribe',
        ]);
    }

    /**
     * Unsubscribe user from push notifications
     */
    public function unsubscribe(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $success = $this->pushService->unsubscribe($user);

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Unsubscribed from push notifications' : 'Failed to unsubscribe',
        ]);
    }

    /**
     * Test push notification (for development)
     */
    public function test(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $success = $this->pushService->sendToUser(
            $user,
            '🔔 Test Notification',
            'Push notifications are working correctly!',
            ['url' => '/admin', 'tag' => 'test']
        );

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Test notification sent' : 'Failed to send notification',
        ]);
    }

    /**
     * Send notification to all users (admin only)
     */
    public function broadcast(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = auth()->user();
        if (!$user || !$user->hasRole('super_admin')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'title' => 'required|string|max:100',
            'body' => 'required|string|max:500',
            'url' => 'nullable|url',
        ]);

        $count = $this->pushService->sendToAll(
            $request->title,
            $request->body,
            ['url' => $request->url ?? '/admin']
        );

        return response()->json([
            'success' => true,
            'sent_to' => $count,
            'message' => "Notification sent to {$count} users",
        ]);
    }
}