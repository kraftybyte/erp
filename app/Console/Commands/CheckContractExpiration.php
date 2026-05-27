<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Contract;
use App\Models\User;
use Filament\Notifications\Notification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CheckContractExpiration extends Command
{
    protected $signature = 'contracts:check-expiration {--days=30}';
    protected $description = 'Check for expiring contracts and send notifications';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $threshold = Carbon::now()->addDays($days);

        $expiringContracts = Contract::where('status', '!=', 'Ditolak')
            ->where('end_date', '<=', $threshold)
            ->where('end_date', '>=', Carbon::now())
            ->with(['deal', 'user'])
            ->get();

        if ($expiringContracts->isEmpty()) {
            $this->info('No contracts expiring soon.');
            return Command::SUCCESS;
        }

        $this->info("Found {$expiringContracts->count()} contract(s) expiring within {$days} days.");

        $directors = User::role(['Director', 'super_admin'])->get();
        $finance = User::role(['Finance'])->get();
        $recipients = $directors->merge($finance)->unique();

        foreach ($expiringContracts as $contract) {
            $daysLeft = Carbon::now()->diffInDays($contract->end_date, false);

            $notification = Notification::make()
                ->title('Kontrak Segera Berakhir')
                ->body("Kontrak {$contract->contract_number} (" . ($contract->deal->nama_perusahaan ?? 'Unknown') . ") akan berakhir dalam {$daysLeft} hari ({$contract->end_date->format('d M Y')}).")
                ->warning()
                ->send();

            foreach ($recipients as $user) {
                $notification->sendToDatabase($user);
            }

            Log::info('Contract expiration notification sent', [
                'contract_id' => $contract->id,
                'contract_number' => $contract->contract_number,
                'days_left' => $daysLeft,
                'recipients' => $recipients->pluck('id')->toArray(),
            ]);
        }

        return Command::SUCCESS;
    }
}
