<?php

namespace App\Services;

use App\Helpers\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Asisten keuangan untuk Director dan Finance (/analisa di bot keuangan dan
 * analisa mingguan financial:analyze).
 *
 * 1. MetricsEngine menghitung fakta: kas, tagihan, piutang dan umurnya, DP yang
 *    belum dibuatkan pelunasan, pipeline, expense, advance, pengajuan.
 * 2. Aturan di findings() menandai yang perlu ditindaklanjuti dan menetapkan
 *    status (sehat / waspada / kritis). Jalan tanpa AI.
 * 3. Gemini memilih dan mengurutkan prioritas serta menulis langkahnya. Setiap
 *    butir diperiksa: nominal dan nomor invoice/quotation harus ada di fakta;
 *    yang tidak ada dibuang. Bila AI gagal atau semua butirnya dibuang,
 *    prioritas diambil dari aturan.
 */
class FinanceAdvisor
{
    public const PICS = ['Finance', 'Director', 'Sales', 'PM'];

    public const STATUS_ICONS = ['kritis' => '🔴', 'waspada' => '🟡', 'sehat' => '🟢'];

    private const LEVELS = ['kritis' => 3, 'waspada' => 2, 'info' => 1];

    private const MAX_PRIORITIES = 4;

    // Batas pesan Telegram 4096; sisakan ruang untuk entitas HTML.
    private const MESSAGE_LIMIT = 4000;

    public function __construct(private MetricsEngine $metrics, private GeminiService $gemini, private PromptEngine $prompts) {}

    /**
     * @return array{status: string, question: ?string, summary: string, priorities: list<array>, opportunity: ?string, source: string, ai_error: ?string, dropped: int, facts: array, findings: list<array>}
     */
    public function analyze(?string $question = null, ?CarbonInterface $today = null): array
    {
        $question = trim(Str::limit(strip_tags((string) $question), 300, ''));
        $question = $question !== '' ? $question : null;
        $facts    = $this->metrics->generateSnapshot($today);
        $findings = $this->findings($facts);
        $status   = self::status($findings);
        $answer   = null;
        $error    = null;
        $dropped  = 0;

        if (! $this->gemini->isConfigured()) {
            $error = 'API key Gemini belum diisi';
        } elseif (($raw = $this->gemini->extractJson($this->prompts->build(self::display($facts), $findings, $status, $question), PromptEngine::SCHEMA, timeout: 40)) === null) {
            $error = GeminiService::lastError() ?? 'jawaban AI tidak terbaca';
        } else {
            [$answer, $dropped] = $this->check($raw, $facts, $findings);
        }

        $priorities = $answer['priorities'] ?? [];

        return [
            'status'      => $status,
            'question'    => $question,
            'summary'     => $answer['summary'] ?? $this->ruleSummary($facts),
            'priorities'  => $priorities ?: $this->rulePriorities($findings),
            'opportunity' => $answer['opportunity'] ?? null,
            'source'      => $priorities ? 'ai' : 'rules',
            'ai_error'    => $error,
            'dropped'     => $dropped,
            'facts'       => $facts,
            'findings'    => $findings,
        ];
    }

    // =================================================================
    // Temuan (tanpa AI)
    // =================================================================

