<?php

namespace App\Http\Controllers;

use App\Services\FakturCollector;
use App\Services\ReportPeriod;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Unduhan halaman Faktur. Lewat route biasa, bukan aksi Livewire: Livewire
 * mengirim file sebagai base64 di dalam JSON, berat untuk ZIP berisi struk
 * sebulan.
 */
class FakturController extends Controller
{
    public function excel(FakturCollector $faktur, string $bulan): BinaryFileResponse
    {
        abort_unless(FakturCollector::visibleTo(auth()->user()), 403);

        return response()
            ->download($faktur->excel(self::period($bulan)), "faktur-{$bulan}.xlsx")
            ->deleteFileAfterSend();
    }

    public function zip(FakturCollector $faktur, string $bulan): BinaryFileResponse
    {
        abort_unless(FakturCollector::visibleTo(auth()->user()), 403);

        // Struk sebulan bisa ratusan file.
        set_time_limit(300);

        return response()
            ->download($faktur->zip(self::period($bulan), "daftar-faktur-{$bulan}.xlsx"), "faktur-masukan-{$bulan}.zip")
            ->deleteFileAfterSend();
    }

    /**
     * Satu bulan dari "2026-10".
     */
    public static function period(string $bulan): ReportPeriod
    {
        $start = Carbon::createFromFormat('!Y-m', $bulan);

        return ReportPeriod::between($start->copy()->startOfMonth(), $start->copy()->endOfMonth());
    }
}
