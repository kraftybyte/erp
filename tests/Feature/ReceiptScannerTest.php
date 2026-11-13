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

    public function test_hasil_hitam_putih_bersih_jari_hilang_cap_tetap(): void
    {
        $image = imagecreatetruecolor(900, 1400);
        imagefill($image, 0, 0, imagecolorallocate($image, 60, 50, 45));
        $quad = [[200, 100], [700, 100], [700, 1300], [200, 1300]];
        imagefilledpolygon($image, array_merge(...$quad), imagecolorallocate($image, 238, 236, 230));
        $this->text($image, $quad);

        // Cap merah tua di tengah kertas, jari berkuteks di pinggir kanan.
        imagefilledrectangle($image, 380, 1150, 520, 1200, imagecolorallocate($image, 150, 20, 30));
        imagefilledellipse($image, 700, 300, 130, 80, imagecolorallocate($image, 180, 90, 100));

        $out = $this->scan($this->jpeg($image));
        $w   = imagesx($out);
        $h   = imagesy($out);
        $at  = fn (float $rx, float $ry) => imagecolorat($out, (int) ($rx * ($w - 1)), (int) ($ry * ($h - 1)));

        // Abu-abu murni (gaya scan hitam-putih), kertas putih bersih.
        foreach ([[0.5, 0.05], [0.9, 0.5], [0.3, 0.95]] as [$rx, $ry]) {
            $rgb = $at($rx, $ry);
            $this->assertSame([255, 255, 255], [($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255], "kertas {$rx},{$ry}");
        }

        $this->assertLessThan(120, $at(0.5, (1175 - 100) / 1200) & 0xFF, 'Cap berwarna di dalam kertas tetap terlihat.');
        $this->assertGreaterThan(230, $at(0.98, (300 - 100) / 1200) & 0xFF, 'Jari di pinggir kertas jadi putih.');
    }

    public function test_tinta_pudar_digelapkan_dan_celah_huruf_tetap_terbuka(): void
    {
        // Struk thermal pudar: garis tinta abu-abu muda 5 px dengan celah 3 px (seperti kaki huruf "m").
        $image = imagecreatetruecolor(900, 1400);
        imagefill($image, 0, 0, imagecolorallocate($image, 60, 50, 45));
        $quad = [[200, 100], [700, 100], [700, 1300], [200, 1300]];
        imagefilledpolygon($image, array_merge(...$quad), imagecolorallocate($image, 236, 234, 228));
        $ink = imagecolorallocate($image, 170, 170, 172);
        for ($x = 260; $x < 640; $x += 8) {
            imagefilledrectangle($image, $x, 600, $x + 4, 700, $ink);
        }

        $out = $this->scan($this->jpeg($image));
        $at  = fn (float $x, float $y) => $this->gray($out, (int) round(($x - 200) / 500 * (imagesx($out) - 1)), (int) round(($y - 100) / 1200 * (imagesy($out) - 1)));

        $strokes = array_map(fn ($x) => $at($x + 2, 650), range(300, 600, 8));
        $gaps    = array_map(fn ($x) => $at($x + 6, 650), range(300, 600, 8));

        $stroke = array_sum($strokes) / count($strokes);
        $gap    = array_sum($gaps) / count($gaps);

        // Ambang tetap yang lama: tinta pudar hampir hilang (garis 187, celah 219).
        $this->assertLessThan(110, $stroke, 'Tinta pudar jadi gelap seperti hasil scanner.');
        $this->assertGreaterThan(50, $gap - $stroke, 'Celah antar garis jelas lebih terang, huruf tidak menyatu.');
    }

    public function test_dokumen_berwarna_hanya_dipotong_dan_kontrasnya_naik(): void
    {
        // Invoice berwarna difoto miring di meja, agak redup: kop biru selebar
        // isi, logo merah, baris tulisan.
        $image = imagecreatetruecolor(1200, 1600);
        imagefill($image, 0, 0, imagecolorallocate($image, 70, 55, 45));
        $quad = [[230, 160], [990, 210], [960, 1460], [200, 1420]];
        imagefilledpolygon($image, array_merge(...$quad), imagecolorallocate($image, 205, 203, 198));

        $lerp = fn ($a, $b, $t) => [$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t];
        $at   = fn ($u, $v) => $lerp($lerp($quad[0], $quad[1], $u), $lerp($quad[3], $quad[2], $u), $v);
        $box  = fn ($u0, $v0, $u1, $v1) => array_merge($at($u0, $v0), $at($u1, $v0), $at($u1, $v1), $at($u0, $v1));

        imagefilledpolygon($image, $box(0.05, 0.05, 0.95, 0.15), imagecolorallocate($image, 40, 70, 150));
        imagefilledpolygon($image, $box(0.1, 0.2, 0.2, 0.28), imagecolorallocate($image, 170, 40, 40));
        for ($v = 0.35; $v < 0.9; $v += 0.05) {
            imagefilledpolygon($image, $box(0.1, $v, 0.8, $v + 0.015), imagecolorallocate($image, 50, 50, 55));
        }

        $out = $this->scan($this->jpeg($image));
        $pix = fn (float $u, float $v) => imagecolorat($out, (int) ($u * (imagesx($out) - 1)), (int) ($v * (imagesy($out) - 1)));

        // Utuh dari kop sampai bawah, tegak (±760 × 1250).
        $this->assertEqualsWithDelta(1250 / 760, imagesy($out) / imagesx($out), 0.15);

        // Warna tetap: kop biru, logo merah.
        $kop  = $pix(0.5, 0.1);
        $logo = $pix(0.15, 0.24);
        $this->assertGreaterThan(80, ($kop & 0xFF) - (($kop >> 16) & 0xFF), 'Kop tetap biru.');
        $this->assertGreaterThan(80, (($logo >> 16) & 0xFF) - ($logo & 0xFF), 'Logo tetap merah.');

        // Kontras naik: kertas redup (205) jadi hampir putih.
        $this->assertGreaterThan(235, $pix(0.9, 0.95) & 0xFF);
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

    public function test_screenshot_dan_bukti_digital_disimpan_apa_adanya(): void
    {
        $scanner = app(ReceiptScanner::class);

        // Dikirim lewat Telegram sebagai foto: JPEG, tanpa noise kamera.
        $jpeg = $this->jpeg($this->screenshot(), camera: false);
        $this->assertNull($scanner->scan($jpeg), 'Screenshot tidak diolah seperti foto kertas.');

        $stored = $scanner->store($jpeg, 'image/jpeg', 'realisasi/tes');
        $this->assertFalse($stored['scanned']);
        $this->assertSame($jpeg, Storage::disk('public')->get($stored['path']), 'Disimpan persis seperti dikirim.');
        $this->assertNull(ReceiptScanner::original($stored['path']));

        // PNG (dikirim sebagai file) juga.
        ob_start();
        imagepng($this->screenshot());
        $this->assertNull($scanner->scan((string) ob_get_clean()));

        // Foto kamera kertas yang memenuhi gambar tetap diolah.
        $this->assertFalse($scanner->isDigital(imagecreatefromstring($this->photo(900, 1200, [[0, 0], [899, 0], [899, 1199], [0, 1199]]))));
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

    /**
     * Foto uji diberi noise sensor seperti foto kamera; tanpa noise, warna yang
     * rata sempurna membuatnya dikenali sebagai screenshot (tidak diolah).
     */
    private function jpeg(GdImage $image, bool $camera = true): string
    {
        if ($camera) {
            mt_srand(7);
            $tile = imagecreatetruecolor(61, 59);

            for ($y = 0; $y < 59; $y++) {
                for ($x = 0; $x < 61; $x++) {
                    imagesetpixel($tile, $x, $y, (mt_rand(0, 255) << 16) | (mt_rand(0, 255) << 8) | mt_rand(0, 255));
                }
            }

            for ($y = 0; $y < imagesy($image); $y += 59) {
                for ($x = 0; $x < imagesx($image); $x += 61) {
                    imagecopymerge($image, $tile, $x, $y, 0, 0, 61, 59, 5);
                }
            }
        }

        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    /**
     * Screenshot aplikasi (seperti detail order ojek/kurir): latar putih, kartu
     * abu-abu muda, teks abu-abu, foto kecil, dan tombol merah.
     */
    private function screenshot(): GdImage
    {
        $image = imagecreatetruecolor(720, 1560);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 0, 0, 719, 60, imagecolorallocate($image, 250, 250, 250));
        imagefilledrectangle($image, 30, 400, 690, 900, imagecolorallocate($image, 245, 246, 247));
        imagefilledrectangle($image, 90, 520, 650, 620, imagecolorallocate($image, 229, 245, 244));
        imagefilledrectangle($image, 30, 1440, 690, 1520, imagecolorallocate($image, 230, 30, 40));

        $dark = imagecolorallocate($image, 20, 20, 20);
        $gray = imagecolorallocate($image, 140, 140, 140);
        for ($y = 120; $y < 1400; $y += 46) {
            imagestring($image, 5, 60, $y, 'CV Contoh Jaya - Rp281.663 - Hari ini, 13:20', $y % 92 ? $gray : $dark);
        }

        // Foto kecil di dalam screenshot.
        mt_srand(5);
        for ($y = 530; $y < 610; $y++) {
            for ($x = 100; $x < 180; $x++) {
                imagesetpixel($image, $x, $y, mt_rand(0, 0xFFFFFF));
            }
        }

        return $image;
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
