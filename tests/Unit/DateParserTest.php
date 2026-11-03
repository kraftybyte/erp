<?php

namespace Tests\Unit;

use App\Services\Telegram\DateParser;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DateParserTest extends TestCase
{
    #[DataProvider('ketikan')]
    public function test_membaca_tanggal_yang_diketik(string $teks, ?string $harapan, string $hariIni = '2026-11-03'): void
    {
        $this->assertSame($harapan, DateParser::parse($teks, Carbon::parse($hariIni)));
    }

    public static function ketikan(): array
    {
        return [
            'hari/bulan'                     => ['3/11', '2026-11-03'],
            'hari/bulan/tahun'               => ['03/11/2026', '2026-11-03'],
            'tahun dua angka'                => ['1-11-26', '2026-11-01'],
            'nama bulan singkat'             => ['30 Okt', '2026-10-30'],
            'nama bulan lengkap dan tahun'   => ['30 oktober 2026', '2026-10-30'],
            'nama bulan Inggris'             => ['30 oct', '2026-10-30'],
            'format ISO'                     => ['2026-10-30', '2026-10-30'],
            'dengan kata tgl'                => ['tgl 1/11', '2026-11-01'],
            'hari ini'                       => ['hari ini', '2026-11-03'],
            'kemarin'                        => ['Kemarin', '2026-11-02'],
            'beberapa hari lalu'             => ['5 hari lalu', '2026-10-29'],
            'hanya tanggal, sudah lewat'     => ['2', '2026-11-02'],
            'hanya tanggal, bulan lalu'      => ['28', '2026-10-28'],
            'Desember diketik bulan Januari' => ['28/12', '2026-12-28', '2027-01-05'],
            'tanggal depan bulan ini'        => ['10/11', '2026-11-10'],
            'tanggal tidak ada'              => ['31/11', null],
            'bukan tanggal'                  => ['besok lusa', null],
            'bulan tidak dikenal'            => ['3 abc', null],
        ];
    }
}
