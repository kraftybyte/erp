<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use App\Models\FinancialAiReport;
use Illuminate\Support\Facades\Artisan;

class FinancialAnalyzer extends Widget
{
    protected static string $view = 'filament.widgets.financial-analyzer';

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';


    public ?FinancialAiReport $report = null;

    public static function canView(): bool
    {
        return true; // Enabled - AI Financial Analysis
    }


    public function mount(): void
    {
        $this->report = FinancialAiReport::latest()->first();
    }

    public function generate(): void
    {
        Artisan::call('financial:analyze');
        $this->report = FinancialAiReport::latest()->first();
    }

    protected function getViewData(): array
    {
        return [
            'report' => $this->report,
        ];
    }
}
