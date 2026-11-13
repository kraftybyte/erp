<?php

namespace App\Services;

use GdImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Foto struk/nota dibuat seperti hasil scan (ala CamScanner), otomatis:
 * 0. gambar digital (screenshot aplikasi, bukti transfer, e-receipt) tidak
 *    diolah sama sekali: sudah tajam, dan diolah seperti foto kertas justru
 *    merusaknya (huruf menebal/pecah, teks abu-abu hilang, foto jadi hitam);
 * 1. rotasi EXIF dari HP diterapkan;
 * 2. kertas struk dicari dari warnanya (terang dan tidak berwarna; kayu,
 *    kulit, dan benda berwarna tidak ikut), baris demi baris, lalu tepinya
 *    ditarik garis lurus. Bila gagal dan Gemini aktif, Gemini hanya dimintai
 *    keempat sudutnya. Kertasnya lalu dipotong dan diluruskan;
 * 3. kertas diputihkan dan bayangan diangkat dengan lembut, warna tetap.
 *
 * Bila kertasnya tidak ketemu, foto disimpan apa adanya: lebih baik foto asli
 * daripada hasil olahan yang rusak. Pikselnya tetap dari foto asli (tidak
 * digambar ulang AI), dan foto aslinya ikut disimpan dengan akhiran "-asli".
 * PDF tidak diubah. Hanya GD (pasti ada di hosting), tanpa Imagick.
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

    // Foto kecil (mis. 400 px) diperbesar halus sampai sekian kali, supaya
    // tulisannya tidak kotak-kotak saat dibuka di layar.
    private const MAX_UPSCALE = 2.5;

    // Hitam-putih (setelah bayangan diangkat): di atas PAPER_LEVEL putih bersih;
    // hitam penuh mulai INK_SPREAD jarak dari tinta foto itu ke kertas (paling
    // terang INK_MAX); di antaranya landai, jadi tepi huruf halus.
    private const PAPER_LEVEL = 225;
    private const INK_SPREAD  = 0.1;
    private const INK_MAX     = 190;
    private const MAX_GAIN    = 2.3;

    // Ukuran kerja untuk mencari kertas: cukup kecil supaya tulisan melebur.
    private const DETECT_SIDE = 240;

    // Piksel "kertas": cukup terang dan hampir tidak berwarna.
    private const PAPER_MIN_GRAY       = 105;
    private const PAPER_MAX_SATURATION = 0.18;

    // Gambar digital: tiga warna terbanyak menutupi sebagian besar gambar
    // (latar dan kartu UI yang rata sempurna). Terukur: screenshot 0,70–0,93;
    // foto struk 0,02–0,05; foto produk paling tinggi 0,25.
    private const DIGITAL_TOP_COLORS = 0.40;

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
     * Hasil scan sebagai JPG, atau null bila bukan foto atau kertasnya tidak
     * ketemu (disimpan apa adanya).
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

        if (imagesx($image) < 150 || imagesy($image) < 150 || $this->isDigital($image)) {
            return null;
        }

        $paper = $this->findPaper($image) ?? $this->askCorners($image);

        if ($paper === null) {
            return null;
        }

        $out = $paper === 'full' ? $this->clean($this->enlarge($image)) : $this->warp($image, $paper);

        ob_start();
        imagejpeg($out, null, 88);

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

    /**
     * Screenshot atau dokumen digital: warnanya rata sempurna (latar putih,
     * kartu abu-abu, tombol berwarna), sedangkan foto kamera selalu punya
     * noise sehingga hampir tidak ada dua piksel yang persis sama warnanya.
     */
    public function isDigital(GdImage $image): bool
    {
        $w      = imagesx($image);
        $h      = imagesy($image);
        $step   = max(1, intdiv(max($w, $h), 400));
        $counts = [];
        $total  = 0;

        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                $color          = imagecolorat($image, $x, $y) & 0xFFFFFF;
                $counts[$color] = ($counts[$color] ?? 0) + 1;
                $total++;
            }
        }

        rsort($counts);

        return $total > 0 && array_sum(array_slice($counts, 0, 3)) / $total >= self::DIGITAL_TOP_COLORS;
    }

    private function workingCopy(GdImage $image): GdImage
    {
        imagepalettetotruecolor($image);

        return $this->fit($image, self::WORK_SIDE);
    }

    /**
     * Cari kertas baris demi baris: di tiap baris, bentangan "kertas" (terang,
     * tidak berwarna) terpanjang. Baris yang lebarnya jauh dari lebar umum
     * (dinding di atas struk, baris yang tertutup jari) dibuang, lalu tepi
     * kiri, kanan, atas, dan bawah ditarik garis lurus.
     *
     * @return array<int, array{float, float}>|'full'|null  empat sudut, 'full' bila kertas memenuhi foto, null bila tidak ketemu
     */
    private function findPaper(GdImage $image): array|string|null
    {
        $small = $this->fit($image, self::DETECT_SIDE, always: true);
        $w     = imagesx($small);
        $h     = imagesy($small);
        [$paper, $gray, $colored] = $this->paperMask($small, $w, $h);

        // Bentangan terpanjang per baris; celah pendek (tulisan tebal, logo) disambung.
        $gap  = max(2, (int) round($w * 0.05));
        $rows = [];
        for ($y = 0; $y < $h; $y++) {
            $rows[$y] = $this->longestRun($paper, $y * $w, $w, $gap);
        }

        $widths = array_filter(array_map(fn ($run) => $run ? $run[1] - $run[0] + 1 : 0, $rows), fn ($width) => $width >= 0.12 * $w);

        if (count($widths) < 0.25 * $h) {
            return null;
        }

        // Hampir seluruh foto "kertas": kertas memenuhi foto (dirapikan tanpa
        // dipotong), kecuali pinggiran foto jelas beda terang dengan tengahnya,
        // misalnya kertas abu-abu di meja putih. Itu diserahkan ke cadangan.
        if ($this->median(array_values($widths)) >= 0.9 * $w && count($widths) >= 0.8 * $h) {
            return abs($this->borderGray($small, $w, $h, true) - $this->borderGray($small, $w, $h, false)) <= 25 ? 'full' : null;
        }

        // Baris yang terang dari ujung ke ujung (dinding/meja terang di samping
        // kertas) tidak dipakai untuk menentukan lebar kertas.
        $widths = array_filter($widths, fn ($width) => $width < 0.95 * $w);

        if (count($widths) < 0.2 * $h) {
            return null;
        }

        // Lebar kertas = bentangan terlebar yang umum (persentil 75): di dokumen
        // yang padat (kop, logo, garis tebal) banyak baris terpotong isinya,
        // jadi nilai tengahnya terlalu kecil.
        $sorted = array_values($widths);
        sort($sorted);
        $width  = (float) $sorted[(int) floor(0.75 * (count($sorted) - 1))];
        $center = $this->median(array_map(
            fn ($y) => ($rows[$y][0] + $rows[$y][1]) / 2,
            array_keys(array_filter($widths, fn ($rowWidth) => abs($rowWidth - $width) <= 0.2 * $width))
        ));

        $kept = [];
        foreach ($widths as $y => $rowWidth) {
            $rowCenter = ($rows[$y][0] + $rows[$y][1]) / 2;

            if (abs($rowWidth - $width) <= 0.2 * $width && abs($rowCenter - $center) <= 0.15 * $width) {
                $kept[$y] = $rows[$y];
            }
        }

        $block = $this->longestBlock(array_keys($kept), max(3, (int) round($h * 0.04)));

        if (count($block) < 0.2 * $h) {
            return null;
        }

        $left  = $this->fitLine(array_map(fn ($y) => [$y, $kept[$y][0]], $block));
        $right = $this->fitLine(array_map(fn ($y) => [$y, $kept[$y][1] + 1], $block));

        // Kertas bisa berlanjut ke baris yang lebarnya menyimpang (latar terang
        // di sampingnya, jari di pinggir): diperpanjang selama pita di antara
        // kedua tepi masih kertas. Paruh kiri dan kanan terpisah, supaya tepi
        // atas/bawah yang miring (perspektif) ikut tertangkap.
        // Paruh yang berhenti karena tertutup benda berwarna (jari memegang
        // pojok struk) mengikuti paruh lainnya.
        [$topLeft, $topRight]       = $this->ends($paper, $gray, $colored, $w, $h, $left, $right, $block, -1);
        [$bottomLeft, $bottomRight] = $this->ends($paper, $gray, $colored, $w, $h, $left, $right, $block, 1);
        $at                         = fn (array $line, float $y) => [$line[0] * $y + $line[1], $y];

        // Ujung diukur di tengah tiap paruh (27,5% dan 72,5% lebar); tepi yang
        // miring diteruskan sampai ke sudut kertas.
        $reach = 0.275 / 0.45;
        [$topLeft, $topRight]       = [$topLeft - ($topRight - $topLeft) * $reach, $topRight + ($topRight - $topLeft) * $reach];
        [$bottomLeft, $bottomRight] = [$bottomLeft - ($bottomRight - $bottomLeft) * $reach, $bottomRight + ($bottomRight - $bottomLeft) * $reach];

        $quad = [$at($left, $topLeft), $at($right, $topRight), $at($right, $bottomRight), $at($left, $bottomLeft)];

        if (! $this->convex($quad) || $this->area($quad) < 0.08 * $w * $h) {
            return null;
        }

        // Sedikit ke dalam, supaya pinggiran meja tidak ikut jadi garis abu-abu.
        $quad  = $this->inset($quad, 0.012);
        $scale = imagesx($image) / $w;

        return array_map(fn (array $p) => [
            max(0, min(imagesx($image) - 1, $p[0] * $scale)),
            max(0, min(imagesy($image) - 1, $p[1] * $scale)),
        ], $quad);
    }

    /**
     * Baris terjauh ke arah $step (−1 atas, 1 bawah) dari ujung $block yang
     * bagian tengahnya (antara tepi kiri dan kanan) masih kertas: sebagian
     * besar "kertas" dan terangnya tidak berubah tiba-tiba (dinding atau meja
     * terang di ujung struk). Perubahan bertahap (pencahayaan) tetap diikuti.
     *
     * $from–$to: bagian lebar kertas yang diperiksa (0 = tepi kiri, 1 = kanan).
     * Baris isi (kop/tabel/logo berwarna atau gelap) dilompati bila pinggiran
     * kertas di baris itu masih putih: isinya ada di dalam kertas. Benda yang
     * menutup sampai melewati tepi kertas (printer, jari) menghentikannya;
     * bila yang menghentikan benda berwarna, kembaliannya ditandai tertutup.
     *
     * @return array{int, bool}  [baris, berhenti karena tertutup]
     */
    private function extend(array $paper, array $gray, array $colored, int $w, int $h, array $left, array $right, array $block, int $step, float $from, float $to): array
    {
        $span = function (int $y, float $from, float $to) use ($w, $left, $right): ?array {
            $x0 = $left[0] * $y + $left[1];
            $x1 = $right[0] * $y + $right[1];
            $a  = (int) max(0, ceil($x0 + $from * ($x1 - $x0)));
            $b  = (int) min($w - 1, floor($x0 + $to * ($x1 - $x0)));

            return $b > $a ? [$a, $b] : null;
        };

        // [bagian kertas, terang kertas, jenis: 'ok' | 'colored' | 'dark']
        $band = function (int $y) use ($paper, $gray, $colored, $w, $span, $from, $to): ?array {
            if (! $range = $span($y, $from, $to)) {
                return null;
            }

            $on     = 0;
            $color  = 0;
            $ink    = 0;
            $values = [];
            for ($x = $range[0]; $x <= $range[1]; $x++) {
                $i = $y * $w + $x;

                if ($colored[$i]) {
                    $color++;

                    continue;
                }

                $values[] = $gray[$i];

                // Tulisan dan logo gelap di atas kertas tidak dihitung.
                if ($gray[$i] < self::PAPER_MIN_GRAY) {
                    $ink++;
                } elseif ($paper[$i]) {
                    $on++;
                }
            }

            $total = $range[1] - $range[0] + 1;

            if ($color > 0.6 * $total) {
                return [0.0, 0.0, 'colored'];
            }

            if ($ink > 0.7 * $total) {
                return [0.0, 0.0, 'dark'];
            }

            // Bagian terang (persentil 80) = kertasnya, juga di baris tulisan padat.
            sort($values);

            return [$on / max(1, $total - $color - $ink), $values[(int) floor(0.8 * (count($values) - 1))], 'ok'];
        };

        // Pinggiran kiri & kanan kertas di baris ini masih kertas?
        $framed = function (int $y) use ($paper, $w, $span): bool {
            foreach ([[0.01, 0.05], [0.95, 0.99]] as [$a, $b]) {
                $range = $span($y, $a, $b);

                if (! $range) {
                    return false;
                }

                $on = 0;
                for ($x = $range[0]; $x <= $range[1]; $x++) {
                    $on += $paper[$y * $w + $x] ? 1 : 0;
                }

                if ($on < 0.6 * ($range[1] - $range[0] + 1)) {
                    return false;
                }
            }

            return true;
        };

        $edge = $step < 0 ? $block[0] : end($block);
        $near = $step < 0 ? array_slice($block, 0, 8) : array_slice($block, -8);
        $ref  = $this->median(array_filter(array_map(fn ($y) => ($row = $band($y)) && $row[2] === 'ok' ? $row[1] : null, $near), fn ($v) => $v !== null));

        // Beberapa baris janggal berturut-turut (garis putus-putus, noda) masih
        // ditoleransi; baru berhenti bila berlanjut.
        $patience = max(2, (int) round($h * 0.02));
        $bad      = 0;
        $occluded = false;

        for ($y = $edge + $step; $y >= 0 && $y < $h; $y += $step) {
            $row = $band($y);

            // Isi di dalam kertas (kop, tabel, logo): dilompati, paling jauh 30% tinggi.
            if ($row && $row[2] !== 'ok' && $framed($y)) {
                if (abs($y - $edge) > 0.3 * $h) {
                    break;
                }

                continue;
            }

            if (! $row || $row[2] !== 'ok' || $row[0] < 0.75 || abs($row[1] - $ref) > 16) {
                $occluded = ($row[2] ?? null) === 'colored';

                if (++$bad > $patience) {
                    break;
                }

                continue;
            }

            $bad      = 0;
            $occluded = false;
            $ref      = 0.8 * $ref + 0.2 * $row[1];
            $edge     = $y;
        }

        return [$edge, $occluded];
    }

    /**
     * Ujung atas ($step −1) atau bawah (1) untuk paruh kiri dan kanan kertas.
     *
     * @return array{int, int}  [kiri, kanan]; ujung bawah sudah +1 (batas luar)
     */
    private function ends(array $paper, array $gray, array $colored, int $w, int $h, array $left, array $right, array $block, int $step): array
    {
        [$a, $aCovered] = $this->extend($paper, $gray, $colored, $w, $h, $left, $right, $block, $step, 0.1, 0.45);
        [$b, $bCovered] = $this->extend($paper, $gray, $colored, $w, $h, $left, $right, $block, $step, 0.55, 0.9);

        if ($aCovered && ! $bCovered) {
            $a = $b;
        } elseif ($bCovered && ! $aCovered) {
            $b = $a;
        }

        return $step > 0 ? [$a + 1, $b + 1] : [$a, $b];
    }

    /**
     * Median terang pinggiran foto (5% tepi) atau bagian tengahnya (50% tengah).
     */
    private function borderGray(GdImage $small, int $w, int $h, bool $border): float
    {
        $values = [];
        $edge   = max(1, (int) round(min($w, $h) * 0.05));

        for ($y = 0; $y < $h; $y += 2) {
            for ($x = 0; $x < $w; $x += 2) {
                $isBorder = $x < $edge || $y < $edge || $x >= $w - $edge || $y >= $h - $edge;
                $isCenter = $x >= $w / 4 && $x < $w * 3 / 4 && $y >= $h / 4 && $y < $h * 3 / 4;

                if ($border ? $isBorder : $isCenter) {
                    $rgb      = imagecolorat($small, $x, $y);
                    $values[] = (($rgb >> 16) & 0xFF) * 0.299 + (($rgb >> 8) & 0xFF) * 0.587 + ($rgb & 0xFF) * 0.114;
                }
            }
        }

        return $this->median($values);
    }

    /**
     * @return array{0: array<int, bool>, 1: array<int, float>, 2: array<int, bool>}  [kertas?, terang, berwarna?] per piksel (y * w + x)
     */
    private function paperMask(GdImage $small, int $w, int $h): array
    {
        $mask    = [];
        $gray    = [];
        $colored = [];

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $r   = ($rgb >> 16) & 0xFF;
                $g   = ($rgb >> 8) & 0xFF;
                $b   = $rgb & 0xFF;
                $max = max($r, $g, $b);
                $lum = $r * 0.299 + $g * 0.587 + $b * 0.114;

                $sat = $max ? ($max - min($r, $g, $b)) / $max : 0;

                $gray[]    = $lum;
                $mask[]    = $lum >= self::PAPER_MIN_GRAY && $sat <= self::PAPER_MAX_SATURATION;
                $colored[] = $lum >= 70 && $sat > 0.25;
            }
        }

        return [$mask, $gray, $colored];
    }

    /**
     * @return array{int, int}|null  [x awal, x akhir] bentangan terpanjang
     */
    private function longestRun(array $mask, int $offset, int $w, int $gap): ?array
    {
        $best  = null;
        $start = null;
        $end   = null;

        for ($x = 0; $x <= $w; $x++) {
            $on = $x < $w && $mask[$offset + $x];

            if ($on) {
                if ($start !== null && $x - $end - 1 <= $gap) {
                    $end = $x;
                } else {
                    if ($start !== null && (! $best || $end - $start > $best[1] - $best[0])) {
                        $best = [$start, $end];
                    }
                    [$start, $end] = [$x, $x];
                }
            }
        }

        if ($start !== null && (! $best || $end - $start > $best[1] - $best[0])) {
            $best = [$start, $end];
        }

        return $best;
    }

    /**
     * Rangkaian baris berurutan terpanjang (celah sampai $gap baris dianggap menyambung).
     *
     * @param  array<int, int>  $ys  urut naik
     * @return array<int, int>
     */
    private function longestBlock(array $ys, int $gap): array
    {
        $best    = [];
        $current = [];

        foreach ($ys as $y) {
            if ($current && $y - end($current) > $gap) {
                $best    = count($current) > count($best) ? $current : $best;
                $current = [];
            }
            $current[] = $y;
        }

        return count($current) > count($best) ? $current : $best;
    }

    /**
     * Garis b = m·a + c lewat titik [a, b], dengan titik yang menyimpang jauh
     * (jari, sobekan) dibuang lalu dihitung ulang.
     *
     * @param  array<int, array{float|int, float|int}>  $points
     * @return array{float, float}  [m, c]
     */
    private function fitLine(array $points): array
    {
        [$m, $c] = [0.0, 0.0];

        for ($round = 0; $round < 3; $round++) {
            $n  = count($points);
            $sa = $sb = $saa = $sab = 0.0;

            foreach ($points as [$a, $b]) {
                $sa  += $a;
                $sb  += $b;
                $saa += $a * $a;
                $sab += $a * $b;
            }

            $den = $n * $saa - $sa * $sa;
            $m   = abs($den) < 1e-9 ? 0.0 : ($n * $sab - $sa * $sb) / $den;
            $c   = ($sb - $m * $sa) / max(1, $n);

            $residuals = array_map(fn ($p) => abs($p[1] - ($m * $p[0] + $c)), $points);
            $limit     = max(1.5, 2.5 * $this->median($residuals));
            $inliers   = array_values(array_filter($points, fn ($i) => $residuals[$i] <= $limit, ARRAY_FILTER_USE_KEY));

            if (count($inliers) === $n || count($inliers) < 5) {
                break;
            }

            $points = $inliers;
        }

        return [$m, $c];
    }

    private function inset(array $quad, float $ratio): array
    {
        $cx = array_sum(array_column($quad, 0)) / 4;
        $cy = array_sum(array_column($quad, 1)) / 4;

        return array_map(fn (array $p) => [$p[0] + ($cx - $p[0]) * $ratio * 2, $p[1] + ($cy - $p[1]) * $ratio], $quad);
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

            $quad[] = [max(0, min(1000, (float) $p[0])) / 1000 * (imagesx($image) - 1), max(0, min(1000, (float) $p[1])) / 1000 * (imagesy($image) - 1)];
        }

        return $this->convex($quad) && $this->area($quad) >= 0.08 * imagesx($image) * imagesy($image) ? $quad : null;
    }

    /**
     * Luruskan segi empat $quad (kiri atas, kanan atas, kanan bawah, kiri
     * bawah) menjadi persegi panjang, sekaligus dirapikan (lihat clean()):
     * satu putaran per piksel, peta latar kertasnya dari versi kecil.
     */
    private function warp(GdImage $image, array $quad): GdImage
    {
        [$tl, $tr, $br, $bl] = $quad;

        $width  = max($this->distance($tl, $tr), $this->distance($bl, $br));
        $height = max($this->distance($tl, $bl), $this->distance($tr, $br));
        $scale  = $this->outputScale($width, $height);
        $w      = max(1, (int) round($width * $scale));
        $h      = max(1, (int) round($height * $scale));
        $sw     = imagesx($image) - 1;
        $sh     = imagesy($image) - 1;

        // Peta latar dari versi kecil: foto dikecilkan dulu dengan perataan area
        // (supaya noise JPEG tidak terbawa), baru diluruskan kasar.
        $bw     = max(4, intdiv($w, 5));
        $bh     = max(4, intdiv($h, 5));
        $k      = min(1, $bw / max(1, $width));
        $source = $k < 1 ? $this->fit($image, (int) max(1, round(max(imagesx($image), imagesy($image)) * $k)), always: true) : $image;
        $kx     = imagesx($source) / imagesx($image);
        $ms     = $this->homography([[0, 0], [$bw - 1, 0], [$bw - 1, $bh - 1], [0, $bh - 1]], array_map(fn ($p) => [$p[0] * $kx, $p[1] * $kx], $quad));
        $small  = imagecreatetruecolor($bw, $bh);
        $ssw    = imagesx($source) - 1;
        $ssh    = imagesy($source) - 1;

        for ($v = 0; $v < $bh; $v++) {
            for ($u = 0; $u < $bw; $u++) {
                $d = $ms[6] * $u + $ms[7] * $v + 1;
                imagesetpixel($small, $u, $v, imagecolorat($source,
                    (int) max(0, min($ssw, ($ms[0] * $u + $ms[1] * $v + $ms[2]) / $d)),
                    (int) max(0, min($ssh, ($ms[3] * $u + $ms[4] * $v + $ms[5]) / $d))));
            }
        }

        // Dokumen berwarna: hanya dipotong, diluruskan, dan kontrasnya
        // dinaikkan. Struk biasa: hitam-putih bersih.
        $color      = $this->isColorDocument($small);
        $levels     = $color ? $this->contrastTable($small) : [];
        $background = $color ? null : $this->background($small, $w, $h);
        $lift       = $color ? [] : $this->liftTable();

        // Homografi dari titik hasil (u, v) ke titik di foto (x, y).
        $m    = $this->homography([[0, 0], [$w - 1, 0], [$w - 1, $h - 1], [0, $h - 1]], $quad);
        $out  = imagecreatetruecolor($w, $h);
        $grid = [];
        $hist = array_fill(0, 256, 0);

        // Interpolasi bilinear: titik terdekat membuat tepi huruf bergerigi.
        for ($v = 0; $v < $h; $v++) {
            for ($u = 0; $u < $w; $u++) {
                $d  = $m[6] * $u + $m[7] * $v + 1;
                $fx = max(0, min($sw, ($m[0] * $u + $m[1] * $v + $m[2]) / $d));
                $fy = max(0, min($sh, ($m[3] * $u + $m[4] * $v + $m[5]) / $d));
                $x0 = (int) $fx;
                $y0 = (int) $fy;
                $x1 = $x0 < $sw ? $x0 + 1 : $x0;
                $y1 = $y0 < $sh ? $y0 + 1 : $y0;
                $ax = $fx - $x0;
                $ay = $fy - $y0;
                $bx = 1 - $ax;
                $by = 1 - $ay;

                $c00 = imagecolorat($image, $x0, $y0);
                $c10 = imagecolorat($image, $x1, $y0);
                $c01 = imagecolorat($image, $x0, $y1);
                $c11 = imagecolorat($image, $x1, $y1);

                $r = (int) (((($c00 >> 16) & 0xFF) * $bx + (($c10 >> 16) & 0xFF) * $ax) * $by + ((($c01 >> 16) & 0xFF) * $bx + (($c11 >> 16) & 0xFF) * $ax) * $ay);
                $g = (int) (((($c00 >> 8) & 0xFF) * $bx + (($c10 >> 8) & 0xFF) * $ax) * $by + ((($c01 >> 8) & 0xFF) * $bx + (($c11 >> 8) & 0xFF) * $ax) * $ay);
                $b = (int) ((($c00 & 0xFF) * $bx + ($c10 & 0xFF) * $ax) * $by + (($c01 & 0xFF) * $bx + ($c11 & 0xFF) * $ax) * $ay);

                if ($color) {
                    imagesetpixel($out, $u, $v, ($levels[$r] << 16) | ($levels[$g] << 8) | $levels[$b]);

                    continue;
                }

                $lifted = $lift[imagecolorat($background, $u, $v) & 0xFF][$this->inkLevel($r, $g, $b)];
                $hist[$lifted]++;
                imagesetpixel($out, $u, $v, $lifted * 0x010101);

                if (($u & 3) === 0 && ($v & 3) === 0) {
                    $grid[$v >> 2][$u >> 2] = $this->isColored($r, $g, $b);
                }
            }
        }

        if (! $color) {
            $this->blackWhite($out, $hist);
            $this->whitenFingers($out, $grid);
        }

        return $out;
    }

    /**
     * Kertas memenuhi foto: tanpa dipotong, hanya disesuaikan ukurannya.
     */
    private function enlarge(GdImage $image): GdImage
    {
        $scale = $this->outputScale(imagesx($image), imagesy($image));
        $out   = imagecreatetruecolor(max(1, (int) round(imagesx($image) * $scale)), max(1, (int) round(imagesy($image) * $scale)));
        imagecopyresampled($out, $image, 0, 0, 0, 0, imagesx($out), imagesy($out), imagesx($image), imagesy($image));

        return $out;
    }

    private function outputScale(float $width, float $height): float
    {
        return min(self::MAX_UPSCALE, self::MAX_SIDE / max(1, $width, $height));
    }

    /**
     * Kertas diputihkan dan bayangan diangkat: tiap piksel dikalikan supaya
     * latar kertas di sekitarnya menjadi putih. Penguatannya dibatasi dan
     * tidak ada kontras ekstrem, jadi hasilnya tetap alami dan berwarna.
     * Tanpa pertajam: penajaman memunculkan lingkaran di sekitar huruf dan
     * kotak-kotak JPEG.
     */
    private function clean(GdImage $image): GdImage
    {
        $w     = imagesx($image);
        $h     = imagesy($image);
        $small = imagecreatetruecolor(max(4, intdiv($w, 5)), max(4, intdiv($h, 5)));
        imagecopyresampled($small, $image, 0, 0, 0, 0, imagesx($small), imagesy($small), $w, $h);

        $out = imagecreatetruecolor($w, $h);

        // Dokumen berwarna: hanya kontrasnya dinaikkan.
        if ($this->isColorDocument($small)) {
            $levels = $this->contrastTable($small);

            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $rgb = imagecolorat($image, $x, $y);
                    imagesetpixel($out, $x, $y, ($levels[($rgb >> 16) & 0xFF] << 16) | ($levels[($rgb >> 8) & 0xFF] << 8) | $levels[$rgb & 0xFF]);
                }
            }

            return $out;
        }

        $background = $this->background($small, $w, $h);
        $lift       = $this->liftTable();
        $grid       = [];
        $hist       = array_fill(0, 256, 0);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($image, $x, $y);
                [$r, $g, $b] = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];

                $lifted = $lift[imagecolorat($background, $x, $y) & 0xFF][$this->inkLevel($r, $g, $b)];
                $hist[$lifted]++;
                imagesetpixel($out, $x, $y, $lifted * 0x010101);

                if (($x & 3) === 0 && ($y & 3) === 0) {
                    $grid[$y >> 2][$x >> 2] = $this->isColored($r, $g, $b);
                }
            }
        }

        $this->blackWhite($out, $hist);
        $this->whitenFingers($out, $grid);

        return $out;
    }

    /**
     * Peta terang latar kertas seukuran hasil: dari versi kecil, tulisan
     * dihapus dengan filter maksimum, lalu dibesarkan lagi dengan halus.
     */
    private function background(GdImage $small, int $w, int $h): GdImage
    {
        $sw    = imagesx($small);
        $sh    = imagesy($small);
        $level = [];

        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $level[$y][$x] = max(($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF);
            }
        }

        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x++) {
                $max = $level[$y][$x];
                for ($dy = -1; $dy <= 1; $dy++) {
                    for ($dx = -1; $dx <= 1; $dx++) {
                        $max = max($max, $level[$y + $dy][$x + $dx] ?? 0);
                    }
                }
                imagesetpixel($small, $x, $y, $max * 0x010101);
            }
        }

        $background = imagecreatetruecolor($w, $h);
        imagecopyresampled($background, $small, 0, 0, 0, 0, $w, $h, $sw, $sh);
        imagefilter($background, IMG_FILTER_GAUSSIAN_BLUR);

        return $background;
    }

    /**
     * Dokumen berwarna (logo/kop/tabel berwarna, cap): bagian berwarna di
     * dalam kertas cukup banyak, atau bagian berwarna secara keseluruhan
     * besar (mis. kop berwarna selebar kertas). Jari di pojok tidak cukup.
     */
    private function isColorDocument(GdImage $small): bool
    {
        $w    = imagesx($small);
        $h    = imagesy($small);
        $grid = [];
        $all  = 0;

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $max = max(($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF);
                $on  = $max > 60 && $max - min(($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF) > 0.2 * $max;

                $grid[$y][$x] = $on;
                $all         += $on ? 1 : 0;
            }
        }

        if ($all > 0.06 * $w * $h) {
            return true;
        }

        $edge = count($this->borderConnected($grid));

        return $all - $edge > 0.015 * $w * $h;
    }

    /**
     * Tabel kontras per kanal untuk dokumen berwarna: hitam dan putih kertas
     * direntangkan (persentil 1 dan 98), lalu kurva S ringan. Warna tetap.
     *
     * @return array<int, int>
     */
    private function contrastTable(GdImage $small): array
    {
        $values = [];
        for ($y = 0; $y < imagesy($small); $y++) {
            for ($x = 0; $x < imagesx($small); $x++) {
                $rgb      = imagecolorat($small, $x, $y);
                $values[] = ((($rgb >> 16) & 0xFF) * 77 + (($rgb >> 8) & 0xFF) * 150 + ($rgb & 0xFF) * 29) >> 8;
            }
        }

        sort($values);
        $low  = $values[(int) floor(0.01 * (count($values) - 1))];
        $high = $values[(int) floor(0.98 * (count($values) - 1))];

        // Foto yang hampir rata (tanpa tulisan jelas) tidak direntangkan berlebihan.
        if ($high - $low < 60) {
            [$low, $high] = [max(0, $high - 60), $high];
        }

        $table = [];
        for ($v = 0; $v < 256; $v++) {
            $t         = max(0.0, min(1.0, ($v - $low) / ($high - $low)));
            $table[$v] = (int) round(255 * (0.6 * $t + 0.4 * $t * $t * (3 - 2 * $t)));
        }

        return $table;
    }

    private function isColored(int $r, int $g, int $b): bool
    {
        $max = max($r, $g, $b);

        return $max > 60 && $max - min($r, $g, $b) > 0.3 * $max;
    }

    /**
     * Jari yang memegang struk (kulit, kuteks) masuk dari luar kertas, jadi
     * menempel ke pinggir gambar: area berwarna yang tersambung ke pinggir
     * dijadikan putih. Tinta dan logo berwarna di dalam kertas tidak tersentuh.
     *
     * @param  array<int, array<int, bool>>  $grid  berwarna? per sel 4×4 piksel
     */
    private function whitenFingers(GdImage $out, array $grid): void
    {
        $seen = $this->borderConnected($grid);

        // Diperlebar dua sel, supaya tepi jari dan bayangan tipisnya ikut putih.
        $white = 0xFFFFFF;
        foreach ($seen as [$x, $y]) {
            imagefilledrectangle($out, ($x - 2) * 4, ($y - 2) * 4, ($x + 3) * 4 - 1, ($y + 3) * 4 - 1, $white);
        }
    }

    /**
     * Sel bernilai true yang tersambung ke pinggir grid.
     *
     * @param  array<int, array<int, bool>>  $grid
     * @return array<int, array{int, int}>  [x, y]
     */
    private function borderConnected(array $grid): array
    {
        $rows  = count($grid);
        $cols  = $rows ? count($grid[0]) : 0;
        $seen  = [];
        $queue = [];

        for ($y = 0; $y < $rows; $y++) {
            for ($x = 0; $x < $cols; $x++) {
                if (($y === 0 || $x === 0 || $y === $rows - 1 || $x === $cols - 1) && ! empty($grid[$y][$x])) {
                    $seen[$y][$x] = true;
                    $queue[]      = [$x, $y];
                }
            }
        }

        while ($queue) {
            [$x, $y] = array_pop($queue);

            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $nx = $x + $dx;
                $ny = $y + $dy;

                if (! empty($grid[$ny][$nx]) && empty($seen[$ny][$nx])) {
                    $seen[$ny][$nx] = true;
                    $queue[]        = [$nx, $ny];
                }
            }
        }

        $cells = [];
        foreach ($seen as $y => $row) {
            foreach (array_keys($row) as $x) {
                $cells[] = [$x, $y];
            }
        }

        return $cells;
    }

    /**
     * Terang piksel untuk hitam-putih. Warna terang yang bukan tinta (kulit
     * jari yang memegang struk) dianggap kertas; tinta gelap, termasuk logo
     * dan cap berwarna tua, tetap gelap.
     */
    private function inkLevel(int $r, int $g, int $b): int
    {
        $luma = ($r * 77 + $g * 150 + $b * 29) >> 8;
        $max  = max($r, $g, $b);

        return $luma > 150 && $max - min($r, $g, $b) > 0.25 * $max ? 255 : $luma;
    }

    /**
     * Tabel [terang latar][terang piksel] → terang setelah bayangan diangkat:
     * piksel dikalikan supaya latar kertas di sekitarnya menjadi putih.
     *
     * @return array<int, array<int, int>>
     */
    private function liftTable(): array
    {
        $table = [];

        for ($bg = 0; $bg < 256; $bg++) {
            $gain = min(self::MAX_GAIN, max(1.0, 245 / max(1, $bg)));

            for ($value = 0; $value < 256; $value++) {
                $table[$bg][$value] = (int) min(255, $value * $gain);
            }
        }

        return $table;
    }

    /**
     * Gaya scan hitam-putih (seperti aplikasi scanner): dipertajam, lalu tinta
     * jadi hitam dan kertas putih bersih, dengan ambang dari tinta foto itu
     * sendiri. Tinta pekat tidak menebal (huruf "m" tetap terbuka) dan struk
     * thermal yang pudar ikut digelapkan. Ketajaman dulu: lingkaran terang di
     * sekitar huruf terpotong jadi putih kertas, tidak terlihat.
     *
     * @param  array<int, int>  $hist  jumlah piksel per terang (0–255) setelah bayangan diangkat
     */
    private function blackWhite(GdImage $image, array $hist): void
    {
        imageconvolution($image, [[0, -1, 0], [-1, 8, -1], [0, -1, 0]], 4, 0);

        // Tinta = rata-rata 0,5% piksel tergelap: tepi huruf yang abu-abu (banyak di foto
        // beresolusi rendah) tidak ikut, jadi huruf tidak menebal.
        $target = max(1, (int) (array_sum($hist) * 0.005));
        $count  = 0;
        $sum    = 0;

        for ($v = 0; $v < 256 && $count < $target; $v++) {
            $take   = min($hist[$v], $target - $count);
            $sum   += $take * $v;
            $count += $take;
        }

        $ink   = $count ? $sum / $count : 0;
        $black = min(self::INK_MAX, $ink + self::INK_SPREAD * (self::PAPER_LEVEL - $ink));
        $map   = [];

        for ($v = 0; $v < 256; $v++) {
            $map[$v] = (int) max(0, min(255, ($v - $black) * 255 / max(1, self::PAPER_LEVEL - $black))) * 0x010101;
        }

        $w = imagesx($image);
        $h = imagesy($image);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                imagesetpixel($image, $x, $y, $map[imagecolorat($image, $x, $y) & 0xFF]);
            }
        }
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

    private function median(array $values): float
    {
        if (! $values) {
            return 0.0;
        }

        sort($values);
        $n = count($values);

        return $n % 2 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
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
