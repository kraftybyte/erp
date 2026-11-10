<?php

namespace App\Console\Commands;

use App\Models\FinancialAiReport;
use App\Services\FinanceAdvisor;
use App\Services\Telegram\ManagementAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Analisa keuangan mingguan (Senin 09:00, lihat Kernel): disimpan di
 * financial_ai_reports dan dikirim ke Director/Finance/super_admin lewat bot
 * keuangan. Isinya sama dengan /analisa di bot.
 */
class GenerateFinancialAnalysis extends Command
{
    protected $signature = 'financial:analyze
        {--dry-run : Tampilkan analisanya tanpa menyimpan dan tanpa mengirim}
        {--no-telegram : Simpan saja, tanpa mengirim ke Telegram}';

    protected $description = 'Analisa keuangan: fakta dari ERP, prioritas dari AI yang diperiksa ulang';

    public function handle(FinanceAdvisor $advisor, ManagementAlerts $alerts): int
    {
        $analysis = $advisor->analyze();
        $message  = $advisor->message($analysis);

        if ($this->option('dry-run')) {
            $this->line(html_entity_decode(strip_tags($message), ENT_QUOTES));

            return self::SUCCESS;
        }

        $today = CarbonImmutable::parse($analysis['facts']['period']['today']);

        FinancialAiReport::create([
            'analysis'     => FinanceAdvisor::plainSummary($analysis),
            'metrics'      => $analysis,
            'period_start' => $today->startOfMonth()->toDateString(),
            'period_end'   => $today->toDateString(),
            'version'      => 'v2-advisor',
        ]);

        if ($analysis['ai_error']) {
            $this->warn("AI tidak dipakai: {$analysis['ai_error']}. Prioritas dari aturan sistem.");
        }

        if ($this->option('no-telegram')) {
            $this->info('Analisa disimpan.');

            return self::SUCCESS;
        }

        $this->info('Analisa disimpan dan dikirim ke ' . $alerts->toManagers($message) . ' orang.');

        return self::SUCCESS;
    }
}
