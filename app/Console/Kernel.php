<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule. Jam mengikuti APP_TIMEZONE
     * (Asia/Jakarta); butuh cron `schedule:run` setiap menit di server.
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('financial:analyze')
            ->weeklyOn(1, '09:00'); // Senin jam 09:00 pagi

        // Pengingat, lewat lonceng ERP dan Telegram.
        $schedule->command('ringkasan:pagi')->weekdays()->at('08:00');
        $schedule->command('deadline:reminder')->dailyAt('08:00');
        $schedule->command('leads:follow-up-reminder')->weekdays()->at('09:00');
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
