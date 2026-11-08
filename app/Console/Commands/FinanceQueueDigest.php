<?php

namespace App\Console\Commands;

use App\Models\Advance;
use App\Models\Expense;
use App\Services\RealisasiService;
use App\Services\SubmissionDecision;
use App\Services\Telegram\ManagementAlerts;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Ringkasan antrean harian untuk Director/Finance lewat bot laporan
 * keuangan: pengajuan menunggu persetujuan, realisasi menunggu verifikasi,
 * dan yang sudah disetujui tapi belum dibayar. Tombolnya membuka
 * /persetujuan dan /verifikasi. Tidak dikirim bila semuanya kosong.
 */
class FinanceQueueDigest extends Command
{
    protected $signature = 'finance:antrean {--dry-run : Tampilkan pesannya tanpa mengirim}';

    protected $description = 'Kirim ringkasan antrean persetujuan, verifikasi, dan pembayaran ke Director/Finance';

    public function handle(SubmissionDecision $decisions, RealisasiService $realisasi, ManagementAlerts $alerts): int
    {
        $pending  = $decisions->pending();
        $verify   = Advance::where('status', Advance::STATUS_REALIZED_USER)->get();
        $ready    = $verify->filter(fn (Advance $advance) => $realisasi->isReadyToClose($advance))->count();
        $overrun  = $verify->filter(fn (Advance $advance) => ! $advance->overrun_approved_by && $realisasi->getRemainingAmount($advance) < 0)->count();
        $expenses = Expense::where('status', 'Disetujui')->get();
        $advances = Advance::where('status', Advance::STATUS_DISETUJUI)->get();

        if ($pending->isEmpty() && $verify->isEmpty() && $expenses->isEmpty() && $advances->isEmpty()) {
            $this->info('Antrean kosong, tidak ada yang dikirim.');

            return self::SUCCESS;
        }

        $rupiah = fn ($amount) => 'Rp ' . number_format((float) $amount, 0, ',', '.');
        $oldest = $pending->first() ? (int) Carbon::parse($pending->first()->created_at)->startOfDay()->diffInDays(today()) : 0;

        $lines = [
            '☀️ <b>Antrean Finance hari ini</b> · ' . today()->locale('id')->translatedFormat('l, j M Y'),
            '',
            "📝 Menunggu persetujuan: <b>{$pending->count()}</b>" . ($pending->isNotEmpty() ? " · {$rupiah($pending->sum('amount'))}" . ($oldest > 0 ? " · terlama {$oldest} hari" : '') : ''),
            "🧾 Realisasi menunggu verifikasi: <b>{$verify->count()}</b>" . ($verify->isNotEmpty() ? " · ✅ siap selesai {$ready} · ⚠️ kelebihan {$overrun}" : ''),
            "💰 Disetujui, belum dibayar: <b>" . ($expenses->count() + $advances->count()) . '</b>'
                . ($expenses->count() + $advances->count() ? " · expense {$expenses->count()} ({$rupiah($expenses->sum('amount'))}) · advance {$advances->count()} ({$rupiah($advances->sum('amount'))})" : ''),
        ];

        $actions = array_values(array_filter([
            $pending->isNotEmpty() ? [['text' => "📝 Persetujuan ({$pending->count()})", 'callback_data' => 'apv:list:0']] : null,
            $verify->isNotEmpty() ? [['text' => "🧾 Verifikasi ({$verify->count()})", 'callback_data' => 'rlz:list:0']] : null,
        ]));

        if ($this->option('dry-run')) {
            $this->line(strip_tags(implode("\n", $lines)));

            return self::SUCCESS;
        }

        $sent = $alerts->toManagers(implode("\n", $lines), $actions, '/admin');
        $this->info("Dikirim ke {$sent} orang.");

        return self::SUCCESS;
    }
}
