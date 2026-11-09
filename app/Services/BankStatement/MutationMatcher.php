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

        $invoices = Invoice::with('deal')->where('type', 'sale')->whereNotIn('status', ['paid', 'cancelled'])->get()
            ->filter(fn (Invoice $invoice) => InvoicePayment::isPayable($invoice))
            ->values();
        $payees = Expense::with('user')->where('status', 'Disetujui')->get()
            ->concat(Advance::with('user')->where('status', 'Disetujui')->get())
            ->values();

        $senders = $this->knownSenders();
        $results = [];

        foreach ($rows as $index => $row) {
            $results[$index] = match (true) {
                $processed->has($row['key']) => $this->result('duplicate', note: 'Sudah dicatat dari unggahan mutasi sebelumnya.'),
                isset($recorded[$index])      => $this->result('recorded', note: $recorded[$index]),
                $this->isFee($row)            => $this->feeResult($row),
                $row['direction'] === 'in'    => $this->classify($this->invoiceCandidates($row, $invoices, $senders)),
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

    /**
     * Biaya/pajak/bunga bank: tidak dicatat kecuali Finance memilih "Catat juga
     * biaya/bunga bank", supaya saldo ERP sama persis dengan saldo bank.
     */
    private function feeResult(array $row): array
    {
        $interest = $row['direction'] === 'in';

        return $this->result('fee', 0, [[
            'targets'  => [['type' => $interest ? 'bank_interest' : 'bank_fee', 'amount' => round($row['amount'], 2)]],
            'label'    => $interest ? 'Bunga / jasa giro bank' : 'Biaya / pajak bank',
            'note'     => null,
            'identity' => true,
            'days'     => null,
        ]], 'Biaya, pajak, atau bunga bank.');
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
     * Pengirim yang pernah membayar invoice lewat mutasi yang sudah dicatat:
     * kata nama pengirimnya dan klien (Deal / nama perusahaan) yang dibayar.
     * Klien sering membayar dari rekening pribadi PIC atau badan lain, jadi
     * namanya tidak sama dengan nama di invoice.
     *
     * @return array<int, array{words: array<int, string>, deals: array<int, int>, companies: array<int, string>}>
     */
    private function knownSenders(): array
    {
        $mutations = BankMutation::where('direction', 'in')->latest('id')->limit(3000)->get(['description', 'targets']);
        $ids       = $mutations->flatMap(fn (BankMutation $mutation) => collect($mutation->targets)->where('type', 'invoice')->pluck('id'))->unique();
        $invoices  = Invoice::withTrashed()->whereIn('id', $ids)->get(['id', 'deal_id', 'nama_perusahaan'])->keyBy('id');
        $senders   = [];

        foreach ($mutations as $mutation) {
            $words = BcaDescription::nameWords(BcaDescription::parse($mutation->description)['counterparty']);
            $paid  = collect($mutation->targets)->where('type', 'invoice')->map(fn ($target) => $invoices->get($target['id']))->filter();

            if ($words && $paid->isNotEmpty()) {
                $senders[] = [
                    'words'     => $words,
                    'deals'     => $paid->pluck('deal_id')->filter()->unique()->values()->all(),
                    'companies' => $paid->map(fn (Invoice $invoice) => mb_strtolower(trim((string) $invoice->nama_perusahaan)))->filter()->unique()->values()->all(),
                ];
            }
        }

        return $senders;
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     */
    private function invoiceCandidates(array $row, Collection $invoices, array $senders): array
    {
        $amount = $row['amount'];
        $bca    = BcaDescription::parse($row['description']);

        // Keterangan BCA mengulang nominalnya; bila berbeda, nominal hasil baca yang dipercaya.
        $text    = $bca['counterparty'] !== '' ? $bca['counterparty'] : $row['description'];
        $words   = $this->words($text);
        $compact = $this->compact($text);
        $sender  = BcaDescription::nameWords($text);
        $known   = array_values(array_filter($senders, fn ($known) => BcaDescription::sameSender($sender, $known['words'])));

        $candidates = [];

        foreach ($invoices as $invoice) {
            $remaining = InvoicePayment::remaining($invoice);
            $number    = $this->numberMatch($invoice->uuid, $compact, $words) || $this->poMatch($invoice, $compact);
            $name      = $this->nameScore($words, $this->clientTokens($invoice));
            $learned   = $this->paidBefore($known, $invoice);
            $identity  = $number || $name > 0 || $learned;
            $pattern   = $this->amountPattern($invoice, $remaining, $amount, $identity);

            if ($pattern === null) {
                continue;
            }

            $days = $this->daysAfter($row['date'], $invoice->date);

            $candidates[] = [
                'targets'  => [['type' => 'invoice', 'id' => $invoice->id, 'amount' => round($amount, 2)]
                    + ($pattern['withholding'] ? ['withholding' => $pattern['withholding'], 'withholding_label' => $pattern['label']] : [])],
                'label'    => $this->invoiceLabel($invoice, $remaining),
                'note'     => implode('; ', array_filter([$pattern['note'], $learned && ! $name && ! $number ? 'pengirim ini pernah membayar invoice klien yang sama' : null])) ?: null,
                'identity' => $identity,
                'exact'    => $pattern['kind'] === 'exact',
                'days'     => $days,
                'score'    => $pattern['score'] + ($number ? 50 : 0) + 30 * min($name, 2) + ($learned ? 40 : 0)
                    + $this->proximity($days) + $this->dueBonus($row['date'], $invoice->due_date),
            ];
        }

        // Satu transfer untuk beberapa invoice klien yang sama.
        if (! array_filter($candidates, fn ($candidate) => $candidate['exact'])) {
            $candidates = array_merge($candidates, $this->invoiceCombos($row, $invoices, $words, $compact, $known));
        }

        return $candidates;
    }

    /**
     * Nominal transfer dibanding sisa tagihan:
     * - sama persis;
     * - dipotong PPh 23 (2% DPP);
     * - dipotong PPN oleh pemungut (WAPU: BUMN/instansi), dengan atau tanpa PPh 23;
     * - kurang sedikit (biaya transfer/pembulatan, maks. Rp 50.000 dan 1%) bila nama/nomornya cocok;
     * - sebagian (DP/termin) bila nama/nomornya cocok.
     *
     * @return array{kind: string, withholding: float, label: ?string, note: ?string, score: int}|null
     */
    private function amountPattern(Invoice $invoice, float $remaining, float $amount, bool $identity): ?array
    {
        $diff  = round($remaining - $amount, 2);
        $total = (float) $invoice->grand_total;
        $dpp   = $total > 0 ? max((float) $invoice->subtotal - (float) $invoice->total_discount, 0) * ($remaining / $total) : 0;
        $vat   = (float) $invoice->total_vat > 0;
        $pph   = round(self::PPH23 * $dpp, 2);
        $near  = fn (float $value) => abs($amount - $value) <= self::TOLERANCE;
        $cut   = fn (string $label, string $note, int $score, string $after = '; dicatat lunas') => ['kind' => 'cut', 'withholding' => $diff, 'label' => $label, 'note' => $note . ' ' . $this->rupiah($diff) . $after, 'score' => $score];

        return match (true) {
            abs($diff) <= self::TOLERANCE           => ['kind' => 'exact', 'withholding' => 0.0, 'label' => null, 'note' => null, 'score' => 100],
            $near($remaining - $pph)                 => $cut('PPh 23', 'dipotong PPh 23 (2% DPP)', 60, '; dicatat lunas, minta bukti potongnya ke klien'),
            $vat && $near($dpp - $pph)               => $cut('PPN (WAPU) + PPh 23', 'PPN dipungut klien (WAPU) dan dipotong PPh 23, total', 60, '; dicatat lunas, minta bukti pungut/potongnya ke klien'),
            $vat && $near($dpp)                      => $cut('PPN (WAPU)', 'PPN dipungut klien (WAPU)', 55, '; dicatat lunas, minta bukti pungutnya ke klien'),
            $identity && $diff > 0 && $diff <= min(50_000, max($remaining * 0.01, 7_500)) => $cut('selisih biaya transfer/pembulatan', 'kurang', 50),
            $identity && $diff > 0                   => ['kind' => 'partial', 'withholding' => 0.0, 'label' => null, 'note' => 'pembayaran sebagian; sisa setelah ini ' . $this->rupiah($diff), 'score' => 20],
            default                                  => null,
        };
    }

    /**
     * Nama klien dari invoice dan dari Deal-nya (nama di invoice kadang diketik beda).
     *
     * @return array<int, string>
     */
    private function clientTokens(Invoice $invoice): array
    {
        return $this->tokens(implode(' ', [$invoice->nama_perusahaan, $invoice->nama_pic, $invoice->deal?->nama_perusahaan, $invoice->deal?->nama_pic]));
    }

    private function paidBefore(array $known, Invoice $invoice): bool
    {
        $company = mb_strtolower(trim((string) $invoice->nama_perusahaan));

        foreach ($known as $sender) {
            if (($invoice->deal_id && in_array($invoice->deal_id, $sender['deals'], true)) || ($company !== '' && in_array($company, $sender['companies'], true))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nomor PO klien di berita transfer.
     */
    private function poMatch(Invoice $invoice, string $compact): bool
    {
        $po = $this->compact((string) $invoice->po_number);

        return strlen($po) >= 4 && str_contains($compact, $po);
    }

    /**
     * Gabungan 2–4 invoice satu klien yang sisanya pas dengan nominal transfer.
     *
     * @param  Collection<int, Invoice>  $invoices
     */
    private function invoiceCombos(array $row, Collection $invoices, array $words, string $compact, array $known): array
    {
        $combos = [];

        foreach ($invoices->groupBy(fn (Invoice $invoice) => mb_strtolower(trim((string) $invoice->nama_perusahaan))) as $client => $group) {
            if ($client === '' || $group->count() < 2 || $group->count() > 12) {
                continue;
            }

            foreach ($this->subsets($group->values()->all(), fn (Invoice $invoice) => InvoicePayment::remaining($invoice), $row['amount']) as $subset) {
                $collection = collect($subset);
                $identity   = $this->nameScore($words, $this->clientTokens($subset[0])) > 0
                    || $this->paidBefore($known, $subset[0])
                    || $collection->contains(fn (Invoice $invoice) => $this->numberMatch($invoice->uuid, $compact, $words) || $this->poMatch($invoice, $compact));

                // Invoice terbaru di gabungan: transfernya baru bisa terjadi setelah itu.
                $days = $this->daysAfter($row['date'], $collection->max('date'));

                $combos[] = [
                    'targets'  => $collection->map(fn (Invoice $invoice) => ['type' => 'invoice', 'id' => $invoice->id, 'amount' => InvoicePayment::remaining($invoice)])->all(),
                    'label'    => count($subset) . ' invoice ' . Text::short(ClientLabel::invoice($subset[0]), 40) . ': ' . $collection->pluck('uuid')->implode(', '),
                    'note'     => 'satu transfer untuk ' . count($subset) . ' invoice; semuanya jadi lunas',
                    'identity' => $identity,
                    'exact'    => true,
                    'combo'    => true,
                    'days'     => $days,
                    'score'    => 90 + ($identity ? 40 : 0) + $this->proximity($days),
                ];
            }
        }

        return $combos;
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
        return $invoice->uuid . ' · ' . Text::short(ClientLabel::invoice($invoice), 40) . $this->dated($invoice->date) . ' · sisa ' . $this->rupiah($remaining);
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

            $days = $this->daysAfter($row['date'], $payee->submission_date);

            $candidates[] = [
                'targets'  => [['type' => $this->type($payee), 'id' => $payee->id, 'amount' => round($amount, 2)]],
                'label'    => $this->payeeLabel($payee),
                'note'     => $partial ? 'dibayar sebagian dari ' . $this->rupiah($requested) : null,
                'identity' => $name > 0,
                'exact'    => $exact,
                'days'     => $days,
                'score'    => ($exact ? 100 : 20) + 30 * min($name, 2) + $this->proximity($days),
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
                        'days'     => $days = $this->daysAfter($row['date'], collect($subset)->max('submission_date')),
                        'score'    => 90 + ($identity ? 40 : 0) + $this->proximity($days),
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

        return ucfirst($this->type($payee)) . " #{$payee->id} · " . $this->userName($payee->user) . ' · ' . Text::short((string) $what, 40) . $this->dated($payee->submission_date) . ' · ' . $this->rupiah($payee->amount);
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

        $named   = $identity($exact);
        $closest = count($named) > 1 ? $this->clearlyClosest($candidates, $named) : null;

        $sure = match (true) {
            count($exact) === 1 && $candidates[$exact[0]]['identity'] => $exact[0],
            count($named) === 1                                       => $named[0],
            $closest !== null                                         => $closest,
            $exact === [] && count($identity($combos)) === 1 && count($combos) === 1 => $combos[0],
            default                                                   => null,
        };

        if ($closest !== null && $sure === $closest) {
            $candidates[$closest]['note'] = trim(($candidates[$closest]['note'] ?? '') . '; ada ' . (count($named) - 1)
                . ' tagihan lain bernominal sama dari nama yang sama, dipilih yang tanggalnya paling dekat sebelum transfer', '; ');
        }

        $clean = array_map(fn ($candidate) => array_intersect_key($candidate, array_flip(['targets', 'label', 'note', 'identity', 'days'])), $candidates);

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
     * Hari dari tanggal invoice/pengajuan sampai uang ditransfer (negatif: transfer lebih dulu).
     */
    private function daysAfter(string $date, mixed $documentDate): ?int
    {
        return $documentDate ? (int) Carbon::parse($documentDate)->startOfDay()->diffInDays(Carbon::parse($date)->startOfDay(), false) : null;
    }

    /**
     * Pembayaran biasanya tidak lama setelah invoice dibuat (atau pengajuan
     * disetujui): makin dekat makin tinggi nilainya. Transfer jauh sebelum
     * dokumennya dibuat kurang mungkin; yang hanya beberapa hari sebelumnya
     * masih wajar (uang muka, invoice dibuat belakangan).
     */
    private function proximity(?int $days): int
    {
        return match (true) {
            $days === null => 0,
            $days < -30    => -40,
            $days < -7     => -15,
            $days < 0      => -5,
            $days <= 45    => 20 - intdiv($days * 20, 45),
            $days <= 120   => 0,
            default        => -10,
        };
    }

    /**
     * Ditransfer sekitar jatuh tempo.
     */
    private function dueBonus(string $date, mixed $dueDate): int
    {
        $days = $this->daysAfter($date, $dueDate);

        return $days !== null && abs($days) <= 7 ? 10 : 0;
    }

    /**
     * Dari beberapa tagihan bernominal sama dan nama yang sama (mis. retainer
     * bulanan): yang dibuat paling dekat sebelum transfer, bila jelas lebih
     * dekat (selisih minimal 7 hari dengan yang berikutnya). Null bila tidak jelas.
     *
     * @param  array<int, int>  $keys
     */
    private function clearlyClosest(array $candidates, array $keys): ?int
    {
        $before = array_values(array_filter($keys, fn ($key) => ($candidates[$key]['days'] ?? null) !== null && $candidates[$key]['days'] >= -3));
        usort($before, fn ($a, $b) => abs($candidates[$a]['days']) <=> abs($candidates[$b]['days']));

        if ($before === []) {
            return null;
        }

        $others = array_diff($keys, [$before[0]]);
        $gap    = min(array_map(fn ($key) => abs(($candidates[$key]['days'] ?? PHP_INT_MAX >> 1) - $candidates[$before[0]]['days']), $others));

        return $gap >= 7 ? $before[0] : null;
    }

    private function dated(mixed $date): string
    {
        return $date ? ' · ' . Carbon::parse($date)->locale('id')->translatedFormat('j M') : '';
    }

    private function rupiah(float|string|null $amount): string
    {
        return 'Rp ' . number_format((float) $amount, 0, ',', '.');
    }
}
