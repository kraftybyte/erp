<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\PushNotificationService;

class PushNotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PushNotificationService::class, function ($app) {
            return new PushNotificationService();
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/push.php' => config_path('push.php'),
        ], 'push-config');
    }
}