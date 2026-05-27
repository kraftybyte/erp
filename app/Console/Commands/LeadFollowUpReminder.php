<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Leads;
use App\Models\User;
use Filament\Notifications\Notification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class LeadFollowUpReminder extends Command
{
    protected $signature = 'leads:follow-up-reminder {--days=3}';
    protected $description = 'Send reminders for leads needing follow-up';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $threshold = Carbon::now()->subDays($days);

        // Leads that haven't been contacted in X days and are still active
        $staleLeads = Leads::whereIn('status', ['Dalam Komunikasi', 'Proposal Sudah Dikirim'])
            ->where('updated_at', '<', $threshold)
            ->where('is_converted', false)
            ->with('product')
            ->get();

        if ($staleLeads->isEmpty()) {
            $this->info('No leads need follow-up.');
            return Command::SUCCESS;
        }

        $this->info("Found {$staleLeads->count()} lead(s) needing follow-up.");

        $sales = User::role(['Sales', 'Director', 'super_admin'])->get();

        foreach ($staleLeads as $lead) {
            $daysSinceUpdate = Carbon::now()->diffInDays($lead->updated_at);

            $notification = Notification::make()
                ->title('Lead Perlu Di-follow Up')
                ->body("Lead {$lead->nama_pic} ({$lead->nama_perusahaan}) belum di-update sejak {$daysSinceUpdate} hari. Status: {$lead->status}.")
                ->warning()
                ->send();

            foreach ($sales as $user) {
                $notification->sendToDatabase($user);
            }

            Log::info('Lead follow-up notification sent', [
                'lead_id' => $lead->id,
                'days_since_update' => $daysSinceUpdate,
                'recipients' => $sales->pluck('id')->toArray(),
            ]);
        }

        return Command::SUCCESS;
    }
}
