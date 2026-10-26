<?php

namespace Tests\Unit;

use App\Services\ReportPeriod;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReportPeriodTest extends TestCase
{
    #[DataProvider('texts')]
    public function test_membaca_rentang_tanggal_dari_teks(string $text, ?string $from, ?string $to, ?string $label): void
    {
        $period = ReportPeriod::parse($text, Carbon::create(2026, 10, 26));

        if ($from === null) {
            $this->assertNull($period);

            return;
        }

        $this->assertSame(['from' => $from, 'to' => $to], $period->toArray());
        $this->assertSame($label, $period->label());
    }

    public static function texts(): array
    {
        return [
            'angka'                 => ['1/10/2026 - 31/10/2026', '2026-10-01', '2026-10-31', 'Oktober 2026'],
            'nama bulan tanpa tahun' => ['1 okt - 15 okt', '2026-10-01', '2026-10-15', '1 Okt – 15 Okt 2026'],
            'tanpa spasi'           => ['1/10-31/10', '2026-10-01', '2026-10-31', 'Oktober 2026'],
            'tahun dua digit'       => ['01-10-26 sd 15-10-26', '2026-10-01', '2026-10-15', '1 Okt – 15 Okt 2026'],
            'iso'                   => ['2026-10-01 2026-10-31', '2026-10-01', '2026-10-31', 'Oktober 2026'],
            'terbalik'              => ['31/10/2026 - 1/10/2026', '2026-10-01', '2026-10-31', 'Oktober 2026'],
            'lintas tahun'          => ['15 des 2025 - 10 jan', '2025-12-15', '2026-01-10', '15 Des 2025 – 10 Jan 2026'],
            'satu tanggal'          => ['26 oktober 2026', '2026-10-26', '2026-10-26', '26 Okt 2026'],
            'bulan saja'            => ['september', '2026-09-01', '2026-09-30', 'September 2026'],
            'tahun saja'            => ['2025', '2025-01-01', '2025-12-31', '2025'],
            'preset'                => ['bulan lalu', '2026-09-01', '2026-09-30', 'September 2026'],
            'tanggal mustahil'      => ['31/2/2026', null, null, null],
            'bukan tanggal'         => ['halo', null, null, null],
        ];
    }

    public function test_preset_dan_seluruh_waktu(): void
    {
        $today = Carbon::create(2026, 1, 15);

        $this->assertSame(['from' => '2025-12-01', 'to' => '2025-12-31'], ReportPeriod::preset('bulan-lalu', $today)->toArray());
        $this->assertSame(['from' => '2025-01-01', 'to' => '2025-12-31'], ReportPeriod::preset('tahun-lalu', $today)->toArray());
        $this->assertTrue(ReportPeriod::preset('semua', $today)->isAll());
        $this->assertSame('Semua waktu', ReportPeriod::fromArray(null)->label());
    }

    public function test_batas_periode_inklusif(): void
    {
        $period = ReportPeriod::parse('1/10/2026 - 31/10/2026');

        $this->assertTrue($period->contains('2026-10-01'));
        $this->assertTrue($period->contains('2026-10-31 23:59:59'));
        $this->assertFalse($period->contains('2026-11-01'));
        $this->assertFalse($period->contains(null));
        $this->assertTrue(ReportPeriod::all()->contains(null));
    }
}
