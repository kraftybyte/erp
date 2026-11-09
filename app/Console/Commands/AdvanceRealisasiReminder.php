<?php

namespace App\Console\Commands;

use App\Helpers\NotifHelper;
use App\Models\Advance;
use App\Models\Realisasi;
use App\Services\RealisasiService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Pengingat ke karyawan: advance yang sudah cair lebih dari 7 hari tapi
 * belum direalisasi penuh, dan tidak ada realisasi baru dalam 7 hari
 * terakhir. Satu pesan per karyawan (lonceng ERP + Telegram), lengkap dengan
 * ID advance untuk "realisasi #ID".
 */
class AdvanceRealisasiReminder extends Command
{
    protected $signature = 'advance:pengingat-realisasi {--dry-run : Tampilkan pesannya tanpa mengirim} {--days=7 : Hari sejak cair / realisasi terakhir}';

    protected $description = 'Ingatkan karyawan untuk merealisasi advance yang sudah cair';

    public function handle(RealisasiService $realisasi): int
    {
        $days  = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);

        $due = Advance::with('user')
            ->whereIn('status', [Advance::STATUS_DIBAYARKAN, Advance::STATUS_REALIZED_USER])
            ->where(fn ($query) => $query->where('paid_at', '<=', $since)->orWhere(fn ($query) => $query->whereNull('paid_at')->where('updated_at', '<=', $since)))
            ->get()
            ->filter(fn (Advance $advance) => $realisasi->getRemainingAmount($advance) >= 1
                && ! Realisasi::where('advance_id', $advance->id)->where('created_at', '>', $since)->exists());

        $sent = 0;

        foreach ($due->groupBy('user_id') as $advances) {
            $user = $advances->first()->user;

            if (! $user || ! $user->is_active) {
                continue;
            }

            $lines = $advances->map(fn (Advance $advance) => "• #{$advance->id} " . mb_strimwidth((string) $advance->keterangan_advance, 0, 60, '…')
                . ' — sisa Rp ' . number_format($realisasi->getRemainingAmount($advance), 0, ',', '.')
                . ' (cair ' . Carbon::parse($advance->paid_at ?? $advance->updated_at)->locale('id')->translatedFormat('j M') . ')');

            $body = 'Advance berikut belum direalisasi penuh:' . "\n" . $lines->implode("\n")
                . "\n\nKirim foto struk ke bot dengan keterangan \"realisasi #" . $advances->first()->id . '", atau kembalikan sisanya ke Finance.';

            if ($this->option('dry-run')) {
                $this->line("== {$user->firstname} {$user->lastname}\n{$body}\n");
            } else {
                NotifHelper::send($user, 'Pengingat realisasi advance', $body, 'heroicon-o-receipt-percent', 'warning', '/admin/advances', telegram: true);
            }

            $sent++;
        }

        $this->info("{$sent} karyawan diingatkan ({$due->count()} advance).");

        return self::SUCCESS;
    }
}
