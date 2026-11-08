<?php

namespace App\Services\BankStatement;

use App\Helpers\ClientLabel;
use App\Models\Advance;
use App\Models\BankMutation;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PaymentAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InvoicePayment;
use App\Services\Telegram\Text;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Mencarikan pasangan setiap baris mutasi di ERP.
 *
 * Uang masuk → invoice penjualan yang belum lunas. Uang keluar → expense dan
 * advance yang sudah Disetujui. Sinyalnya: nominal (sama dengan sisa tagihan
 * atau nominal pengajuan, gabungan beberapa invoice klien yang sama, potongan
 * PPh 23, pembayaran sebagian), nomor invoice dan nama klien/karyawan di
 * berita transfer, serta tanggal.
 *
 * Status tiap baris:
 * - sure      satu pasangan, nominal sama dan nama/nomor cocok: ikut tombol Catat;
 * - review    ada kandidat tapi belum pasti: dipilih satu per satu;
 * - none      tidak ada kandidat;
 * - recorded  sudah ada transaksi senilai itu di rekening ini (dicatat manual di ERP);
 * - duplicate sudah dicatat dari unggahan mutasi sebelumnya;
 * - fee       biaya admin, pajak, atau bunga bank.
 *
 * Tidak ada yang diubah di sini; pencatatan di MutationRecorder.
 */
class MutationMatcher
{
    // Selisih pembulatan rupiah yang masih dianggap sama.
    private const TOLERANCE = 1.0;

    private const PPH23 = 0.02;

    // Kata umum di nama badan usaha dan sapaan: tidak membedakan klien.
    private const STOPWORDS = [
        'pt', 'cv', 'tbk', 'persero', 'ud', 'pd', 'toko', 'the', 'dan', 'and', 'indonesia', 'indo', 'group', 'grup', 'co', 'ltd', 'inc', 'corp',
        'bapak', 'ibu', 'pak', 'bu', 'mr', 'mrs', 'ms', 'sdr', 'sdri', 'tn', 'ny', 'koperasi', 'yayasan', 'kreatif', 'creative', 'digital', 'media',
        'solusi', 'solution', 'solutions', 'sejahtera', 'abadi', 'jaya', 'makmur', 'sentosa', 'utama', 'global', 'internasional', 'international',
        'teknologi', 'technology', 'nusantara', 'prima', 'mitra', 'karya', 'cipta', 'bersama', 'berkah', 'agency',
    ];

    // Kata dari bank di berita transfer, bukan nama pengirim/penerima.
    private const NOISE = [
        'trsf', 'trf', 'transfer', 'banking', 'ebanking', 'cr', 'db', 'bi', 'fast', 'bifast', 'bif', 'ke', 'dari', 'setoran', 'setor', 'tunai',
        'kliring', 'rtgs', 'skn', 'llg', 'mandiri', 'bca', 'bri', 'bni', 'bsi', 'cimb', 'niaga', 'permata', 'danamon', 'btn', 'mybca', 'livin',
        'brimo', 'klikbca', 'atm', 'va', 'virtual', 'account', 'pembayaran', 'bayar', 'payment', 'invoice', 'inv', 'ftscy', 'ftfva', 'ws', 'switching',
        'kr', 'otomatis', 'pemindahan', 'dana', 'antar', 'rek', 'rekening', 'mbank', 'internet', 'mobile', 'cms', 'kbb', 'byr', 'pemb', 'tf',
    ];

    private const FEE_OUT = [
        '/\bBIAYA\s+(ADM|ADMIN|ADMINISTRASI|TXN|TRANSAKSI|TRANSFER|TRF|KARTU|MATERAI|METERAI|PROVISI|RTGS|SKN|BI-?FAST|LAYANAN|BULANAN|PENUTUPAN)\b/',
        '/\b(ADM|ADMIN)\s*(BANK|BULANAN|REK|REKENING|KARTU)\b/',
        '/\bBY\.?\s*(ADM|ADMIN|TRANSFER|TRF|TXN|LAYANAN)\b/',
        '/\b(PAJAK|PPH)\s*(BUNGA|JASA\s*GIRO)\b/',
        '/^\s*(BIAYA\s*ADM|ADM\b|ADMINISTRASI\b|PAJAK\b|BEA\s*METERAI|METERAI|MATERAI)/',
    ];

    private const FEE_IN = [
        '/^\s*(BUNGA|JASA\s*GIRO|INTEREST|KREDIT\s*BUNGA|BUNGA\s*TABUNGAN)\b/',
        '/\bBUNGA\s*(TABUNGAN|REK|REKENING|GIRO|DEPOSITO)\b/',
        '/\bJASA\s*GIRO\b/',
    ];

