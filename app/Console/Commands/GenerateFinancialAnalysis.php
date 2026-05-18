<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\FinancialAiReport;
use App\Services\MetricsEngine;
use App\Services\TrendEngine;
use App\Services\PromptEngine;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Log;

class GenerateFinancialAnalysis extends Command
{
    protected $signature = 'financial:analyze';
    protected $description = 'Generate AI business intelligence snapshot & forecast';

    public function handle()
    {
        try {
            // ================= ENGINE INIT =================
            $metricsEngine = new MetricsEngine();
            $trendEngine = new TrendEngine();
            $promptEngine = new PromptEngine();
            $ai = new GeminiService();

            // ================= DATA =================
            $metrics = $metricsEngine->generateSnapshot();
            $history = $trendEngine->getMonthlyHistory(6);

            $metrics['forecast'] = [
                'next_month' => [
                    'revenue' => $trendEngine->forecastNextMonth($history, 'revenue'),
                    'expenses' => $trendEngine->forecastNextMonth($history, 'expenses'),
                    'leads' => $trendEngine->forecastNextMonth($history, 'leads'),
                    'deals' => $trendEngine->forecastNextMonth($history, 'deals'),
                ],
                'history' => $history,
            ];

            // ================= PROMPT =================
            $prompt = $promptEngine->build($metrics, 'forecast');

            // ================= AI =================
            $rawAnalysis = $ai->analyze($prompt);

            // ================= CLEAN OUTPUT =================
            $analysis = $this->cleanAiOutput($rawAnalysis);

            // ================= SAVE =================
            FinancialAiReport::create([
                'analysis' => $analysis,
                'metrics' => json_encode($metrics, JSON_PRETTY_PRINT),
                'period_start' => $metrics['period']['start'],
                'period_end' => $metrics['period']['end'],
                'version' => 'v1-forecast',
            ]);

            $this->info('AI Business Intelligence Report generated successfully!');
        } catch (\Throwable $e) {
            Log::error('AI Financial Analysis Failed', [
                'error' => $e->getMessage(),
            ]);

            $this->error('Failed to generate AI report. Check logs.');
        }
    }

    /**
     * Bersihkan output AI:
     * - Potong semua teks sebelum STATUS:
     * - Pastikan maksimal 5 baris
     */
    protected function cleanAiOutput(string $text): string
    {
        $text = trim($text);

        // Potong sebelum STATUS:
        $text = preg_replace('/^.*?(STATUS:)/is', 'STATUS:', $text);

        // Batasi ke 5 baris
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $lines = array_slice($lines, 0, 5);

        return trim(implode("\n", $lines));
    }
}
