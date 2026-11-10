<?php

namespace App\Services;

use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Riwayat bulanan untuk analisa keuangan: bulan-bulan penuh yang sudah lewat,
 * ditambah bulan berjalan sampai hari ini (partial). Definisinya sama dengan
 * laporan bot keuangan:
 * - billed: invoice penjualan menurut tanggal invoice, sebesar yang ditagih
 *   (DP = nilai DP, pelunasan = sisa setelah DP), tanpa proforma dan yang batal;
 * - expenses: pengajuan expense menurut tanggal pengajuan, tanpa yang ditolak;
 * - cash_in / cash_out: transaksi kas yang dicatat di ERP, menurut tanggalnya.
 */
class TrendEngine
{
    public function __construct(private FinanceOverview $finance) {}

    /**
     * @return list<array{month: string, label: string, partial: bool, billed: float, expenses: float, cash_in: float, cash_out: float}>
     */
    public function getMonthlyHistory(int $months = 6, ?CarbonInterface $today = null): array
    {
        $today   = CarbonImmutable::parse($today ?? now())->startOfDay();
        $history = [];

        for ($i = $months; $i >= 0; $i--) {
            $start = $today->startOfMonth()->subMonthsNoOverflow($i);
            $end   = $i === 0 ? $today : $start->endOfMonth();

            $history[] = $this->month(ReportPeriod::between($start, $end), $i === 0);
        }

        return $history;
    }

    /**
     * Rata-rata per bulan dari $months bulan penuh terakhir (bulan berjalan
     * tidak ikut); null bila belum ada bulan penuh.
     */
    public static function runRate(array $history, string $key, int $months = 3): ?float
    {
        $full = array_slice(array_values(array_filter($history, fn (array $month) => ! $month['partial'])), -$months);

        return $full ? round(array_sum(array_column($full, $key)) / count($full), 2) : null;
    }

    private function month(ReportPeriod $period, bool $partial): array
    {
        $finance = $this->finance->forPeriod($period);

        return [
            'month'    => $period->from->format('Y-m'),
            'label'    => $period->from->locale('id')->translatedFormat('M Y'),
            'partial'  => $partial,
            'billed'   => round($finance->invoiceTotals()['total']['amount'], 2),
            'expenses' => round($finance->expenses()['total']['amount'], 2),
            'cash_in'  => self::cash($period, Transaction::TYPE_IN),
            'cash_out' => self::cash($period, Transaction::TYPE_OUT),
        ];
    }

    public static function cash(ReportPeriod $period, string $type, ?string $reference = null): float
    {
        return round((float) $period->apply(Transaction::query()->where('type', $type), 'transaction_date')
            ->when($reference, fn ($query) => $query->where('reference_type', $reference))
            ->sum('amount'), 2);
    }
}