    /**
     * Hal yang perlu ditindaklanjuti, paling mendesak dulu (level, lalu nominal).
     *
     * @return list<array{key: string, level: string, title: string, fact: string, action: string, pic: string, when: string, amount: float}>
     */
    public function findings(array $facts): array
    {
        $rp       = fn ($amount) => Money::rupiah($amount);
        $period   = $facts['period'];
        $findings = [];
        $add      = function (string $key, string $level, string $title, string $fact, string $action, string $pic, string $when, float $amount) use (&$findings) {
            $findings[] = compact('key', 'level', 'title', 'fact', 'action', 'pic', 'when', 'amount');
        };

        // Piutang lewat jatuh tempo.
        $receivables = $facts['receivables'];
        if ($receivables['overdue']['count'] > 0) {
            $old  = $receivables['aging']['d61_90']['amount'] + $receivables['aging']['d90_plus']['amount'];
            $top  = $receivables['top_overdue'][0];
            $add(
                'piutang_telat',
                $old > 0 ? 'kritis' : 'waspada',
                'Tagih piutang yang lewat jatuh tempo',
                "{$rp($receivables['overdue']['amount'])} dari {$receivables['overdue']['count']} invoice lewat jatuh tempo"
                    . ($old > 0 ? ", {$rp($old)} di antaranya lebih dari 60 hari" : '')
                    . ". Terbesar {$top['invoice']} ({$top['client']}) {$rp($top['remaining'])}, telat {$top['days_overdue']} hari"
                    . ($top['status'] === 'draft' ? ', statusnya masih draft' : '') . '.',
                $top['status'] === 'draft'
                    ? "Pastikan {$top['invoice']} sudah dikirim ke {$top['client']}; bila sudah, ubah statusnya dan minta tanggal transfer pasti."
                    : "Hubungi {$top['client']} untuk {$top['invoice']} dan minta tanggal transfer pasti; kirim ulang invoice berikut nomor rekening.",
                'Finance',
                'hari ini',
                $receivables['overdue']['amount'],
            );
        }

        // DP lunas yang belum dibuatkan pelunasan.
        $dp = $facts['dp_unbilled'];
        if ($dp['done_count'] > 0) {
            $top = collect($dp['top'])->firstWhere('project_done', true);
            $add(
                'pelunasan_selesai',
                'waspada',
                'Tagih pelunasan project yang sudah selesai',
                "{$dp['done_count']} project selesai dengan DP lunas belum dibuatkan pelunasan: {$rp($dp['done_amount'])} belum ditagih. Terbesar {$top['invoice']} ({$top['client']}) {$rp($top['unbilled'])}.",
                "Buka {$top['invoice']} di ERP, tekan Buat pelunasan, lalu kirim ke {$top['client']}.",
                'Finance',
                'hari ini',
                $dp['done_amount'],
            );
        } elseif ($dp['count'] > 0) {
            $top = $dp['top'][0];
            $add(
                'pelunasan_berjalan',
                'info',
                'Siapkan pelunasan dari DP yang sudah lunas',
                "{$dp['count']} invoice DP lunas belum punya pelunasan: sisa nilai pekerjaan {$rp($dp['amount'])} belum ditagih. Terbesar {$top['invoice']} ({$top['client']}) {$rp($top['unbilled'])}"
                    . ($top['days_since_dp'] !== null ? ", DP {$top['days_since_dp']} hari lalu" : '') . '.',
                "Cek progres project {$top['client']} dengan PM dan buat pelunasan begitu pekerjaan diserahkan.",
                'PM',
                'minggu ini',
                $dp['amount'],
            );
        }

        // Saldo kas dibanding pengeluaran rata-rata.
        $cash = $facts['cash'];
        if ($cash['runway_months'] !== null && $cash['runway_months'] < 2) {
            $add(
                'kas_menipis',
                $cash['runway_months'] < 1 ? 'kritis' : 'waspada',
                'Jaga saldo kas yang menipis',
                "Saldo kas tercatat {$rp($cash['balance'])}, cukup ±" . self::decimal($cash['runway_months']) . " bulan dengan rata-rata uang keluar {$rp($cash['avg_out'])} per bulan.",
                $receivables['total']['amount'] > 0
                    ? "Tunda pengeluaran yang bisa menunggu dan dahulukan penagihan piutang {$rp($receivables['total']['amount'])}."
                    : 'Tunda pengeluaran yang bisa menunggu sampai ada uang masuk baru.',
                'Director',
                'minggu ini',
                $cash['balance'],
            );
        }

        if ($cash['out'] > $cash['in'] && $cash['out'] > 0) {
            $add(
                'kas_minus',
                'waspada',
                'Uang keluar lebih besar dari uang masuk',
                "{$period['label']}: masuk {$rp($cash['in'])}, keluar {$rp($cash['out'])} (periode yang sama bulan lalu masuk {$rp($cash['prev_in'])}, keluar {$rp($cash['prev_out'])}).",
                'Cocokkan jadwal pembayaran keluar dengan piutang yang jatuh tempo; tagih dulu sebelum membayar yang bisa menunggu.',
                'Finance',
                'minggu ini',
                $cash['out'] - $cash['in'],
            );
        }

        // Tagihan baru turun dibanding tanggal yang sama bulan lalu.
        $billing  = $facts['billing'];
        $approved = $facts['pipeline']['quotes_approved'];
        $pending  = $facts['pipeline']['quotes_pending'];
        if ($period['day_of_month'] >= 7 && $billing['prev_billed'] > 0 && $billing['billed'] < 0.7 * $billing['prev_billed']) {
            $add(
                'tagihan_turun',
                'waspada',
                'Kejar tagihan baru yang turun',
                "Ditagih {$period['label']} {$rp($billing['billed'])}, turun " . abs((int) $billing['change_pct']) . "% dari {$period['compare_label']} ({$rp($billing['prev_billed'])}).",
                $approved['count'] > 0
                    ? "Jadikan {$approved['count']} quotation approved ({$rp($approved['amount'])}) proforma atau invoice minggu ini."
                    : ($pending['count'] > 0 ? "Follow up {$pending['count']} quotation pending ({$rp($pending['amount'])}) sampai ada keputusan." : 'Cari project baru atau upsell ke klien aktif bulan ini.'),
                'Sales',
                'minggu ini',
                $billing['prev_billed'] - $billing['billed'],
            );
        }

        // Quotation approved yang belum menjadi tagihan.
        if ($approved['count'] > 0) {
            $top = $approved['top'][0];
            $add(
                'quotation_approved',
                ($top['days'] ?? 0) > 7 ? 'waspada' : 'info',
                'Ubah quotation approved jadi tagihan',
                "{$approved['count']} quotation approved belum dijadikan invoice: {$rp($approved['amount'])}. Terbesar {$top['number']} ({$top['client']}) {$rp($top['value'])}"
                    . ($top['days'] !== null ? ", {$top['days']} hari" : '') . '.',
                "Buat proforma/invoice DP dari {$top['number']} dan kirim ke {$top['client']}.",
                'Finance',
                'hari ini',
                $approved['amount'],
            );
        }

        // Proforma yang belum dibayar.
        $proforma = $facts['pipeline']['proforma'];
        if ($proforma['count'] > 0) {
            $top = $proforma['top'][0];
            $add(
                'proforma',
                ($top['days'] ?? 0) > 14 ? 'waspada' : 'info',
                'Follow up proforma yang belum dibayar',
                "{$proforma['count']} proforma menunggu pembayaran: {$rp($proforma['amount'])}. Terbesar {$top['invoice']} ({$top['client']}) {$rp($top['remaining'])}"
                    . ($top['days'] !== null ? ", {$top['days']} hari" : '') . '.',
                "Konfirmasi jadwal transfer {$top['client']} untuk {$top['invoice']}.",
                'Finance',
                'minggu ini',
                $proforma['amount'],
            );
        }

        // Expense naik tajam.
        $expenses = $facts['expenses'];
        if ($expenses['prev_total'] > 0 && $expenses['total'] > 1.3 * $expenses['prev_total'] && $expenses['total'] - $expenses['prev_total'] >= 1_000_000) {
            $category = $expenses['top_categories'][0] ?? null;
            $add(
                'expense_naik',
                'waspada',
                'Periksa expense yang naik tajam',
                "Expense {$period['label']} {$rp($expenses['total'])}, naik {$expenses['change_pct']}% dari {$period['compare_label']} ({$rp($expenses['prev_total'])})"
                    . ($category ? ". Terbesar {$category['label']} {$rp($category['amount'])}" : '') . '.',
                $category
                    ? "Periksa pengajuan kategori {$category['label']}; tahan yang tidak terkait project berbayar."
                    : 'Periksa pengajuan expense terbesar bulan ini; tahan yang tidak terkait project berbayar.',
                'Director',
                'minggu ini',
                $expenses['total'] - $expenses['prev_total'],
            );
        }

        // Advance yang lama belum dipertanggungjawabkan.
        $advances = $facts['advances'];
        if ($advances['stale']['count'] > 0) {
            $top = collect($advances['top'])->first(fn (array $row) => ($row['days'] ?? 0) > MetricsEngine::STALE_ADVANCE_DAYS);
            $add(
                'advance_macet',
                'waspada',
                'Minta realisasi advance yang tertunda',
                "{$advances['stale']['count']} advance lebih dari " . MetricsEngine::STALE_ADVANCE_DAYS . " hari belum dipertanggungjawabkan: {$rp($advances['stale']['amount'])}"
                    . ($top ? ". Terbesar {$top['user']} ({$top['project']}) {$rp($top['outstanding'])}, {$top['days']} hari" : '') . '.',
                ($top ? "Minta {$top['user']}" : 'Minta pemegang advance') . ' mengirim realisasi lewat bot pengajuan dan mengembalikan sisa dananya.',
                'Finance',
                'minggu ini',
                $advances['stale']['amount'],
            );
        }

        if (($advances['owed']['count'] ?? 0) > 0) {
            $add(
                'advance_belum_dibayar',
                'info',
                'Bayar realisasi advance ke karyawan',
                "{$advances['owed']['count']} advance bayar paralel punya realisasi yang belum dibayar ke karyawan: {$rp($advances['owed']['amount'])}.",
                'Bayar lewat ERP (Realisasi → Bayar) atau unggah mutasi transfernya di /mutasi, lalu selesaikan advance-nya.',
                'Finance',
                'minggu ini',
                $advances['owed']['amount'],
            );
        }

        if ($advances['overrun']['count'] > 0) {
            $add(
                'advance_lebih',
                'info',
                'Putuskan realisasi yang melebihi advance',
                "{$advances['overrun']['count']} advance realisasinya melebihi dana yang dicairkan: {$rp($advances['overrun']['amount'])}.",
                'Putuskan lewat /verifikasi: setujui kelebihannya atau minta penjelasan.',
                'Director',
                'minggu ini',
                $advances['overrun']['amount'],
            );
        }

        // Pengajuan yang menunggu terlalu lama.
        $waiting = $facts['pending'];
        $count   = $waiting['expense']['count'] + $waiting['advance']['count'];
        $oldest  = max($waiting['expense']['oldest_days'] ?? 0, $waiting['advance']['oldest_days'] ?? 0);
        if ($count > 0 && $oldest > 3) {
            $add(
                'pengajuan_menunggu',
                'info',
                'Putuskan pengajuan yang menunggu',
                "{$count} pengajuan menunggu ({$rp($waiting['expense']['amount'] + $waiting['advance']['amount'])}), yang terlama {$oldest} hari.",
                'Putuskan lewat /persetujuan supaya pekerjaan tim tidak tertahan.',
                'Director',
                'hari ini',
                $waiting['expense']['amount'] + $waiting['advance']['amount'],
            );
        }

        // Tagihan yang segera jatuh tempo.
        if ($soon = $receivables['due_soon']) {
            $first = $soon[0];
            $add(
                'jatuh_tempo',
                'info',
                'Ingatkan klien sebelum jatuh tempo',
                count($soon) . " invoice jatuh tempo dalam 7 hari: {$rp(array_sum(array_column($soon, 'remaining')))}. Terdekat {$first['invoice']} ({$first['client']}) {$rp($first['remaining'])}, "
                    . ($first['days_to_due'] === 0 ? 'hari ini' : "{$first['days_to_due']} hari lagi") . '.',
                "Kirim pengingat ke {$first['client']} sebelum jatuh tempo.",
                'Finance',
                'minggu ini',
                array_sum(array_column($soon, 'remaining')),
            );
        }

        usort($findings, fn (array $a, array $b) => [self::LEVELS[$b['level']], $b['amount']] <=> [self::LEVELS[$a['level']], $a['amount']]);

        return $findings;
    }

