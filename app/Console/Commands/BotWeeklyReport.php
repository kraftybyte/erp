<?php

namespace App\Console\Commands;

use App\Models\BankMutation;
use App\Models\Realisasi;
use App\Services\Telegram\ManagementAlerts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Laporan mingguan bot untuk Director/Finance/super_admin: pemakaian (struk,
 * realisasi, mutasi), pemakaian dan perkiraan biaya Gemini, serta jumlah
 * error aplikasi 7 hari terakhir.
 */
class BotWeeklyReport extends Command
{
    protected $signature = 'bot:laporan-mingguan {--dry-run : Tampilkan pesannya tanpa mengirim} {--days=7}';

    protected $description = 'Kirim laporan mingguan pemakaian bot dan biaya Gemini';

    public function handle(ManagementAlerts $alerts): int
    {
        $days  = max(1, (int) $this->option('days'));
        $from  = today()->subDays($days);
        $until = today()->subDay();
        $num   = fn ($value) => number_format((float) $value, 0, ',', '.');

        $receipts = DB::table('telegram_receipts')->where('created_at', '>=', $from)->get(['scope', 'user_id']);
        $batches  = Realisasi::with('items')->where('notes', 'Dikirim lewat bot Telegram')->where('created_at', '>=', $from)->get();
        $usage    = Schema::hasTable('ai_usages')
            ? DB::table('ai_usages')->whereBetween('date', [$from->toDateString(), $until->toDateString()])
                ->selectRaw('COALESCE(SUM(calls),0) calls, COALESCE(SUM(failures),0) failures, COALESCE(SUM(input_tokens),0) input, COALESCE(SUM(output_tokens),0) output')->first()
            : null;
        $cost = $usage
            ? ($usage->input * (float) config('services.gemini.price_input_per_million') + $usage->output * (float) config('services.gemini.price_output_per_million')) / 1_000_000
            : 0;

        $errors = Schema::hasTable('filament_exceptions_table')
            ? DB::table('filament_exceptions_table')->where('created_at', '>=', $from)->selectRaw('type, COUNT(*) n')->groupBy('type')->orderByDesc('n')->get()
            : collect();

        $lines = [
            '📊 <b>Laporan mingguan bot</b> · ' . $from->locale('id')->translatedFormat('j M') . ' – ' . $until->locale('id')->translatedFormat('j M Y'),
            '',
            '<b>Pemakaian</b>',
            '• Struk masuk lewat bot: ' . $receipts->count() . ' (pengajuan ' . $receipts->where('scope', 'pengajuan')->count()
                . ' · realisasi ' . $receipts->filter(fn ($row) => str_starts_with((string) $row->scope, 'realisasi:'))->count() . ') dari ' . $receipts->pluck('user_id')->unique()->count() . ' orang',
            '• Realisasi lewat bot: ' . $batches->count() . ' batch · Rp ' . $num($batches->sum(fn (Realisasi $batch) => $batch->items->sum('subtotal'))),
            '• Mutasi rekening dicatat: ' . (Schema::hasTable('bank_mutations') ? BankMutation::where('created_at', '>=', $from)->count() : 0),
            '',
            '<b>Gemini</b> (baca struk, pesan suara, mutasi PDF/foto)',
            $usage
                ? '• ' . $num($usage->calls) . ' panggilan · ' . $num($usage->failures) . ' gagal · ' . $num($usage->input) . ' token masuk · ' . $num($usage->output) . ' token keluar'
                    . "\n• Perkiraan biaya: US$" . number_format($cost, 2, ',', '.') . ' <i>(harga per token di config/services.php)</i>'
                : '• Belum tercatat.',
            '',
            '<b>Error aplikasi</b>: ' . $errors->sum('n')
                . ($errors->isNotEmpty() ? ' · terbanyak: ' . $errors->take(3)->map(fn ($row) => e(class_basename((string) $row->type)) . " ({$row->n})")->implode(', ') : ''),
        ];

        if ($this->option('dry-run')) {
            $this->line(strip_tags(implode("\n", $lines)));

            return self::SUCCESS;
        }

        $this->info('Dikirim ke ' . $alerts->toManagers(implode("\n", $lines), [], '/admin') . ' orang.');

        return self::SUCCESS;
    }
}
