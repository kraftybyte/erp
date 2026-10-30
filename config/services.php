<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'wablas' => [
        'api_key' => env('WABLAS_API_KEY'),
    ],

    // Gemini & Telegram diatur di Settings → Integrasi (App\Settings\IntegrationSettings).
    // Nilai di bawah hanya cadangan bila isian di sana kosong.
    'gemini' => [
        'key'   => env('GEMINI_API_KEY'),
        // gemini-2.0-flash sudah dimatikan Google per 1 Juni 2026.
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
    ],

    'telegram' => [
        'bot_token'      => env('TELEGRAM_BOT_TOKEN'),
        // Dikirim Telegram di header X-Telegram-Bot-Api-Secret-Token pada setiap
        // webhook; request tanpa nilai ini ditolak.
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        // Bot laporan keuangan (terpisah dari bot pengajuan).
        'finance_bot_token'      => env('TELEGRAM_FINANCE_BOT_TOKEN'),
        'finance_webhook_secret' => env('TELEGRAM_FINANCE_WEBHOOK_SECRET'),
    ],

    'mailgun' => [
        'domain'   => env('MAILGUN_DOMAIN'),
        'secret'   => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme'   => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

];