    public static function status(array $findings): string
    {
        $levels = array_column($findings, 'level');

        return match (true) {
            in_array('kritis', $levels, true)  => 'kritis',
            in_array('waspada', $levels, true) => 'waspada',
            default                            => 'sehat',
        };
    }

    private function ruleSummary(array $facts): string
    {
        $rp = fn ($amount) => Money::rupiah($amount);

        return "{$facts['period']['label']}: uang masuk {$rp($facts['cash']['in'])}, keluar {$rp($facts['cash']['out'])}; "
            . "piutang {$rp($facts['receivables']['total']['amount'])}, {$rp($facts['receivables']['overdue']['amount'])} lewat jatuh tempo.";
    }

    private function rulePriorities(array $findings): array
    {
        return array_map(
            fn (array $finding) => array_intersect_key($finding, array_flip(['title', 'fact', 'action', 'pic', 'when'])),
            array_slice($findings, 0, self::MAX_PRIORITIES)
        );
    }

    // =================================================================
    // Pemeriksaan jawaban AI
    // =================================================================

    /**
     * Ambil butir jawaban AI yang nominal dan nomor dokumennya ada di fakta.
     *
     * @return array{0: array{summary: ?string, priorities: list<array>, opportunity: ?string}, 1: int} jawaban bersih, jumlah yang dibuang
     */
    private function check(array $raw, array $facts, array $findings): array
    {
        $amounts = self::moneyValues($facts);
        foreach ($findings as $finding) {
            foreach (self::amountsIn("{$finding['fact']} {$finding['action']}") as [$value]) {
                $amounts[] = $value;
            }
        }
        $refs    = array_flip(self::references($facts));
        $dropped = 0;
        $valid   = fn (string $text) => self::grounded($text, $amounts, $refs);

        $priorities = [];
        foreach (array_slice((array) ($raw['prioritas'] ?? []), 0, 6) as $item) {
            $item = [
                'title'  => self::clean($item['judul'] ?? '', 90),
                'fact'   => self::clean($item['fakta'] ?? '', 280),
                'action' => self::clean($item['aksi'] ?? '', 260),
                'pic'    => in_array($item['pic'] ?? null, self::PICS, true) ? $item['pic'] : 'Finance',
                'when'   => self::clean($item['tenggat'] ?? '', 30) ?: 'minggu ini',
            ];

            if ($item['title'] === '' || $item['action'] === '' || ! $valid("{$item['title']} {$item['fact']} {$item['action']}")) {
                $dropped++;

                continue;
            }

            $priorities[] = $item;
        }

        $text = function (string $key, int $limit) use ($raw, $valid, &$dropped): ?string {
            $value = self::clean($raw[$key] ?? '', $limit);

            if ($value !== '' && ! $valid($value)) {
                $dropped++;

                return null;
            }

            return $value !== '' ? $value : null;
        };

        return [[
            'summary'     => $text('ringkasan', 400),
            'priorities'  => array_slice($priorities, 0, self::MAX_PRIORITIES),
            'opportunity' => $text('peluang', 300),
        ], $dropped];
    }

