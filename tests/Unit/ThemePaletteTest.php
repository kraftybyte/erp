<?php

namespace Tests\Unit;

use App\Helpers\ThemePalette;
use Filament\Support\Colors\Color;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ThemePaletteTest extends TestCase
{
    #[DataProvider('warnaTema')]
    public function test_teks_badge_terbaca_dan_warna_dasar_tetap(string $hex): void
    {
        $palette = ThemePalette::palette($hex);
        $rgb     = fn (string $value) => array_map('intval', explode(',', $value));

        $this->assertGreaterThanOrEqual(4.5, ThemePalette::contrast($rgb($palette[600]), $rgb($palette[50])));
        $this->assertSame(Color::hex($hex)[500], $palette[500]);

        // Urutan shade tetap: makin besar makin gelap.
        foreach ([[600, 700], [700, 800], [800, 900], [900, 950]] as [$lighter, $darker]) {
            $this->assertGreaterThan(
                ThemePalette::contrast($rgb($palette[$lighter]), [255, 255, 255]),
                ThemePalette::contrast($rgb($palette[$darker]), [255, 255, 255]),
                "shade {$darker} harus lebih gelap dari {$lighter}",
            );
        }
    }

    public static function warnaTema(): array
    {
        return [
            'warning pucat (dulu 1,6:1)' => ['#f5de8d'],
            'success (dulu 2,5:1)'       => ['#1DCB8A'],
            'danger'                     => ['#ff5467'],
            'primary sudah gelap'        => ['#3150AE'],
        ];
    }

    public function test_warna_yang_sudah_terbaca_tidak_diubah_dan_nilai_lain_diteruskan(): void
    {
        $theme = ThemePalette::readable(['primary' => '#3150AE', 'custom' => ['500' => '1, 2, 3']]);

        $this->assertSame(Color::hex('#3150AE')[600], $theme['primary'][600]);
        $this->assertSame(['500' => '1, 2, 3'], $theme['custom']);
    }
}
