<?php

namespace App\Services;

use GdImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Foto struk/nota dibuat seperti hasil scan (ala CamScanner), otomatis:
 * 1. rotasi EXIF dari HP diterapkan;
 * 2. kertas struk dicari (bagian terang terbesar di foto; bila gagal dan
 *    Gemini aktif, keempat sudutnya ditanyakan ke Gemini) lalu diluruskan
 *    dari perspektif miring;
 * 3. bayangan diratakan, kertas dibuat putih, tulisan dipertegas.
 *
 * Pikselnya tetap dari foto asli, tidak digambar ulang AI, dan foto aslinya
 * ikut disimpan di sebelahnya dengan akhiran "-asli". PDF tidak diubah.
 * Hanya GD (pasti ada di hosting), tanpa Imagick.
 */
class ReceiptScanner
{
    private const TYPES = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];

    // Sisi panjang hasil scan, dan foto yang diolah (lebih besar dikecilkan dulu).
    private const MAX_SIDE  = 1600;
    private const WORK_SIDE = 2400;

    // Ukuran kerja untuk mencari kertas.
    private const DETECT_SIDE = 400;

    public function __construct(private GeminiService $gemini) {}

    /**
     * Simpan struk ke disk public: hasil scan (JPG) bila fotonya bisa diolah,
     * selain itu file apa adanya.
     *
     * @return array{path: string, mime: string, size: int, scanned: bool}
     */
    public function store(string $data, string $mime, string $folder): array
    {
        $base = rtrim($folder, '/') . '/' . Str::uuid();
        $scan = $mime !== 'application/pdf' ? $this->scan($data) : null;
        $disk = Storage::disk('public');

        if ($scan === null) {
            $path = $base . '.' . (self::TYPES[$mime] ?? 'bin');
            $disk->put($path, $data);

            return ['path' => $path, 'mime' => $mime, 'size' => strlen($data), 'scanned' => false];
        }

        $disk->put($base . '-asli.' . (self::TYPES[$mime] ?? 'jpg'), $data);
        $disk->put($path = $base . '.jpg', $scan);

        return ['path' => $path, 'mime' => 'image/jpeg', 'size' => strlen($scan), 'scanned' => true];
    }

    /**
     * Hapus struk beserta foto aslinya ("-asli").
     *
     * @param  array<int, string>|string  $paths
     */
    public static function delete(array|string $paths): void
    {
        $disk = Storage::disk('public');

        foreach ((array) $paths as $path) {
            $disk->delete($path);
            $disk->delete(self::originals($path));
        }
    }

    /**
     * Foto asli dari hasil scan, bila ada.
     */
    public static function original(string $path): ?string
    {
        return collect(self::originals($path))->first(fn (string $file) => Storage::disk('public')->exists($file));
    }

    /**
     * Hasil scan sebagai JPG, atau null bila bukan foto yang bisa dibaca.
     */
    public function scan(string $data): ?string
    {
        $size = @getimagesizefromstring($data);

        // Foto 48 MP ke atas butuh ratusan MB saat dibuka GD. GD bawaan PHP
        // menghitungnya ke memory_limit: bila tidak cukup, simpan apa adanya
        // daripada upload-nya gagal. libgd sistem tidak terkena batas itu.
        if (! $size || (defined('GD_BUNDLED') && GD_BUNDLED && ! $this->enoughMemory((int) $size[0] * (int) $size[1]))) {
            return null;
        }

        $image = @imagecreatefromstring($data);

        if (! $image instanceof GdImage) {
            return null;
        }

        // Dikecilkan dulu baru diputar: memutar foto penuh menggandakan memorinya.
        $image = $this->orient($this->workingCopy($image), $data);

        if (imagesx($image) < 200 || imagesy($image) < 200) {
            return null;
        }

        $quad = $this->findPaper($image) ?? $this->askCorners($image);
        $gray = $quad ? $this->warp($image, $quad) : $this->resizeGray($image);
        $out  = $this->clean($gray);

        ob_start();
        imagejpeg($out, null, 82);

        return (string) ob_get_clean();
    }

    // =================================================================
    // Langkah-langkah
    // =================================================================

    private function orient(GdImage $image, string $data): GdImage
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($data, "\xFF\xD8")) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($data));

        return match ((int) ($exif['Orientation'] ?? 1)) {
            3       => imagerotate($image, 180, 0),
            6       => imagerotate($image, -90, 0),
            8       => imagerotate($image, 90, 0),
            default => $image,
        };
    }

    private function workingCopy(GdImage $image): GdImage
    {
        imagepalettetotruecolor($image);

        return $this->fit($image, self::WORK_SIDE);
    }

    /**
     * Kertas = bagian terang terbesar yang berbentuk segi empat. Null bila
     * tidak ketemu, atau kertasnya sudah memenuhi foto.
     *
     * @return array{0: array{float, float}, 1: array{float, float}, 2: array{float, float}, 3: array{float, float}}|null
     */
    private function findPaper(GdImage $image): ?array
    {
        $small = $this->fit($image, self::DETECT_SIDE, always: true);
        $w     = imagesx($small);
        $h     = imagesy($small);
        $gray  = [];
        $hist  = array_fill(0, 256, 0);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $g   = (int) ((($rgb >> 16) & 0xFF) * 0.299 + (($rgb >> 8) & 0xFF) * 0.587 + ($rgb & 0xFF) * 0.114);
                $gray[] = $g;
                $hist[$g]++;
            }
        }

        $threshold = $this->otsu($hist, $w * $h);
        $component = $this->largestBright($gray, $w, $h, $threshold);
        $area      = count($component);

        // Terlalu kecil, atau hampir seluruh foto (kertas sudah memenuhi foto / latar terang).
        if ($area < 0.08 * $w * $h || $area > 0.92 * $w * $h) {
            return null;
        }

        // Sudut = titik terjauh ke arah tiap pojok.
        $tl = $tr = $br = $bl = null;
        foreach ($component as $index) {
            $x = $index % $w;
            $y = intdiv($index, $w);

            $tl = ! $tl || $x + $y < $tl[0] + $tl[1] ? [$x, $y] : $tl;
            $br = ! $br || $x + $y > $br[0] + $br[1] ? [$x, $y] : $br;
            $tr = ! $tr || $x - $y > $tr[0] - $tr[1] ? [$x, $y] : $tr;
            $bl = ! $bl || $x - $y < $bl[0] - $bl[1] ? [$x, $y] : $bl;
        }

        $quad = [$tl, $tr, $br, $bl];

        // Bentuknya harus segi empat yang terisi penuh oleh bagian terang tadi.
        $quadArea = $this->area($quad);
        if (! $this->convex($quad) || $quadArea < 0.08 * $w * $h || $area / $quadArea < 0.85) {
            return null;
        }

        $scale = imagesx($image) / $w;

        return array_map(fn (array $p) => [($p[0] + 0.5) * $scale, ($p[1] + 0.5) * $scale], $quad);
    }

    /**
     * Cadangan: sudut kertas menurut Gemini (koordinat 0–1000). Gemini hanya
     * memberi titik; gambarnya tetap diolah di sini.
     */
    private function askCorners(GdImage $image): ?array
    {
        if (! $this->gemini->isConfigured()) {
            return null;
        }

        $small = $this->fit($image, 1024);
        ob_start();
        imagejpeg($small, null, 80);
        $jpeg = (string) ob_get_clean();

        $point  = ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 2, 'maxItems' => 2];
        $result = $this->gemini->extractJson(<<<'PROMPT'
            Terlampir foto struk, nota, atau invoice kertas. Beri empat sudut kertasnya dalam koordinat 0 sampai 1000
            (x dari kiri, y dari atas): kiri_atas, kanan_atas, kanan_bawah, kiri_bawah, masing-masing [x, y].
            Semua null bila kertasnya sudah memenuhi foto atau tidak terlihat jelas.
            PROMPT, [
            'type'       => 'object',
            'properties' => [
                'kiri_atas'   => ['type' => ['array', 'null']] + $point,
                'kanan_atas'  => ['type' => ['array', 'null']] + $point,
                'kanan_bawah' => ['type' => ['array', 'null']] + $point,
                'kiri_bawah'  => ['type' => ['array', 'null']] + $point,
            ],
            'required' => ['kiri_atas', 'kanan_atas', 'kanan_bawah', 'kiri_bawah'],
        ], [['mime' => 'image/jpeg', 'data' => $jpeg]]);

        $quad = [];
        foreach (['kiri_atas', 'kanan_atas', 'kanan_bawah', 'kiri_bawah'] as $key) {
            $p = $result[$key] ?? null;

            if (! is_array($p) || count($p) !== 2 || ! is_numeric($p[0]) || ! is_numeric($p[1])) {
                return null;
            }

            $quad[] = [max(0, min(1000, (float) $p[0])) / 1000 * imagesx($image), max(0, min(1000, (float) $p[1])) / 1000 * imagesy($image)];
        }

        return $this->convex($quad) && $this->area($quad) >= 0.08 * imagesx($image) * imagesy($image) ? $quad : null;
    }

    /**
     * Luruskan segi empat $quad (kiri atas, kanan atas, kanan bawah, kiri
     * bawah) menjadi persegi panjang abu-abu.
     */
    private function warp(GdImage $image, array $quad): GdImage
    {
        [$tl, $tr, $br, $bl] = $quad;

        $width  = max($this->distance($tl, $tr), $this->distance($bl, $br));
        $height = max($this->distance($tl, $bl), $this->distance($tr, $br));
        $scale  = min(1, self::MAX_SIDE / max($width, $height));
        $w      = max(1, (int) round($width * $scale));
        $h      = max(1, (int) round($height * $scale));

        // Homografi dari titik hasil (u, v) ke titik di foto (x, y).
        $m = $this->homography([[0, 0], [$w - 1, 0], [$w - 1, $h - 1], [0, $h - 1]], $quad);

        $sw  = imagesx($image) - 1;
        $sh  = imagesy($image) - 1;
        $out = imagecreatetruecolor($w, $h);

        for ($v = 0; $v < $h; $v++) {
            for ($u = 0; $u < $w; $u++) {
                $d = $m[6] * $u + $m[7] * $v + 1;
                $x = (int) (($m[0] * $u + $m[1] * $v + $m[2]) / $d);
                $y = (int) (($m[3] * $u + $m[4] * $v + $m[5]) / $d);

                $rgb = imagecolorat($image, max(0, min($sw, $x)), max(0, min($sh, $y)));
                $g   = (int) ((($rgb >> 16) & 0xFF) * 0.299 + (($rgb >> 8) & 0xFF) * 0.587 + ($rgb & 0xFF) * 0.114);

                imagesetpixel($out, $u, $v, $g * 0x010101);
            }
        }

        return $out;
    }

    private function resizeGray(GdImage $image): GdImage
    {
        $out = $this->fit($image, self::MAX_SIDE, always: true);
        imagefilter($out, IMG_FILTER_GRAYSCALE);

        return $out;
    }

    /**
     * Ratakan bayangan (bagi dengan latar yang dikaburkan), kertas jadi putih,
     * tulisan dipertegas.
     */
    private function clean(GdImage $gray): GdImage
    {
        $w = imagesx($gray);
        $h = imagesy($gray);

        // Latar: dikecilkan lalu dibesarkan lagi, tulisan tipis ikut hilang.
        $small = imagecreatetruecolor(max(1, intdiv($w, 24)), max(1, intdiv($h, 24)));
        imagecopyresampled($small, $gray, 0, 0, 0, 0, imagesx($small), imagesy($small), $w, $h);
        $background = imagecreatetruecolor($w, $h);
        imagecopyresampled($background, $small, 0, 0, 0, 0, $w, $h, imagesx($small), imagesy($small));

        $out = imagecreatetruecolor($w, $h);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $g  = imagecolorat($gray, $x, $y) & 0xFF;
                $bg = max(1, imagecolorat($background, $x, $y) & 0xFF);

                // Kertas mendekati 1; tulisan diperpekat sedikit (kurva gamma).
                $ratio = min(1, $g / $bg * 1.08);
                $value = (int) (255 * $ratio * $ratio);

                imagesetpixel($out, $x, $y, $value * 0x010101);
            }
        }

        imageconvolution($out, [[0, -1, 0], [-1, 5, -1], [0, -1, 0]], 1, 0);

        return $out;
    }

    // =================================================================
    // Bantuan
    // =================================================================

    private function enoughMemory(int $pixels): bool
    {
        $limit = trim((string) ini_get('memory_limit'));

        if ($limit === '' || $limit === '-1') {
            return true;
        }

        $bytes = (int) $limit * match (strtolower(substr($limit, -1))) {
            'g'     => 1024 ** 3,
            'm'     => 1024 ** 2,
            'k'     => 1024,
            default => 1,
        };

        // Foto terbuka 4 byte per piksel, plus salinan kerja dan cadangan
        // (terukur ±57 MB untuk foto 12 MP).
        return memory_get_usage() + $pixels * 6 + 32 * 1024 ** 2 <= $bytes;
    }

    private function fit(GdImage $image, int $side, bool $always = false): GdImage
    {
        $w     = imagesx($image);
        $h     = imagesy($image);
        $scale = $side / max($w, $h);

        if ($scale >= 1 && ! $always) {
            return $image;
        }

        $scale = min($scale, 1);
        $out   = imagecreatetruecolor(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
        imagecopyresampled($out, $image, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);

        return $out;
    }

    private function otsu(array $hist, int $total): int
    {
        $sum = 0;
        foreach ($hist as $value => $count) {
            $sum += $value * $count;
        }

        [$sumB, $weightB, $best, $threshold] = [0, 0, 0.0, 128];

        foreach ($hist as $value => $count) {
            $weightB += $count;
            $weightF  = $total - $weightB;

            if ($weightB === 0 || $weightF === 0) {
                continue;
            }

            $sumB    += $value * $count;
            $between  = $weightB * $weightF * (($sumB / $weightB) - (($sum - $sumB) / $weightF)) ** 2;

            if ($between > $best) {
                [$best, $threshold] = [$between, $value];
            }
        }

        return $threshold;
    }

    /**
     * Piksel bagian terang terbesar (indeks y * w + x), 4 arah.
     *
     * @return array<int, int>
     */
    private function largestBright(array $gray, int $w, int $h, int $threshold): array
    {
        $seen = [];
        $best = [];

        foreach ($gray as $start => $value) {
            if ($value <= $threshold || isset($seen[$start])) {
                continue;
            }

            $seen[$start] = true;
            $queue        = [$start];
            $component    = [];

            while ($queue) {
                $index       = array_pop($queue);
                $component[] = $index;
                $x           = $index % $w;

                foreach ([$x > 0 ? $index - 1 : -1, $x < $w - 1 ? $index + 1 : -1, $index - $w, $index + $w] as $next) {
                    if ($next >= 0 && $next < $w * $h && ! isset($seen[$next]) && $gray[$next] > $threshold) {
                        $seen[$next] = true;
                        $queue[]     = $next;
                    }
                }
            }

            if (count($component) > count($best)) {
                $best = $component;
            }
        }

        return $best;
    }

    /**
     * Homografi 3×3 (h33 = 1) yang memetakan $from ke $to, empat pasang titik.
     *
     * @return array<int, float> h11..h32
     */
    private function homography(array $from, array $to): array
    {
        $a = [];
        $b = [];

        foreach ($from as $i => [$u, $v]) {
            [$x, $y] = $to[$i];
            $a[] = [$u, $v, 1, 0, 0, 0, -$u * $x, -$v * $x];
            $b[] = $x;
            $a[] = [0, 0, 0, $u, $v, 1, -$u * $y, -$v * $y];
            $b[] = $y;
        }

        // Eliminasi Gauss dengan pivot parsial.
        $n = 8;
        for ($col = 0; $col < $n; $col++) {
            $pivot = $col;
            for ($row = $col + 1; $row < $n; $row++) {
                if (abs($a[$row][$col]) > abs($a[$pivot][$col])) {
                    $pivot = $row;
                }
            }

            [$a[$col], $a[$pivot]] = [$a[$pivot], $a[$col]];
            [$b[$col], $b[$pivot]] = [$b[$pivot], $b[$col]];

            for ($row = $col + 1; $row < $n; $row++) {
                $factor = $a[$row][$col] / ($a[$col][$col] ?: 1e-12);
                for ($k = $col; $k < $n; $k++) {
                    $a[$row][$k] -= $factor * $a[$col][$k];
                }
                $b[$row] -= $factor * $b[$col];
            }
        }

        $h = array_fill(0, $n, 0.0);
        for ($row = $n - 1; $row >= 0; $row--) {
            $sum = $b[$row];
            for ($k = $row + 1; $k < $n; $k++) {
                $sum -= $a[$row][$k] * $h[$k];
            }
            $h[$row] = $sum / ($a[$row][$row] ?: 1e-12);
        }

        return $h;
    }

    private function convex(array $quad): bool
    {
        $sign = 0;

        for ($i = 0; $i < 4; $i++) {
            [$ax, $ay] = $quad[$i];
            [$bx, $by] = $quad[($i + 1) % 4];
            [$cx, $cy] = $quad[($i + 2) % 4];
            $cross     = ($bx - $ax) * ($cy - $by) - ($by - $ay) * ($cx - $bx);

            if (abs($cross) < 1e-9) {
                return false;
            }

            if ($sign === 0) {
                $sign = $cross > 0 ? 1 : -1;
            } elseif (($cross > 0 ? 1 : -1) !== $sign) {
                return false;
            }
        }

        return true;
    }

    private function area(array $quad): float
    {
        $sum = 0;
        for ($i = 0; $i < 4; $i++) {
            $sum += $quad[$i][0] * $quad[($i + 1) % 4][1] - $quad[($i + 1) % 4][0] * $quad[$i][1];
        }

        return abs($sum) / 2;
    }

    private function distance(array $a, array $b): float
    {
        return hypot($a[0] - $b[0], $a[1] - $b[1]);
    }

    /**
     * @return array<int, string>
     */
    private static function originals(string $path): array
    {
        $base = preg_replace('/\.[^.\/]+$/', '', $path);

        return array_map(fn (string $ext) => "{$base}-asli.{$ext}", ['jpg', 'png', 'webp']);
    }
}
