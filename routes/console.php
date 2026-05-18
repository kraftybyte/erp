<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Jobs\SyncMetaAdsJob;
use App\Jobs\SyncSocialAccountJob;
use App\Models\MetaAdAccount;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


// Sync Meta Ads setiap malam jam 02:00
Schedule::call(function () {
    MetaAdAccount::where('is_active', true)->each(function ($account) {
        SyncMetaAdsJob::dispatch($account, 'yesterday');
    });
})->dailyAt('02:00')->name('sync-meta-ads-daily');

// Sync data 30 hari setiap Senin jam 03:00
Schedule::call(function () {
    MetaAdAccount::where('is_active', true)->each(function ($account) {
        SyncMetaAdsJob::dispatch($account, 'last_30d');
    });
})->weeklyOn(1, '03:00')->name('sync-meta-ads-weekly');

// Sync Instagram & Facebook setiap malam jam 01:00
Schedule::command('instagram:sync-metrics --days=29')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/instagram-sync.log'));