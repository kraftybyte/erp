<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Invoice;
use App\Models\User;
use Filament\Notifications\Notification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CheckOverdueInvoices extends Command
{
    protected $signature = 'invoices:check-overdue {--days=7}';
    protected $description = 'Check for overdue invoices and send reminders';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $threshold = Carbon::now()->subDays($days);

        $overdueInvoices = Invoice::where('status', '!=', 'Lunas')
            ->where('status', '!=', 'Ditolak')
            ->where('due_date', '<', $threshold)
            ->with(['quotation.deal'])
            ->get();

        if ($overdueInvoices->isEmpty()) {
            $this->info('No overdue invoices found.');
            return Command::SUCCESS;
        }

        $this->info("Found {$overdueInvoices->count()} overdue invoice(s).");

        $directors = User::role(['Director', 'super_admin'])->get();
        $finance = User::role(['Finance'])->get();
        $recipients = $directors->merge($finance)->unique();

        foreach ($overdueInvoices as $invoice) {
            $daysOverdue = Carbon::now()->diffInDays($invoice->due_date);

            $notification = Notification::make()
                ->title('Invoice Jatuh Tempo')
                ->body("Invoice #{$invoice->invoice_number} (" . ($invoice->quotation->deal->nama_perusahaan ?? 'Unknown') . ") sudah melampaui batas {$days} hari ({$invoice->due_date->format('d M Y')}).")
                ->danger()
                ->send();

            foreach ($recipients as $utilisateur) {
                $notification->sendToDatabase($utilisateur);
            }

            Log::info('Invoice overdue notification sent', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'days_overdue' => $daysOverdue,
                'recipients' => $recipients->pluck('id')->toArray(),
            ]);
        }

        return Command::SUCCESS;
    }
}