    /**
     * @param  array<int, array{key: string, date: string, description: string, amount: float, direction: string}>  $rows
     * @return array<int, array{status: string, pick: ?int, candidates: array<int, array{targets: array, label: string, note: ?string, identity: bool}>, note: ?string}>
     */
    public function match(array $rows, PaymentAccount $account): array
    {
        if ($rows === []) {
            return [];
        }

        $processed = BankMutation::where('payment_account_id', $account->id)
            ->whereIn('fingerprint', array_column($rows, 'key'))
            ->pluck('fingerprint')
            ->flip();

        $recorded = $this->recordedTransactions($rows, $account, $processed);

        $invoices = Invoice::where('type', 'sale')->whereNotIn('status', ['paid', 'cancelled'])->get()
            ->filter(fn (Invoice $invoice) => InvoicePayment::isPayable($invoice))
            ->values();
        $payees = Expense::with('user')->where('status', 'Disetujui')->get()
            ->concat(Advance::with('user')->where('status', 'Disetujui')->get())
            ->values();

        $results = [];

        foreach ($rows as $index => $row) {
            $results[$index] = match (true) {
                $processed->has($row['key']) => $this->result('duplicate', note: 'Sudah dicatat dari unggahan mutasi sebelumnya.'),
                isset($recorded[$index])      => $this->result('recorded', note: $recorded[$index]),
                $this->isFee($row)            => $this->result('fee', note: 'Biaya, pajak, atau bunga bank.'),
                $row['direction'] === 'in'    => $this->classify($this->invoiceCandidates($row, $invoices)),
                default                       => $this->classify($this->payoutCandidates($row, $payees)),
            };
        }

        return $results;
    }

    // =================================================================
    // Sudah tercatat
    // =================================================================

    /**
     * Mutasi yang sudah punya transaksi senilai di rekening ini (dicatat manual
     * di ERP, biasanya beberapa hari setelah uangnya masuk). Satu transaksi
     * hanya dipakai satu mutasi; yang namanya cocok dan tanggalnya terdekat
     * didahulukan. Lebih baik melewatkan daripada mencatat dua kali.
     *
     * @return array<int, string> indeks baris => keterangan
     */
    private function recordedTransactions(array $rows, PaymentAccount $account, Collection $processed): array
    {
        $dates  = array_column($rows, 'date');
        $window = [Carbon::parse(min($dates))->subDays(5)->toDateString(), Carbon::parse(max($dates))->addDays(30)->toDateString()];

        // Transaksi yang dibuat dari mutasi yang sudah dicatat bukan "dicatat manual".
        $taken = BankMutation::where('payment_account_id', $account->id)
            ->whereBetween('mutation_date', [Carbon::parse($window[0])->subDays(40)->toDateString(), $window[1]])
            ->pluck('targets')->flatten(1)->pluck('transaction_id')->filter()->flip();

        $transactions = Transaction::where('payment_account_id', $account->id)
            ->whereBetween('transaction_date', $window)
            ->get()
            ->reject(fn (Transaction $transaction) => $taken->has($transaction->id));

        $pairs = [];

        foreach ($rows as $index => $row) {
            if ($processed->has($row['key'])) {
                continue;
            }

            foreach ($transactions as $transaction) {
                $days = Carbon::parse($row['date'])->diffInDays(Carbon::parse($transaction->transaction_date), false);

                if ($transaction->type !== $row['direction'] || abs((float) $transaction->amount - $row['amount']) > self::TOLERANCE || $days < -5 || $days > 30) {
                    continue;
                }

                $named   = $this->nameScore($this->words($row['description']), $this->tokens((string) $transaction->description)) > 0;
                $pairs[] = ['row' => $index, 'transaction' => $transaction, 'score' => ($named ? 100 : 0) - abs($days)];
            }
        }

        usort($pairs, fn ($a, $b) => $b['score'] <=> $a['score']);

        $result = [];
        $used   = [];

        foreach ($pairs as $pair) {
            if (isset($result[$pair['row']]) || isset($used[$pair['transaction']->id])) {
                continue;
            }

            $used[$pair['transaction']->id] = true;
            $result[$pair['row']]           = 'Sudah tercatat di ERP: ' . Text::short((string) $pair['transaction']->description, 70)
                . ' (' . Carbon::parse($pair['transaction']->transaction_date)->locale('id')->translatedFormat('j M') . ').';
        }

        return $result;
    }

