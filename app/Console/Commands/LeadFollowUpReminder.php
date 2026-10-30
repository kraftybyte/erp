<?php

namespace App\Console\Commands;

use App\Helpers\NotifHelper;
use App\Models\Leads;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Satu pengingat per penerima (Sales, Director, super_admin) berisi lead aktif
 * yang tidak diperbarui X hari, lewat lonceng ERP dan Telegram. Dulu satu
 * notifikasi per lead per hari, sehingga pengingat penting tenggelam.
 */
class LeadFollowUpReminder extends Command
{
    protected $signature = 'leads:follow-up-reminder {--days=3}';
    protected $description = 'Send reminders for leads needing follow-up';

    private const LIST_LIMIT = 5;

    public function handle(): int
    {
        $days      = (int) $this->option('days');
        $threshold = Carbon::now()->subDays($days);

        // Leads that haven't been contacted in X days and are still active
        $staleLeads = Leads::whereIn('status', ['Dalam Komunikasi', 'Proposal Sudah Dikirim'])
            ->where('updated_at', '<', $threshold)
            ->where('is_converted', false)
            ->orderBy('updated_at')
            ->get();

        if ($staleLeads->isEmpty()) {
            $this->info('No leads need follow-up.');
            return Command::SUCCESS;
        }

        $this->info("Found {$staleLeads->count()} lead(s) needing follow-up.");

        $lines = $staleLeads->take(self::LIST_LIMIT)->map(function (Leads $lead) {
            $since = (int) Carbon::parse($lead->updated_at)->startOfDay()->diffInDays(Carbon::today());

            return "• {$lead->nama_pic} ({$lead->nama_perusahaan}) — {$lead->status}, {$since} hari tanpa update";
        });

        if ($staleLeads->count() > self::LIST_LIMIT) {
            $lines->push('…dan ' . ($staleLeads->count() - self::LIST_LIMIT) . ' lead lain.');
        }

        $sales = User::where('is_active', true)
            ->whereHas('roles', fn (Builder $query) => $query->whereIn('name', ['Sales', 'Director', 'super_admin']))
            ->get();

        NotifHelper::send(
            $sales,
            $staleLeads->count() . ' lead perlu di-follow up',
            $lines->implode("\n"),
            'heroicon-o-phone',
            'warning',
            '/admin/leads',
            telegram: true,
        );

        Log::info('Lead follow-up notification sent', [
            'lead_ids'   => $staleLeads->pluck('id')->all(),
            'recipients' => $sales->pluck('id')->all(),
        ]);

        return Command::SUCCESS;
    }
}
