<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PruneOldNotifications extends Command
{
    protected $signature = 'notifications:prune {--days=30}';
    protected $description = 'Delete old notifications from database';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $threshold = Carbon::now()->subDays($days);

        $table = config('notifications.database.table');

        if (!DB::table($table)->exists()) {
            $this->info("Notifications table not found or empty.");
            return Command::SUCCESS;
        }

        $deleted = DB::table($table)
            ->where('created_at', '<', $threshold)
            ->where('read_at', '<', $threshold)
            ->delete();

        $this->info("Deleted {$deleted} old notifications (older than {$days} days).");

        return Command::SUCCESS;
    }
}
