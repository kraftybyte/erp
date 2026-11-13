<?php

namespace Tests\Unit;

use App\Services\Telegram\ReceiptDate;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Hari ini 3 November 2026 di semua kasus.
 */
class ReceiptDateTest extends TestCase
{
    #[DataProvider('struk')]
    public function test_urutan_hari_bulan_di_struk(?string $tercetak, ?string $bacaanGemini, ?string $harapan): void
    {
        $this->assertSame($harapan, ReceiptDate::resolve($tercetak, $bacaanGemini, Carbon::create(2026, 11, 3)));
    }

    public static function struk(): array
    {
        return [
            'slip BCA bulan/hari, Gemini terbalik'      => ['11/03/26 14:22', '2026-03-11', '2026-11-03'],
            'slip BCA bulan/hari, Gemini benar'         => ['11/03/26', '2026-11-03', '2026-11-03'],
            'struk Indonesia hari/bulan, Gemini terbalik' => ['03/11/2026', '2026-03-11', '2026-11-03'],
            'kemarin, slip BCA'                         => ['11/02/26', '2026-02-11', '2026-11-02'],
            'akhir bulan lalu, slip BCA'                => ['10/05/26', '2026-05-10', '2026-10-05'],
            'hanya satu tafsiran yang valid'            => ['10/28/26', null, '2026-10-28'],
            'hari/bulan dengan titik'                   => ['28.10.26', '2026-10-28', '2026-10-28'],
            'nama bulan: bacaan Gemini dipakai'         => ['03 Nov 2026', '2026-11-03', '2026-11-03'],
            'tanggal dari pesan, bukan dari struk'      => ['11/03/26', '2026-11-01', '2026-11-01'],
            'tidak ada tanggal tercetak'                => [null, '2026-10-30', '2026-10-30'],
            'kedua tafsiran di masa depan'              => ['12/11/26', '2026-11-12', '2026-11-12'],
            'jam bukan tanggal'                         => ['14.22.01', '2026-11-03', '2026-11-03'],
        ];
    }

    #[DataProvider('urutan')]
    public function test_urutan_dari_jenis_struk_didahulukan(string $tercetak, ?string $urutan, string $harapan): void
    {
        $this->assertSame($harapan, ReceiptDate::resolve($tercetak, null, Carbon::create(2026, 11, 3), $urutan));
    }

    public static function urutan(): array
    {
        return [
            'struk restoran hari/bulan, bukan tafsiran terbaru' => ['10/09/26', 'dmy', '2026-09-10'],
            'slip EDC bulan/hari'                               => ['10/09/26', 'mdy', '2026-10-09'],
            'tanpa urutan: tafsiran terbaru'                     => ['10/09/26', null, '2026-10-09'],
            'urutan menghasilkan masa depan: aturan lama'       => ['10/11/26', 'dmy', '2026-10-11'],
        ];
    }
}
