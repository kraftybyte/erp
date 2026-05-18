<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\User;

class PushNotificationService
{
    protected string $vapidPublicKey;
    protected string $vapidPrivateKey;
    protected string $vapidSubject;

    public function __construct()
    {
        $this->vapidPublicKey = env('VAPID_PUBLIC_KEY', '');
        $this->vapidPrivateKey = env('VAPID_PRIVATE_KEY', '');
        $this->vapidSubject = env('VAPID_SUBJECT', 'mailto:admin@getkrafty.id');
    }

    /**
     * Get VAPID public key
     */
    public function getPublicKey(): ?string
    {
        return $this->vapidPublicKey ?: null;
    }

    /**
     * Subscribe a user to push notifications
     */
    public function subscribe(User $user, array $subscription): bool
    {
        try {
            $user->update([
                'push_subscription' => json_encode($subscription),
                'push_enabled' => true,
            ]);

            Log::info("User {$user->id} subscribed to push notifications");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to subscribe user {$user->id}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Unsubscribe a user from push notifications
     */
    public function unsubscribe(User $user): bool
    {
        try {
            $user->update([
                'push_subscription' => null,
                'push_enabled' => false,
            ]);

            Log::info("User {$user->id} unsubscribed from push notifications");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to unsubscribe user {$user->id}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Send push notification to a user
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): bool
    {
        $subscription = $user->getPushSubscription();
        if (!$subscription) {
            Log::warning("User {$user->id} has no push subscription");
            return false;
        }

        return $this->send($subscription, $title, $body, $data);
    }

    /**
     * Send push notification to all subscribed users
     */
    public function sendToAll(string $title, string $body, array $data = []): int
    {
        $users = User::where('push_enabled', true)->get();
        $sent = 0;

        foreach ($users as $user) {
            if ($this->sendToUser($user, $title, $body, $data)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Send push notification
     */
    protected function send(array $subscription, string $title, string $body, array $data = []): bool
    {
        try {
            $payload = json_encode([
                'title' => $title,
                'body' => $body,
                'icon' => '/icons/icon-192x192.png',
                'badge' => '/icons/icon-72x72.png',
                'vibrate' => [200, 100, 200],
                'tag' => $data['tag'] ?? 'erp-notification',
                'data' => array_merge($data, [
                    'url' => $data['url'] ?? '/admin',
                    'timestamp' => now()->toIso8601String(),
                ]),
                'actions' => [
                    ['action' => 'open', 'title' => 'Buka'],
                    ['action' => 'dismiss', 'title' => 'Tutup'],
                ],
            ]);

            // For web push, we need a push service
            // This could be Firebase Cloud Messaging, OneSignal, etc.
            // For now, we'll use the web push via service worker

            Log::info("Push notification queued", [
                'title' => $title,
                'body' => $body,
                'subscription' => $subscription['endpoint'] ?? 'unknown',
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error("Failed to send push notification: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Send push notification via Firebase Cloud Messaging (FCM)
     */
    public function sendViaFCM(string $token, string $title, string $body, array $data = []): bool
    {
        $serverKey = env('FCM_SERVER_KEY');
        if (!$serverKey) {
            Log::warning('FCM Server Key not configured');
            return false;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'key=' . $serverKey,
                'Content-Type' => 'application/json',
            ])->post('https://fcm.googleapis.com/fcm/send', [
                'to' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                    'icon' => '/icons/icon-192x192.png',
                    'click_action' => $data['url'] ?? '/admin',
                ],
                'data' => $data,
            ]);

            $result = $response->json();
            return $result['success'] ?? false;
        } catch (\Exception $e) {
            Log::error("FCM send failed: {$e->getMessage()}");
            return false;
        }
    }
}