    private function isFee(array $row): bool
    {
        $text = mb_strtoupper($row['description']);

        foreach ($row['direction'] === 'in' ? self::FEE_IN : self::FEE_OUT as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    // =================================================================
    // Uang masuk → invoice
    // =================================================================

    /**
     * @param  Collection<int, Invoice>  $invoices
     */
    private function invoiceCandidates(array $row, Collection $invoices): array
    {
        $amount     = $row['amount'];
        $words      = $this->words($row['description']);
        $compact    = $this->compact($row['description']);
        $candidates = [];

        foreach ($invoices as $invoice) {
            $remaining = InvoicePayment::remaining($invoice);
            $number    = $this->numberMatch($invoice->uuid, $compact, $words);
            $name      = $this->nameScore($words, $this->tokens($invoice->nama_perusahaan . ' ' . $invoice->nama_pic));
            $identity  = $number || $name > 0;
            $exact     = abs($amount - $remaining) <= self::TOLERANCE;
            $pph       = ! $exact && abs($amount - ($remaining - $this->pph23($invoice, $remaining))) <= self::TOLERANCE;
            $partial   = ! $exact && ! $pph && $amount < $remaining && $identity;

            if (! $exact && ! $pph && ! $partial) {
                continue;
            }

            $note = match (true) {
                $pph     => 'nominal = sisa tagihan dipotong PPh 23 (2%); invoice tetap Sebagian dengan sisa ' . $this->rupiah($remaining - $amount),
                $partial => 'pembayaran sebagian; sisa setelah ini ' . $this->rupiah($remaining - $amount),
                default  => null,
            };

            $candidates[] = [
                'targets'  => [['type' => 'invoice', 'id' => $invoice->id, 'amount' => round($amount, 2)]],
                'label'    => $this->invoiceLabel($invoice, $remaining),
                'note'     => $note,
                'identity' => $identity,
                'exact'    => $exact,
                'score'    => ($exact ? 100 : ($pph ? 60 : 20)) + ($number ? 50 : 0) + 30 * min($name, 2) - $this->datePenalty($row['date'], $invoice->date),
            ];
        }

        // Satu transfer untuk beberapa invoice klien yang sama.
        if (! array_filter($candidates, fn ($candidate) => $candidate['exact'])) {
            $candidates = array_merge($candidates, $this->invoiceCombos($row, $invoices, $words, $compact));
        }

        return $candidates;
    }

    /**
     * Gabungan 2–4 invoice satu klien yang sisanya pas dengan nominal transfer.
     *
     * @param  Collection<int, Invoice>  $invoices
     */
    private function invoiceCombos(array $row, Collection $invoices, array $words, string $compact): array
    {
        $combos = [];

        foreach ($invoices->groupBy(fn (Invoice $invoice) => mb_strtolower(trim((string) $invoice->nama_perusahaan))) as $client => $group) {
            if ($client === '' || $group->count() < 2 || $group->count() > 12) {
                continue;
            }

            foreach ($this->subsets($group->values()->all(), fn (Invoice $invoice) => InvoicePayment::remaining($invoice), $row['amount']) as $subset) {
                $collection = collect($subset);
                $identity   = $this->nameScore($words, $this->tokens($subset[0]->nama_perusahaan . ' ' . $subset[0]->nama_pic)) > 0
                    || $collection->contains(fn (Invoice $invoice) => $this->numberMatch($invoice->uuid, $compact, $words));

                $combos[] = [
                    'targets'  => $collection->map(fn (Invoice $invoice) => ['type' => 'invoice', 'id' => $invoice->id, 'amount' => InvoicePayment::remaining($invoice)])->all(),
                    'label'    => count($subset) . ' invoice ' . Text::short(ClientLabel::invoice($subset[0]), 40) . ': ' . $collection->pluck('uuid')->implode(', '),
                    'note'     => 'satu transfer untuk ' . count($subset) . ' invoice; semuanya jadi lunas',
                    'identity' => $identity,
                    'exact'    => true,
                    'combo'    => true,
                    'score'    => 90 + ($identity ? 40 : 0),
                ];
            }
        }

        return $combos;
    }

    private function pph23(Invoice $invoice, float $remaining): float
    {
        $total = (float) $invoice->grand_total;
        $dpp   = max((float) $invoice->subtotal - (float) $invoice->total_discount, 0);

        return $total > 0 ? round(self::PPH23 * $dpp * ($remaining / $total), 2) : 0.0;
    }

    /**
     * Nomor invoice di berita transfer: utuh ("SC013261100012"), kode bulan +
     * urutan ("0132611" dan "00012" / "12"), atau dengan pemisah apa pun.
     */
    private function numberMatch(string $uuid, string $compact, array $words): bool
    {
        if (! preg_match('/(\d{7})\D+(\d{3,6})\s*$/', $uuid, $m)) {
            return false;
        }

        [$code, $sequence] = [$m[1], $m[2]];
        $full              = $this->compact($uuid);

        if (str_contains($compact, $full) || str_contains($compact, $code . $sequence)) {
            return true;
        }

        $short = ltrim($sequence, '0');

        return str_contains($compact, $code)
            && (in_array($sequence, $words['numbers'], true) || (strlen($short) >= 2 && in_array($short, $words['numbers'], true)));
    }

    private function invoiceLabel(Invoice $invoice, float $remaining): string
    {
        return $invoice->uuid . ' · ' . Text::short(ClientLabel::invoice($invoice), 40) . ' · sisa ' . $this->rupiah($remaining);
    }

    // =================================================================
    // Uang keluar → expense / advance
    // =================================================================

    /**
     * @param  Collection<int, Expense|Advance>  $payees
     */
    private function payoutCandidates(array $row, Collection $payees): array
    {
        $amount     = $row['amount'];
        $words      = $this->words($row['description']);
        $candidates = [];

        foreach ($payees as $payee) {
            $requested = (float) $payee->amount;
            $name      = $this->nameScore($words, $this->userTokens($payee->user));
            $exact     = abs($amount - $requested) <= self::TOLERANCE;
            $partial   = ! $exact && $amount < $requested && $name > 0;

            if (! $exact && ! $partial) {
                continue;
            }

            $candidates[] = [
                'targets'  => [['type' => $this->type($payee), 'id' => $payee->id, 'amount' => round($amount, 2)]],
                'label'    => $this->payeeLabel($payee),
                'note'     => $partial ? 'dibayar sebagian dari ' . $this->rupiah($requested) : null,
                'identity' => $name > 0,
                'exact'    => $exact,
                'score'    => ($exact ? 100 : 20) + 30 * min($name, 2) - $this->datePenalty($row['date'], $payee->submission_date),
            ];
        }

        // Satu transfer untuk beberapa pengajuan karyawan yang sama.
        if (! array_filter($candidates, fn ($candidate) => $candidate['exact'])) {
            foreach ($payees->groupBy('user_id') as $group) {
                if ($group->count() < 2 || $group->count() > 12) {
                    continue;
                }

                foreach ($this->subsets($group->values()->all(), fn ($payee) => (float) $payee->amount, $amount) as $subset) {
                    $identity     = $this->nameScore($words, $this->userTokens($subset[0]->user)) > 0;
                    $candidates[] = [
                        'targets'  => array_map(fn ($payee) => ['type' => $this->type($payee), 'id' => $payee->id, 'amount' => (float) $payee->amount], $subset),
                        'label'    => count($subset) . ' pengajuan ' . $this->userName($subset[0]->user) . ': ' . implode(', ', array_map(fn ($payee) => ucfirst($this->type($payee)) . " #{$payee->id}", $subset)),
                        'note'     => 'satu transfer untuk ' . count($subset) . ' pengajuan',
                        'identity' => $identity,
                        'exact'    => true,
                        'combo'    => true,
                        'score'    => 90 + ($identity ? 40 : 0),
                    ];
                }
            }
        }

        return $candidates;
    }

    private function type(Expense|Advance $payee): string
    {
        return $payee instanceof Expense ? 'expense' : 'advance';
    }

    private function payeeLabel(Expense|Advance $payee): string
    {
        $what = $payee instanceof Expense ? $payee->keterangan_expense : $payee->keterangan_advance;

        return ucfirst($this->type($payee)) . " #{$payee->id} · " . $this->userName($payee->user) . ' · ' . Text::short((string) $what, 40) . ' · ' . $this->rupiah($payee->amount);
    }

    private function userTokens(?User $user): array
    {
        return $user ? $this->tokens("{$user->firstname} {$user->lastname} {$user->username}") : [];
    }

    private function userName(?User $user): string
    {
        return $user ? (trim("{$user->firstname} {$user->lastname}") ?: (string) $user->username) : '-';
    }

    // =================================================================
    // Keputusan
    // =================================================================

    private function classify(array $candidates): array
    {
        if ($candidates === []) {
            return $this->result('none');
        }

        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);
        $candidates = array_slice($candidates, 0, 6);

        $exact    = array_keys(array_filter($candidates, fn ($candidate) => $candidate['exact'] && empty($candidate['combo'])));
        $combos   = array_keys(array_filter($candidates, fn ($candidate) => ! empty($candidate['combo'])));
        $identity = fn (array $keys) => array_values(array_filter($keys, fn ($key) => $candidates[$key]['identity']));

        $sure = match (true) {
            count($exact) === 1 && $candidates[$exact[0]]['identity'] => $exact[0],
            count($exact) > 1 && count($identity($exact)) === 1       => $identity($exact)[0],
            $exact === [] && count($identity($combos)) === 1 && count($combos) === 1 => $combos[0],
            default                                                   => null,
        };

        $clean = array_map(fn ($candidate) => array_intersect_key($candidate, array_flip(['targets', 'label', 'note', 'identity'])), $candidates);

        return $sure !== null
            ? $this->result('sure', $sure, $clean)
            : $this->result('review', null, $clean);
    }

    private function result(string $status, ?int $pick = null, array $candidates = [], ?string $note = null): array
    {
        return ['status' => $status, 'pick' => $pick, 'candidates' => $candidates, 'note' => $note];
    }

    // =================================================================
    // Alat bantu
    // =================================================================

    /**
     * Himpunan bagian 2–4 anggota yang jumlahnya pas dengan $target.
     *
     * @return array<int, array<int, mixed>>
     */
    private function subsets(array $items, callable $value, float $target): array
    {
        $found = [];
        $count = count($items);

        $walk = function (int $start, array $chosen, float $sum) use (&$walk, &$found, $items, $value, $target, $count) {
            if (count($chosen) >= 2 && abs($sum - $target) <= self::TOLERANCE) {
                $found[] = $chosen;
            }

            if (count($chosen) === 4 || count($found) >= 5) {
                return;
            }

            for ($i = $start; $i < $count; $i++) {
                $next = $sum + $value($items[$i]);

                if ($next <= $target + self::TOLERANCE) {
                    $walk($i + 1, [...$chosen, $items[$i]], $next);
                }
            }
        };

        $walk(0, [], 0.0);

        return $found;
    }

    /**
     * Kata dari berita transfer: huruf (tanpa kata dari bank) dan angka terpisah.
     *
     * @return array{words: array<int, string>, numbers: array<int, string>}
     */
    private function words(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        return [
            'words'   => array_values(array_filter($parts, fn ($part) => ! ctype_digit($part) && mb_strlen($part) >= 3 && ! in_array($part, self::NOISE, true))),
            'numbers' => array_values(array_filter($parts, 'ctype_digit')),
        ];
    }

    /**
     * Kata pembeda dari nama klien / karyawan.
     *
     * @return array<int, string>
     */
    private function tokens(string $name): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter($parts, fn ($part) => mb_strlen($part) >= 3 && ! ctype_digit($part) && ! in_array($part, self::STOPWORDS, true))));
    }

    /**
     * 0 = tidak cocok, 1 = satu kata pendek, 2 = kuat (kata ≥ 5 huruf atau dua kata).
     * Nama di berita transfer sering terpotong, jadi awalan ≥ 4 huruf dihitung.
     */
    private function nameScore(array $words, array $tokens): int
    {
        $matched = 0;
        $long    = false;

        foreach ($tokens as $token) {
            foreach ($words['words'] as $word) {
                $same = $word === $token
                    || (mb_strlen($word) >= 4 && mb_strlen($token) >= 4 && (str_starts_with($word, $token) || str_starts_with($token, $word)));

                if ($same) {
                    $matched++;
                    $long = $long || mb_strlen($token) >= 5;
                    break;
                }
            }
        }

        return match (true) {
            $matched >= 2 || ($matched === 1 && $long) => 2,
            $matched === 1                            => 1,
            default                                   => 0,
        };
    }

    private function compact(string $text): string
    {
        return mb_strtoupper(preg_replace('/[^\p{L}\p{N}]+/u', '', $text));
    }

    /**
     * Pembayaran jauh sebelum invoice/pengajuan dibuat kurang mungkin.
     */
    private function datePenalty(string $date, mixed $documentDate): int
    {
        if (! $documentDate) {
            return 0;
        }

        $days = Carbon::parse($documentDate)->diffInDays(Carbon::parse($date), false);

        return $days < -30 ? 40 : ($days < -7 ? 15 : 0);
    }

    private function rupiah(float|string|null $amount): string
    {
        return 'Rp ' . number_format((float) $amount, 0, ',', '.');
    }
}
