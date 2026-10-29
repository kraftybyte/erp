<?php

namespace Tests\Unit;

use App\Services\Telegram\Text;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TelegramTextTest extends TestCase
{
    #[DataProvider('teks')]
    public function test_dipotong_di_batas_kata(string $teks, int $batas, string $harapan): void
    {
        $this->assertSame($harapan, Text::short($teks, $batas));
    }

    public static function teks(): array
    {
        return [
            'muat'                  => ['Grab ke kantor klien', 30, 'Grab ke kantor klien'],
            'tidak di tengah kata'  => ['Lisa Anggraini - Universitas Harmoni Bangsa | Signage & Wayfinding', 60, 'Lisa Anggraini - Universitas Harmoni Bangsa | Signage…'],
            'tanda baca di ujung'   => ['Budi Santoso - PT Nestory Indonesia Sejahtera Abadi', 38, 'Budi Santoso - PT Nestory Indonesia…'],
            'spasi dirapikan'       => ["Sewa   lighting\ndan kamera", 40, 'Sewa lighting dan kamera'],
            'satu kata sangat panjang' => ['Supercalifragilisticexpialidocious', 10, 'Supercalif…'],
        ];
    }
}
