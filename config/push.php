<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Web Push Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Web Push Notifications using VAPID keys.
    | Generate VAPID keys using: php artisan webpush:generate-vapid-keys
    |
    */

    'vapid' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:admin@getkrafty.id'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging (FCM)
    |--------------------------------------------------------------------------
    |
    | Optional: Use Firebase for mobile push notifications.
    |
    */

    'fcm' => [
        'server_key' => env('FCM_SERVER_KEY'),
        'project_id' => env('FCM_PROJECT_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Settings
    |--------------------------------------------------------------------------
    */

    'notifications' => [
        // Enable push notifications globally
        'enabled' => env('PUSH_NOTIFICATIONS_ENABLED', true),

        // Default notification TTL in seconds
        'ttl' => env('PUSH_NOTIFICATION_TTL', 2419200), // 4 weeks

        // Enable badge updates
        'badge' => true,

        // Enable vibration
        'vibrate' => true,
    ],
];