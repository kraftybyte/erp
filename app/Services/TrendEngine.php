<?php


namespace App\Services;


use Carbon\Carbon;
use App\Models\Invoice;
use App\Models\Expense;
use App\Models\Advance;
use App\Models\Leads;
use App\Models\Deal;


class TrendEngine
{
public function getMonthlyHistory(int $months = 6): array
{
$now = Carbon::now();
$history = [];


for ($i = $months - 1; $i >= 0; $i--) {
$start = $now->copy()->subMonths($i)->startOfMonth();
$end = $start->copy()->endOfMonth();


$history[] = [
'month' => $start->format('Y-m'),
'revenue' => Invoice::whereBetween('created_at', [$start, $end])->sum('grand_total'),
'expenses' => Expense::whereBetween('created_at', [$start, $end])->sum('amount'),
'advances' => Advance::whereBetween('created_at', [$start, $end])->sum('amount'),
'leads' => Leads::whereBetween('created_at', [$start, $end])->count(),
'deals' => Deal::whereBetween('created_at', [$start, $end])->count(),
];
}


return $history;
}


public function forecastNextMonth(array $history, string $key): float
{
$values = collect($history)->pluck($key)->filter(fn($v) => $v > 0);
if ($values->count() < 2) return 0;


$growth = ($values->last() - $values->first()) / $values->first();
return round($values->last() * (1 + $growth), 2);
}
}