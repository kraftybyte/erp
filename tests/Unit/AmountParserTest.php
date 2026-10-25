<?php

namespace Tests\Unit;

use App\Services\Telegram\AmountParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AmountParserTest extends TestCase
{
    #[DataProvider('nominal')]
    public function test_membaca_nominal_dari_teks(string $teks, ?int $harapan): void
    {
        $this->assertSame($harapan, AmountParser::parse($teks));
    }

    public static function nominal(): array
    {
        return [
            'ribuan singkat'           => ['grab 150rb', 150_000],
            'ribu ditulis lengkap'     => ['parkir 15 ribu', 15_000],
            'k'                        => ['kopi klien 85k', 85_000],
            'juta desimal koma'        => ['advance 1,5jt props', 1_500_000],
            'juta desimal titik'       => ['2.5 juta sewa alat', 2_500_000],
            'rupiah bertitik'          => ['Rp 150.000 bensin', 150_000],
            'rupiah menempel'          => ['bayar Rp150.000', 150_000],
            'rupiah dengan sen'        => ['Rp 1.250.000,00 hosting', 1_250_000],
            'angka polos'              => ['beli kabel 85000', 85_000],
            'pemisah koma ribuan'      => ['1,500,000 sewa studio', 1_500_000],
            'bersatuan didahulukan'    => ['2 orang makan 300rb', 300_000],
            'tanpa nominal'            => ['grab ke kantor klien', null],
            'angka kecil tanpa satuan' => ['2 orang makan', null],
            'tahun bukan nominal'      => ['shooting 25 okt 2026', null],
            'angka menempel di kata'   => ['beli casing iPhone15', null],
        ];
    }

    public function test_strip_menyisakan_keterangan(): void
    {
        $sisa = trim(preg_replace('/\s+/', ' ', AmountParser::strip('grab Rp 150.000 ke kantor klien')));

        $this->assertSame('grab ke kantor klien', $sisa);
    }
}
