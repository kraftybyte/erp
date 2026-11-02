<?php

namespace Tests\Feature;

use App\Services\ReceiptScanner;
use App\Settings\IntegrationSettings;
use GdImage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Foto struk dibuat seperti hasil scan: dipotong ke kertasnya, diluruskan,
 * dan dirapikan. Foto uji dibuat sendiri: kertas terang miring di meja gelap.
 */
class ReceiptScannerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        // Tanpa Gemini kecuali tes yang memintanya (kosong juga di cadangan .env).
        IntegrationSettings::fake(['gemini_api_key' => null]);
        config(['services.gemini.key' => null]);
    }

    public function test_struk_miring_di_meja_dipotong_dan_diluruskan(): void
    {
        $out = $this->scan($this->photo(1200, 1600, [[280, 210], [940, 300], [870, 1440], [225, 1370]]));

        // Kertas sekitar 680 × 1150: hasilnya tegak memanjang, bukan seukuran foto.
        $this->assertEqualsWithDelta(1150 / 680, imagesy($out) / imagesx($out), 0.12);

        // Meja gelap terbuang: keempat pojok hasil putih seperti kertas.
        foreach ([[5, 5], [imagesx($out) - 6, 5], [5, imagesy($out) - 6], [imagesx($out) - 6, imagesy($out) - 6]] as [$x, $y]) {
            $this->assertGreaterThan(200, $this->gray($out, $x, $y), "pojok {$x},{$y}");
        }

        // Tulisan tetap ada dan tegas.
        $this->assertGreaterThan(0.01, $this->darkShare($out));
    }

    public function test_struk_dipegang_di_depan_dinding_terang_tidak_terpotong(): void
    {
        // Seperti foto struk Alfamart: dinding terang di atas, printer gelap di
        // tengah, meja terang di kiri bawah, jari di pinggir kertas.
        $image = imagecreatetruecolor(800, 1400);
        imagefill($image, 0, 0, imagecolorallocate($image, 225, 225, 228));
        imagefilledrectangle($image, 60, 300, 760, 1000, imagecolorallocate($image, 30, 32, 40));
        imagefilledrectangle($image, 500, 260, 760, 420, imagecolorallocate($image, 30, 60, 160));

        $quad = [[230, 140], [620, 140], [650, 1380], [240, 1380]];
        imagefilledpolygon($image, array_merge(...$quad), imagecolorallocate($image, 246, 246, 244));
        $this->text($image, $quad);

        $skin = imagecolorallocate($image, 225, 160, 140);
        imagefilledellipse($image, 640, 200, 150, 90, $skin);
        imagefilledellipse($image, 200, 1330, 170, 110, $skin);

        $out = $this->scan($this->jpeg($image));

        // Panjang struk utuh (±1240 × 400), bukan berhenti di batas printer.
        $this->assertEqualsWithDelta(1240 / 400, imagesy($out) / imagesx($out), 0.35);
        $this->assertGreaterThan(200, $this->gray($out, intdiv(imagesx($out), 2), imagesy($out) - 10), 'Bagian bawah kertas ikut.');
    }

    public function test_kertas_yang_memenuhi_foto_tidak_dipotong(): void
    {
        $image = imagecreatetruecolor(900, 1200);
        imagefill($image, 0, 0, imagecolorallocate($image, 235, 233, 228));
        $this->text($image, [[0, 0], [899, 0], [899, 1199], [0, 1199]]);

        $out = $this->scan($this->jpeg($image));

        // Tidak dipotong (perbandingan sisi sama), hanya diperbesar halus sampai 1600 px.
        $this->assertSame([1200, 1600], [imagesx($out), imagesy($out)]);
        $this->assertGreaterThan(0.01, $this->darkShare($out));
    }

    public function test_sudut_dari_gemini_bila_kertasnya_tidak_terdeteksi(): void
    {
        // Latar lebih terang dari kertas: pencarian bagian terang menyerah.
        $image = imagecreatetruecolor(1000, 1000);
        imagefill($image, 0, 0, imagecolorallocate($image, 250, 250, 250));
        imagefilledpolygon($image, [200, 100, 800, 100, 800, 900, 200, 900], imagecolorallocate($image, 200, 200, 195));

        IntegrationSettings::fake(['gemini_api_key' => 'gemini-tes', 'gemini_model' => 'gemini-3.5-flash-lite']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [[
            'text' => json_encode(['kiri_atas' => [200, 100], 'kanan_atas' => [800, 100], 'kanan_bawah' => [800, 900], 'kiri_bawah' => [200, 900]]),
        ]]]]]])]);

        $out = $this->scan($this->jpeg($image));

        $this->assertEqualsWithDelta(800 / 600, imagesy($out) / imagesx($out), 0.05);
        Http::assertSentCount(1);
    }

    public function test_tanpa_gemini_foto_yang_kertasnya_tidak_ketemu_disimpan_apa_adanya(): void
    {
        // Meja kayu tanpa kertas: lebih baik foto asli daripada olahan yang rusak.
        $image = imagecreatetruecolor(1000, 800);
        imagefill($image, 0, 0, imagecolorallocate($image, 150, 105, 70));
        Http::fake();

        $scanner = app(ReceiptScanner::class);
        $this->assertNull($scanner->scan($jpeg = $this->jpeg($image)));

        $stored = $scanner->store($jpeg, 'image/jpeg', 'expense/invoice/tes');
        $this->assertFalse($stored['scanned']);
        $this->assertSame($jpeg, Storage::disk('public')->get($stored['path']));
        Http::assertNothingSent();
    }

    public function test_foto_asli_disimpan_dan_ikut_terhapus(): void
    {
        $stored = app(ReceiptScanner::class)->store($this->photo(800, 1000, [[150, 120], [650, 160], [620, 880], [130, 850]]), 'image/jpeg', 'expense/invoice/tes');

        $this->assertTrue($stored['scanned']);
        $this->assertSame('image/jpeg', $stored['mime']);
        $this->assertStringEndsWith('.jpg', $stored['path']);
        Storage::disk('public')->assertExists($stored['path']);

        $original = ReceiptScanner::original($stored['path']);
        $this->assertSame(substr($stored['path'], 0, -4) . '-asli.jpg', $original);

        ReceiptScanner::delete($stored['path']);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_pdf_dan_file_rusak_disimpan_apa_adanya(): void
    {
        $scanner = app(ReceiptScanner::class);
        $pdf     = $scanner->store("%PDF-1.4\n%%EOF", 'application/pdf', 'expense/invoice/tes');

        $this->assertFalse($pdf['scanned']);
        $this->assertStringEndsWith('.pdf', $pdf['path']);
        $this->assertSame("%PDF-1.4\n%%EOF", Storage::disk('public')->get($pdf['path']));
        $this->assertNull(ReceiptScanner::original($pdf['path']));

        $this->assertNull($scanner->scan("\xFF\xD8\xFF\xE0bukan-jpeg"));
    }

    // =================================================================
    // Foto uji
    // =================================================================

    /**
     * Meja gelap bertekstur dengan kertas terang di $quad (kiri atas, kanan
     * atas, kanan bawah, kiri bawah) berisi baris tulisan dan bayangan.
     */
    private function photo(int $w, int $h, array $quad): string
    {
        $image = imagecreatetruecolor($w, $h);
        mt_srand(3);

        for ($y = 0; $y < $h; $y += 4) {
            for ($x = 0; $x < $w; $x += 4) {
                $v = 55 + mt_rand(0, 30);
                imagefilledrectangle($image, $x, $y, $x + 3, $y + 3, imagecolorallocate($image, $v + 15, $v + 8, $v));
            }
        }

        imagefilledpolygon($image, array_merge(...$quad), imagecolorallocate($image, 236, 234, 228));
        $this->text($image, $quad);

        // Bayangan lembut di sepertiga bawah.
        for ($y = (int) ($h * 0.66); $y < $h; $y += 2) {
            for ($x = 0; $x < $w; $x += 2) {
                $rgb = imagecolorat($image, $x, $y);
                $f   = 0.8;
                imagefilledrectangle($image, $x, $y, $x + 1, $y + 1, imagecolorallocate($image, (int) ((($rgb >> 16) & 255) * $f), (int) ((($rgb >> 8) & 255) * $f), (int) (($rgb & 255) * $f)));
            }
        }

        return $this->jpeg($image);
    }

    private function text(GdImage $image, array $quad): void
    {
        $lerp  = fn ($a, $b, $t) => [$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t];
        $black = imagecolorallocate($image, 30, 30, 35);

        for ($row = 0.1; $row < 0.9; $row += 0.06) {
            $left  = $lerp($quad[0], $quad[3], $row);
            $right = $lerp($quad[1], $quad[2], $row);

            for ($t = 0.1; $t < 0.75; $t += 0.02) {
                [$x, $y] = $lerp($left, $right, $t);
                imagefilledellipse($image, (int) $x, (int) $y, 8, 12, $black);
            }
        }
    }

    private function jpeg(GdImage $image): string
    {
        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    private function scan(string $jpeg): GdImage
    {
        $out = app(ReceiptScanner::class)->scan($jpeg);
        $this->assertNotNull($out, 'Foto bisa diolah.');

        return imagecreatefromstring($out);
    }

    private function gray(GdImage $image, int $x, int $y): int
    {
        return imagecolorat($image, $x, $y) & 0xFF;
    }

    private function darkShare(GdImage $image): float
    {
        $dark  = 0;
        $total = 0;

        for ($y = 0; $y < imagesy($image); $y += 3) {
            for ($x = 0; $x < imagesx($image); $x += 3) {
                $total++;
                $dark += $this->gray($image, $x, $y) < 100 ? 1 : 0;
            }
        }

        return $dark / $total;
    }
}
