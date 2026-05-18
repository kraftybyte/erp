<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(\Illuminate\Console\Scheduling\Schedule $schedule)
{
    $schedule->command('financial:analyze')
        ->weeklyOn(1, '09:00'); // Senin jam 09:00 pagi

    // Social media sync - every 6 hours
    $schedule->command('social:sync')
        ->everySixHours()
        ->withoutOverlapping()
        ->runInBackground();

    // Token refresh - daily
    $schedule->command('social:refresh-tokens')
        ->daily()
        ->withoutOverlapping();
}

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }

    
}