    /**
     * Setiap nominal Rupiah dan nomor invoice/quotation di $text ada di data.
     *
     * @param  list<float>  $amounts
     * @param  array<string, int>  $refs
     */
    public static function grounded(string $text, array $amounts, array $refs): bool
    {
        foreach (self::amountsIn($text) as [$value, $tolerance]) {
            $known = false;
            foreach ($amounts as $amount) {
                if (abs($amount - $value) <= max($tolerance, 1)) {
                    $known = true;

                    break;
                }
            }

            if (! $known) {
                return false;
            }
        }

        preg_match_all('/#[A-Z]{1,5}\/[0-9A-Z]+(?:\/[0-9A-Z]+)*/u', $text, $matches);

        foreach ($matches[0] as $ref) {
            if (! isset($refs[$ref])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Nominal Rupiah di teks dengan toleransinya: "Rp 12.500.000" persis,
     * "Rp 12,5 jt" ± Rp 50.000 (setengah digit terakhir yang ditulis).
     *
     * @return list<array{0: float, 1: float}>
     */
    public static function amountsIn(string $text): array
    {
        preg_match_all('/Rp\.?\s?(\d[\d.,]*)(?:\s?(jt|juta|rb|ribu|miliar|milyar|M)\b)?/iu', $text, $matches, PREG_SET_ORDER);

        $amounts = [];
        foreach ($matches as $match) {
            $raw  = rtrim($match[1], '.,');
            $unit = Str::lower($match[2] ?? '');

            if ($unit !== '') {
                $multiplier = match ($unit) {
                    'jt', 'juta' => 1_000_000,
                    'rb', 'ribu' => 1_000,
                    default      => 1_000_000_000,
                };

                $number = match (true) {
                    (bool) preg_match('/^\d{1,3}(\.\d{3})+$/', $raw) => str_replace('.', '', $raw),               // "1.250" jt: ribuan
                    str_contains($raw, ',')                         => str_replace(',', '.', str_replace('.', '', $raw)), // "12,5" jt
                    default                                         => $raw,                                        // "12.5" / "12" jt
                };
                $decimals = str_contains($number, '.') ? strlen(Str::after($number, '.')) : 0;

                $amounts[] = [(float) $number * $multiplier, 0.5 * 10 ** -$decimals * $multiplier];

                continue;
            }

            $value = match (true) {
                (bool) preg_match('/^(\d{1,3}(?:\.\d{3})+)(?:,\d{1,2})?$/', $raw, $part) => (float) str_replace('.', '', $part[1]),
                (bool) preg_match('/^(\d{1,3}(?:,\d{3})+)(?:\.\d{1,2})?$/', $raw, $part) => (float) str_replace(',', '', $part[1]),
                default                                                                => (float) str_replace(['.', ','], ['', '.'], $raw),
            };

            $amounts[] = [$value, 1.0];
        }

        return $amounts;
    }

    /**
     * Semua nominal di fakta (nilai mutlak), dari kunci MetricsEngine::MONEY_KEYS.
     *
     * @return list<float>
     */
    public static function moneyValues(array $facts): array
    {
        $values = [];

        array_walk_recursive($facts, function ($value, $key) use (&$values) {
            if (in_array($key, MetricsEngine::MONEY_KEYS, true) && (is_int($value) || is_float($value))) {
                $values[] = abs((float) $value);
            }
        });

        return $values;
    }

    /**
     * Fakta dengan nominal berformat "Rp 1.250.000" untuk AI.
     */
    public static function display(array $facts): array
    {
        foreach ($facts as $key => $value) {
            if (is_array($value)) {
                $facts[$key] = self::display($value);
            } elseif (is_string($key) && in_array($key, MetricsEngine::MONEY_KEYS, true) && (is_int($value) || is_float($value))) {
                $facts[$key] = Money::signed($value);
            }
        }

        return $facts;
    }

    /**
     * Nomor invoice dan quotation yang ada di fakta.
     *
     * @return list<string>
     */
    private static function references(array $facts): array
    {
        $refs = [];

        array_walk_recursive($facts, function ($value) use (&$refs) {
            if (is_string($value) && preg_match('/^#[A-Z]{1,5}\//u', $value)) {
                $refs[] = $value;
            }
        });

        return $refs;
    }

    private static function clean(mixed $text, int $limit): string
    {
        $text = strip_tags(is_string($text) ? $text : '');
        $text = preg_replace(['/[*_`]+/', '/^\s*[-•]\s*/u', '/\s+/u'], ['', '', ' '], $text);

        return Str::limit(trim($text), $limit, '…');
    }

    private static function decimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',');
    }

    // =================================================================
    // Pesan
    // =================================================================

    /**
     * Pesan Telegram (HTML) dari hasil analyze(), di bawah batas 4096 karakter
     * Telegram: bila terlalu panjang, prioritas terakhir lalu peluang dan
     * catatan data dilepas.
     */
    public function message(array $analysis): string
    {
        $message = $this->render($analysis);

        while (mb_strlen($message) > self::MESSAGE_LIMIT) {
            if (count($analysis['priorities']) > 2) {
                array_pop($analysis['priorities']);
            } elseif ($analysis['opportunity'] !== null) {
                $analysis['opportunity'] = null;
            } elseif (empty($analysis['hide_notes'])) {
                $analysis['hide_notes'] = true;
            } else {
                $analysis['priorities'] = array_slice($analysis['priorities'], 0, 1);
                $analysis['question']   = $analysis['question'] !== null ? Str::limit($analysis['question'], 80) : null;
                $message                = $this->render($analysis);

                break;
            }

            $message = $this->render($analysis);
        }

        return $message;
    }

    private function render(array $analysis): string
    {
        $facts = $analysis['facts'];
        $rp    = fn ($amount) => Money::rupiah($amount);
        $today = CarbonImmutable::parse($facts['period']['today'])->locale('id')->translatedFormat('j M Y');

        $lines = [
            "🧭 <b>Analisa keuangan</b> · {$today}",
            self::STATUS_ICONS[$analysis['status']] . ' Status: <b>' . Str::upper($analysis['status']) . '</b>',
        ];

        if ($analysis['question'] !== null) {
            $lines[] = '❓ <i>' . e($analysis['question']) . '</i>';
        }

        $lines[] = e($analysis['summary']);
        $lines[] = '';

        if ($analysis['priorities']) {
            $lines[] = '<b>Prioritas</b>';

            foreach ($analysis['priorities'] as $i => $priority) {
                $lines[] = '<b>' . ($i + 1) . '. ' . e($priority['title']) . '</b>';
                if ($priority['fact'] !== '') {
                    $lines[] = e($priority['fact']);
                }
                $lines[] = '👉 ' . e($priority['action']) . ' <i>(' . e($priority['pic']) . ', ' . e($priority['when']) . ')</i>';
                $lines[] = '';
            }
        } else {
            $lines[] = '✅ Tidak ada yang mendesak saat ini.';
            $lines[] = '';
        }

        $cash  = $facts['cash'];
        $lines[] = '<b>Angka kunci</b> · ' . e($facts['period']['label']) . ' <i>(vs ' . e($facts['period']['compare_label']) . ')</i>';
        $lines[] = "• Uang masuk {$rp($cash['in'])} <i>({$rp($cash['prev_in'])})</i> · keluar {$rp($cash['out'])} <i>({$rp($cash['prev_out'])})</i>";
        $lines[] = "• Ditagih {$rp($facts['billing']['billed'])} <i>({$rp($facts['billing']['prev_billed'])})</i> · expense {$rp($facts['expenses']['total'])} <i>({$rp($facts['expenses']['prev_total'])})</i>";
        $lines[] = "• Piutang {$rp($facts['receivables']['total']['amount'])} · lewat jatuh tempo {$rp($facts['receivables']['overdue']['amount'])}";
        $lines[] = "• Saldo kas tercatat {$rp($cash['balance'])}" . ($cash['runway_months'] !== null ? ' · ±' . self::decimal($cash['runway_months']) . ' bulan pengeluaran' : '');

        if ($facts['dp_unbilled']['amount'] > 0) {
            $lines[] = "• Pelunasan belum ditagih {$rp($facts['dp_unbilled']['amount'])}";
        }

        if ($facts['advances']['outstanding']['amount'] > 0) {
            $lines[] = "• Advance belum dipertanggungjawabkan {$rp($facts['advances']['outstanding']['amount'])}";
        }

        if ($analysis['opportunity']) {
            $lines[] = '';
            $lines[] = '💡 ' . e($analysis['opportunity']);
        }

        if (empty($analysis['hide_notes']) && ($notes = self::dataNotes($facts['data_quality']))) {
            $lines[] = '';
            $lines[] = '<i>Catatan data: ' . e(implode('; ', $notes)) . '.</i>';
        }

        $lines[] = '';
        $lines[] = match (true) {
            $analysis['source'] === 'ai' => '<i>Angka dihitung sistem dari data ERP; prioritas disusun AI dan diperiksa ulang.</i>',
            $analysis['question'] !== null => '<i>Pertanyaan belum bisa dijawab AI (' . e($analysis['ai_error'] ?? 'jawabannya tidak lolos pemeriksaan angka') . '); prioritas dari aturan sistem.</i>',
            default => '<i>Prioritas dari aturan sistem' . ($analysis['ai_error'] ? ' (AI tidak tersedia: ' . e($analysis['ai_error']) . ')' : '') . '.</i>',
        };

        if ($analysis['dropped'] > 0) {
            $lines[] = "<i>{$analysis['dropped']} saran AI dibuang karena memakai angka atau nomor yang tidak ada di data.</i>";
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    public static function dataNotes(array $quality): array
    {
        $rp    = fn ($amount) => Money::rupiah($amount);
        $notes = [];

        if ($quality['paid_without_cash']['count'] > 0) {
            $notes[] = "{$quality['paid_without_cash']['count']} invoice tercatat dibayar tanpa uang masuk di rekening ERP ({$rp($quality['paid_without_cash']['amount'])}), jadi uang masuk bisa lebih kecil dari kenyataan";
        }

        if ($quality['paid_not_marked']['count'] > 0) {
            $notes[] = "{$quality['paid_not_marked']['count']} invoice sudah lunas tapi statusnya belum paid";
        }

        if ($quality['overpaid']['count'] > 0) {
            $notes[] = "{$quality['overpaid']['count']} invoice dibayar melebihi tagihannya ({$rp($quality['overpaid']['amount'])})";
        }

        if ($quality['unlinked_finals']['count'] > 0) {
            $notes[] = "{$quality['unlinked_finals']['count']} pelunasan lama belum ditautkan ke invoice DP-nya";
        }

        return $notes;
    }

    /**
     * Ringkasan lima baris (STATUS / TREN UTAMA / ...) untuk tabel financial_ai_reports.
     */
    public static function plainSummary(array $analysis): string
    {
        $first = $analysis['priorities'][0] ?? null;
        $line  = fn (?string $text) => trim(preg_replace('/\s+/u', ' ', (string) $text)) ?: '-';

        return implode("\n", [
            'STATUS: ' . Str::upper($analysis['status']),
            'TREN UTAMA: ' . $line($analysis['summary']),
            'RISIKO TERBESAR: ' . $line($first ? "{$first['title']}. {$first['fact']}" : null),
            'AKSI 7 HARI: ' . $line(implode(' | ', array_column($analysis['priorities'], 'action'))),
            'PELUANG STRATEGIS: ' . $line($analysis['opportunity']),
        ]);
    }
}
