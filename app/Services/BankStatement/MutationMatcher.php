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

    // DP yang lazim dari total invoice.
    private const DP_PERCENTS = [10, 15, 20, 25, 30, 35, 40, 50, 60, 70, 75, 80];

    // Biaya transfer yang lazim dipotong pengirim: BI-FAST, LLG/SKN, online antarbank, RTGS.
    private const TRANSFER_FEES = [2_500, 2_900, 5_000, 6_500, 25_000, 30_000];

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

        // Proforma ikut dicari: klien sering mentransfer berdasarkan proforma sebelum invoicenya dibuat.
        $invoices = self::openInvoices();
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

            if ($results[$index]['status'] === 'none' && $row['direction'] === 'in') {
                $results[$index]['note'] = $this->alreadyPaidHint($row);
            }
        }

        return $results;
    }

    /**
     * Pencarian manual dari layar Tinjau: nomor invoice (potongan angka),
     * nama klien/PIC (juga di Deal), atau nominal. Hasil diurutkan menurut
     * kecocokan dengan kata kunci dan dengan nominal mutasinya; invoice yang
     * sudah lunas ikut ditampilkan sebagai info.
     *
     * @return array<int, array{invoice: Invoice, open: bool, remaining: float, explanation: ?array, score: int}>
     */
    public function searchInvoices(string $query, array $row, int $limit = 8): array
    {
        $words   = $this->words($query);
        $digits  = preg_replace('/\D/', '', $query);
        $amount  = \App\Services\Telegram\AmountParser::parse($query);
        $results = [];

        foreach (Invoice::with('deal')->whereIn('type', ['sale', 'estimate'])->where('status', '!=', 'cancelled')->latest('id')->limit(2000)->get() as $invoice) {
            $remaining = InvoicePayment::remaining($invoice);
            $open      = $invoice->status !== 'paid' && $remaining > 0;
            $score     = 0;

            if (strlen($digits) >= 3 && ! $amount && str_contains(preg_replace('/\D/', '', $invoice->uuid . $invoice->proforma_uuid), $digits)) {
                $score += 100;
            }

            $score += 40 * $this->nameScore($words, $this->clientTokens($invoice));

            if ($amount && (abs($remaining - $amount) <= self::TOLERANCE || abs((float) $invoice->grand_total - $amount) <= self::TOLERANCE)) {
                $score += 60;
            }

            if ($score === 0) {
                continue;
            }

            $explanation = $open ? $this->explainAmount($invoice, $remaining, $row['amount'], true) : null;
            $score      += ($explanation && $explanation['kind'] !== 'partial' ? 30 : 0) + ($open ? 20 : 0);
            $results[]   = ['invoice' => $invoice, 'open' => $open, 'remaining' => $remaining, 'explanation' => $explanation, 'score' => $score];
        }

        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, $limit);
    }

    /**
     * Pencarian manual expense/advance yang sudah Disetujui: ID (#45), nama
     * karyawan, atau nominal.
     *
     * @return array<int, array{payee: Expense|Advance, label: string, score: int}>
     */
    public function searchPayees(string $query, array $row, int $limit = 8): array
    {
        $words   = $this->words($query);
        $digits  = preg_replace('/\D/', '', $query);
        $amount  = \App\Services\Telegram\AmountParser::parse($query);
        $results = [];

        $payees = Expense::with('user')->where('status', 'Disetujui')->get()->concat(Advance::with('user')->where('status', 'Disetujui')->get());

        foreach ($payees as $payee) {
            $score = ($digits !== '' && ! $amount && (string) $payee->id === $digits ? 100 : 0)
                + 40 * $this->nameScore($words, $this->userTokens($payee->user))
                + ($amount && abs((float) $payee->amount - $amount) <= self::TOLERANCE ? 60 : 0);

            if ($score === 0) {
                continue;
            }

            $score    += abs((float) $payee->amount - $row['amount']) <= self::TOLERANCE ? 30 : 0;
            $results[] = ['payee' => $payee, 'label' => $this->payeeLabel($payee), 'score' => $score];
        }

        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, $limit);
    }

    /**
     * Invoice penjualan yang belum lunas dan proforma yang belum dibayar.
     *
     * @return Collection<int, Invoice>
     */
    public static function openInvoices(): Collection
    {
        return Invoice::with('deal')->whereIn('type', ['sale', 'estimate'])->whereNotIn('status', ['paid', 'cancelled'])->get()
            ->filter(fn (Invoice $invoice) => $invoice->type === 'estimate' ? InvoicePayment::remaining($invoice) > 0 : InvoicePayment::isPayable($invoice))
            ->values();
    }

    /**
     * Uang masuk tanpa pasangan: invoice yang sudah Lunas dengan nominal ini
     * (dan nama klien di berita, bila ada), supaya Finance tahu mungkin sudah
     * dicatat manual tanpa transaksi rekening.
     */
    private function alreadyPaidHint(array $row): ?string
    {
        $words = $this->words(BcaDescription::parse($row['description'])['counterparty'] ?: $row['description']);
        $paid  = Invoice::with('deal')->where('type', 'sale')->where('status', 'paid')
            ->where(fn ($query) => $query->whereBetween('grand_total', [$row['amount'] - 1, $row['amount'] + 1])->orWhereBetween('paid', [$row['amount'] - 1, $row['amount'] + 1]))
            ->latest('id')->limit(20)->get()
            ->sortByDesc(fn (Invoice $invoice) => $this->nameScore($words, $this->clientTokens($invoice)))
            ->first();

        return $paid
            ? 'Mungkin sudah dicatat: ' . $paid->uuid . ' · ' . Text::short(ClientLabel::invoice($paid), 30) . ' sudah Lunas dengan nominal ini.'
            : null;
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
            'label'      => $interest ? 'Bunga / jasa giro bank' : 'Biaya / pajak bank',
            'note'       => null,
            'identity'   => true,
            'days'       => null,
            'confidence' => 100,
            'reasons'    => ['✓ keterangan bank: ' . ($interest ? 'bunga / jasa giro' : 'biaya admin / pajak')],
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
     * Semua invoice yang bisa menjelaskan transfer ini, masing-masing dengan
     * penjelasan nominalnya, bukti pendukung/pelemah, dan skornya.
     *
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

        // Klien yang namanya jelas tertulis di berita transfer.
        $mentioned = [];
        foreach ($invoices as $invoice) {
            if ($this->nameScore($words, $this->clientTokens($invoice)) === 2) {
                $mentioned[$this->clientKey($invoice)] = (string) $invoice->nama_perusahaan;
            }
        }

        $candidates = [];

        foreach ($invoices as $invoice) {
            $remaining   = InvoicePayment::remaining($invoice);
            $number      = $this->numberMatch($invoice->uuid, $compact, $words);
            $po          = $this->poMatch($invoice, $compact);
            $matchedName = $this->nameMatches($words, $this->clientTokens($invoice));
            $name        = $this->nameScore($words, $this->clientTokens($invoice));
            $learned     = $this->paidBefore($known, $invoice);
            $identity    = $number || $po || $name > 0 || $learned;
            $explanation = $this->explainAmount($invoice, $remaining, $amount, $identity);

            if ($explanation === null) {
                continue;
            }

            $days    = $this->daysAfter($row['date'], $invoice->date);
            $client  = $this->clientKey($invoice);
            $reasons = ['✓ ' . $explanation['reason']];
            $score   = $explanation['score'];

            if ($number) {
                $score += 35;
                $reasons[] = '✓ nomor invoice tertulis di berita';
            }

            if ($po) {
                $score += 35;
                $reasons[] = '✓ nomor PO klien tertulis di berita';
            }

            if ($name > 0) {
                $score += $name === 2 ? 30 : 15;
                $reasons[] = '✓ nama klien di berita (' . mb_strtoupper(implode(' ', $matchedName)) . ')';
            }

            if ($learned) {
                $score += 25;
                $reasons[] = '✓ pengirim ini pernah membayar invoice klien yang sama';
            }

            if ($mentioned && ! isset($mentioned[$client])) {
                $score -= 50;
                $reasons[] = '✗ berita menyebut klien lain (' . Text::short(implode(', ', $mentioned), 40) . ')';
            }

            [$dateScore, $dateReason] = $this->dateEvidence($days, 'invoice dibuat');
            $score += $dateScore + $this->dueBonus($row['date'], $invoice->due_date);

            if ($dateReason) {
                $reasons[] = $dateReason;
            }

            if ($invoice->type === 'estimate') {
                $reasons[] = '• masih proforma: dijadikan invoice #SC saat dicatat';
            }

            $candidates[] = [
                'targets'  => [$this->invoiceTarget($invoice, round($amount, 2))
                    + ($explanation['withholding'] > 0 ? ['withholding' => $explanation['withholding'], 'withholding_label' => $explanation['label']] : [])],
                'label'    => $this->invoiceLabel($invoice, $remaining),
                'note'     => $explanation['note'],
                'identity' => $identity,
                'kind'     => $explanation['kind'],
                'exact'    => $explanation['kind'] === 'exact',
                'client'   => $client,
                'days'     => $days,
                'score'    => $score,
                'reasons'  => $reasons,
            ];
        }

        // Satu transfer untuk beberapa invoice klien yang sama (utuh, atau dipotong PPh 23).
        if (! array_filter($candidates, fn ($candidate) => $candidate['exact'])) {
            $candidates = array_merge($candidates, $this->invoiceCombos($row, $invoices, $words, $compact, $known));
        }

        return $this->weigh($candidates, $amount);
    }

    /**
     * Penjelasan nominal transfer terhadap sisa tagihan satu invoice, dari
     * yang paling meyakinkan:
     * - sama persis dengan sisa tagihan;
     * - dipotong PPh 23 (2% DPP);
     * - PPN dipungut klien (WAPU: BUMN/instansi), dengan atau tanpa PPh 23;
     * - DP 10–80% dari total invoice yang belum dibayar sama sekali;
     * - dipotong biaya transfer yang umum (BI-FAST, online, LLG, RTGS);
     * - kurang sedikit (pembulatan, maks. Rp 50.000 dan 1%) bila nama/nomornya cocok;
     * - sebagian (termin) bila nama/nomornya cocok.
     *
     * @return array{kind: string, score: int, withholding: float, label: ?string, reason: string, note: ?string}|null
     */
    public function explainAmount(Invoice $invoice, float $remaining, float $amount, bool $identity): ?array
    {
        $diff  = round($remaining - $amount, 2);
        $total = (float) $invoice->grand_total;
        $dpp   = $total > 0 ? max((float) $invoice->subtotal - (float) $invoice->total_discount, 0) * ($remaining / $total) : 0;
        $vat   = (float) $invoice->total_vat > 0;
        $pph   = round(self::PPH23 * $dpp, 2);
        $near  = fn (float $value) => $value > 0 && abs($amount - $value) <= self::TOLERANCE;
        $cut   = fn (string $kind, int $score, string $label, string $reason, string $note) => [
            'kind' => $kind, 'score' => $score, 'withholding' => $diff, 'label' => $label, 'reason' => $reason, 'note' => $note,
        ];

        if (abs($diff) <= self::TOLERANCE) {
            return ['kind' => 'exact', 'score' => 60, 'withholding' => 0.0, 'label' => null, 'reason' => 'nominal = sisa tagihan ' . $this->rupiah($remaining) . ' persis', 'note' => null];
        }

        if ($near($remaining - $pph)) {
            return $cut('pph', 45, 'PPh 23', 'nominal = sisa tagihan ' . $this->rupiah($remaining) . ' − PPh 23 2% × DPP ' . $this->rupiah($dpp) . ' (' . $this->rupiah($pph) . ')', 'dicatat lunas dengan potongan PPh 23 ' . $this->rupiah($diff) . '; minta bukti potongnya ke klien');
        }

        if ($vat && $near($dpp - $pph)) {
            return $cut('wapu', 45, 'PPN (WAPU) + PPh 23', 'nominal = DPP ' . $this->rupiah($dpp) . ' − PPh 23 ' . $this->rupiah($pph) . '; PPN dipungut klien (WAPU)', 'dicatat lunas dengan potongan PPN + PPh 23 ' . $this->rupiah($diff) . '; minta bukti pungut/potongnya ke klien');
        }

        if ($vat && $near($dpp)) {
            return $cut('wapu', 40, 'PPN (WAPU)', 'nominal = DPP ' . $this->rupiah($dpp) . '; PPN dipungut klien (WAPU)', 'dicatat lunas dengan potongan PPN ' . $this->rupiah($diff) . '; minta bukti pungutnya ke klien');
        }

        if ((float) $invoice->paid <= 0 && $total > 0) {
            foreach (self::DP_PERCENTS as $percent) {
                if ($near(round($total * $percent / 100, 2))) {
                    return ['kind' => 'dp', 'score' => 45, 'withholding' => 0.0, 'label' => null, 'reason' => "nominal = DP {$percent}% dari total " . $this->rupiah($total), 'note' => "pembayaran DP {$percent}%; sisa setelah ini " . $this->rupiah($diff)];
                }
            }
        }

        if ($diff > 0 && in_array((int) round($diff), self::TRANSFER_FEES, true)) {
            return $cut('fee', $identity ? 50 : 40, 'biaya transfer', 'nominal = sisa tagihan − biaya transfer ' . $this->rupiah($diff), 'dicatat lunas; biaya transfer ' . $this->rupiah($diff) . ' ditanggung kita');
        }

        if ($identity && $diff > 0 && $diff <= min(50_000, max($remaining * 0.01, 7_500))) {
            return $cut('diff', 35, 'selisih pembulatan', 'nominal kurang ' . $this->rupiah($diff) . ' dari sisa tagihan (pembulatan/biaya)', 'dicatat lunas; selisih ' . $this->rupiah($diff) . ' dicatat sebagai potongan');
        }

        if ($identity && $diff > 0) {
            return ['kind' => 'partial', 'score' => 10, 'withholding' => 0.0, 'label' => null, 'reason' => 'pembayaran sebagian dari sisa tagihan ' . $this->rupiah($remaining), 'note' => 'pembayaran sebagian; sisa setelah ini ' . $this->rupiah($diff)];
        }

        return null;
    }

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
     * Nama klien dari invoice dan dari Deal-nya (nama di invoice kadang diketik beda).
     *
     * @return array<int, string>
     */
    private function clientTokens(Invoice $invoice): array
    {
        return $this->tokens(implode(' ', [$invoice->nama_perusahaan, $invoice->nama_pic, $invoice->deal?->nama_perusahaan, $invoice->deal?->nama_pic]));
    }

    private function clientKey(Invoice $invoice): string
    {
        return $invoice->deal_id ? "deal:{$invoice->deal_id}" : 'co:' . mb_strtolower(trim((string) $invoice->nama_perusahaan));
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
     * Gabungan 2–4 invoice satu klien yang sisanya pas dengan nominal transfer,
     * utuh atau masing-masing dipotong PPh 23.
     *
     * @param  Collection<int, Invoice>  $invoices
     */
    private function invoiceCombos(array $row, Collection $invoices, array $words, string $compact, array $known): array
    {
        $combos = [];

        foreach ($invoices->groupBy(fn (Invoice $invoice) => $this->clientKey($invoice)) as $client => $group) {
            if ($group->count() < 2 || $group->count() > 12) {
                continue;
            }

            foreach (['full' => false, 'pph' => true] as $mode => $withPph) {
                $value = function (Invoice $invoice) use ($withPph) {
                    $remaining = InvoicePayment::remaining($invoice);
                    $total     = (float) $invoice->grand_total;
                    $dpp       = $total > 0 ? max((float) $invoice->subtotal - (float) $invoice->total_discount, 0) * ($remaining / $total) : 0;

                    return $withPph ? $remaining - round(self::PPH23 * $dpp, 2) : $remaining;
                };

                foreach ($this->subsets($group->values()->all(), $value, $row['amount']) as $subset) {
                    $collection = collect($subset);
                    $name       = $this->nameScore($words, $this->clientTokens($subset[0]));
                    $learned    = $this->paidBefore($known, $subset[0]);
                    $number     = $collection->contains(fn (Invoice $invoice) => $this->numberMatch($invoice->uuid, $compact, $words) || $this->poMatch($invoice, $compact));
                    $days       = $this->daysAfter($row['date'], $collection->max('date'));
                    [$dateScore, $dateReason] = $this->dateEvidence($days, 'invoice terbaru dibuat');

                    $combos[] = [
                        'targets'  => $collection->map(function (Invoice $invoice) use ($value, $withPph) {
                            $remaining = InvoicePayment::remaining($invoice);
                            $cash      = round($value($invoice), 2);

                            return $this->invoiceTarget($invoice, $cash)
                                + ($withPph ? ['withholding' => round($remaining - $cash, 2), 'withholding_label' => 'PPh 23'] : []);
                        })->all(),
                        'label'    => count($subset) . ' invoice ' . Text::short(ClientLabel::invoice($subset[0]), 40) . ': ' . $collection->pluck('uuid')->implode(', '),
                        'note'     => 'satu transfer untuk ' . count($subset) . ' invoice; semuanya jadi lunas' . ($withPph ? ', masing-masing dengan potongan PPh 23' : ''),
                        'identity' => $name > 0 || $learned || $number,
                        'kind'     => $withPph ? 'combo-pph' : 'combo',
                        'exact'    => true,
                        'combo'    => true,
                        'client'   => $client,
                        'days'     => $days,
                        'score'    => ($withPph ? 45 : 50) + ($name === 2 ? 30 : ($name ? 15 : 0)) + ($learned ? 25 : 0) + ($number ? 35 : 0) + $dateScore,
                        'reasons'  => array_values(array_filter([
                            '✓ nominal = jumlah sisa ' . count($subset) . ' invoice klien yang sama' . ($withPph ? ' setelah PPh 23' : ''),
                            $name ? '✓ nama klien di berita' : null,
                            $learned ? '✓ pengirim ini pernah membayar invoice klien yang sama' : null,
                            $number ? '✓ nomor invoice/PO tertulis di berita' : null,
                            $dateReason,
                        ])),
                    ];
                }
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

    /**
     * Target pencatatan untuk invoice; proforma ditandai untuk dikonversi dulu.
     */
    public function invoiceTarget(Invoice $invoice, float $amount): array
    {
        return ['type' => 'invoice', 'id' => $invoice->id, 'amount' => $amount] + ($invoice->type === 'estimate' ? ['convert' => true] : []);
    }

    public function invoiceLabel(Invoice $invoice, float $remaining): string
    {
        return ($invoice->type === 'estimate' ? 'Proforma ' : '') . $invoice->uuid . ' · ' . Text::short(ClientLabel::invoice($invoice), 40) . $this->dated($invoice->date) . ' · sisa ' . $this->rupiah($remaining);
    }

    // =================================================================
    // Uang keluar → expense / advance
    // =================================================================

    /**
     * @param  Collection<int, Expense|Advance>  $payees
     */
    private function payoutCandidates(array $row, Collection $payees): array
    {
        $amount = $row['amount'];
        $bca    = BcaDescription::parse($row['description']);
        $words  = $this->words($bca['counterparty'] !== '' ? $bca['counterparty'] : $row['description']);

        // Karyawan yang namanya jelas tertulis di berita transfer.
        $mentioned = [];
        foreach ($payees as $payee) {
            if ($payee->user && $this->nameScore($words, $this->userTokens($payee->user)) === 2) {
                $mentioned[$payee->user_id] = $this->userName($payee->user);
            }
        }

        $candidates = [];

        foreach ($payees as $payee) {
            $requested = (float) $payee->amount;
            $name      = $this->nameScore($words, $this->userTokens($payee->user));
            $exact     = abs($amount - $requested) <= self::TOLERANCE;
            $partial   = ! $exact && $amount < $requested && $name > 0;

            if (! $exact && ! $partial) {
                continue;
            }

            $days    = $this->daysAfter($row['date'], $payee->submission_date);
            $reasons = [$exact ? '✓ nominal = nominal pengajuan persis' : '✓ dibayar sebagian dari ' . $this->rupiah($requested)];
            $score   = $exact ? 60 : 10;

            if ($name > 0) {
                $score += $name === 2 ? 30 : 15;
                $reasons[] = '✓ nama karyawan di berita (' . mb_strtoupper(implode(' ', $this->nameMatches($words, $this->userTokens($payee->user)))) . ')';
            }

            if ($mentioned && ! isset($mentioned[$payee->user_id])) {
                $score -= 50;
                $reasons[] = '✗ berita menyebut orang lain (' . Text::short(implode(', ', $mentioned), 40) . ')';
            }

            [$dateScore, $dateReason] = $this->dateEvidence($days, 'diajukan');
            $score += $dateScore;

            if ($dateReason) {
                $reasons[] = $dateReason;
            }

            $candidates[] = [
                'targets'  => [['type' => $this->type($payee), 'id' => $payee->id, 'amount' => round($amount, 2)]],
                'label'    => $this->payeeLabel($payee),
                'note'     => $partial ? 'dibayar sebagian dari ' . $this->rupiah($requested) : null,
                'identity' => $name > 0,
                'kind'     => $exact ? 'exact' : 'partial',
                'exact'    => $exact,
                'client'   => "user:{$payee->user_id}",
                'days'     => $days,
                'score'    => $score,
                'reasons'  => $reasons,
            ];
        }

        // Satu transfer untuk beberapa pengajuan karyawan yang sama.
        if (! array_filter($candidates, fn ($candidate) => $candidate['exact'])) {
            foreach ($payees->groupBy('user_id') as $userId => $group) {
                if ($group->count() < 2 || $group->count() > 12) {
                    continue;
                }

                foreach ($this->subsets($group->values()->all(), fn ($payee) => (float) $payee->amount, $amount) as $subset) {
                    $name = $this->nameScore($words, $this->userTokens($subset[0]->user));
                    $days = $this->daysAfter($row['date'], collect($subset)->max('submission_date'));
                    [$dateScore, $dateReason] = $this->dateEvidence($days, 'pengajuan terbaru diajukan');

                    $candidates[] = [
                        'targets'  => array_map(fn ($payee) => ['type' => $this->type($payee), 'id' => $payee->id, 'amount' => (float) $payee->amount], $subset),
                        'label'    => count($subset) . ' pengajuan ' . $this->userName($subset[0]->user) . ': ' . implode(', ', array_map(fn ($payee) => ucfirst($this->type($payee)) . " #{$payee->id}", $subset)),
                        'note'     => 'satu transfer untuk ' . count($subset) . ' pengajuan',
                        'identity' => $name > 0,
                        'kind'     => 'combo',
                        'exact'    => true,
                        'combo'    => true,
                        'client'   => "user:{$userId}",
                        'days'     => $days,
                        'score'    => 50 + ($name === 2 ? 30 : ($name ? 15 : 0)) + $dateScore,
                        'reasons'  => array_values(array_filter(['✓ nominal = jumlah ' . count($subset) . ' pengajuan karyawan yang sama', $name ? '✓ nama karyawan di berita' : null, $dateReason])),
                    ];
                }
            }
        }

        return $this->weigh($candidates, $amount);
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

    /**
     * Bukti yang berlaku untuk semua kandidat satu baris mutasi, lalu skor
     * keyakinan 0–100:
     * - hanya satu tagihan yang bisa menjelaskan nominal ini: +15, dan +10
     *   lagi bila nominalnya tidak bulat (khas, kecil kemungkinan kebetulan);
     * - beberapa tagihan bisa menjelaskan nominal ini dan nama/nomornya tidak
     *   ada di berita: −20;
     * - tanpa nama/nomor/riwayat pengirim, keyakinan dibatasi 70% (88% bila
     *   nominalnya khas dan satu-satunya).
     */
    private function weigh(array $candidates, float $amount): array
    {
        $strong      = array_filter($candidates, fn ($candidate) => $candidate['kind'] !== 'partial' && $candidate['kind'] !== 'diff');
        $unique      = count($strong) === 1;
        $distinctive = fmod(round($amount), 1000.0) != 0.0;

        foreach ($candidates as &$candidate) {
            $isStrong = $candidate['kind'] !== 'partial' && $candidate['kind'] !== 'diff';

            if ($isStrong && $unique) {
                $candidate['score'] += 15;
                $candidate['reasons'][] = '✓ hanya tagihan ini yang cocok dengan nominal ini';

                if ($distinctive) {
                    $candidate['score'] += 10;
                    $candidate['reasons'][] = '✓ nominal tidak bulat (khas)';
                }
            } elseif ($isStrong && ! $candidate['identity']) {
                $candidate['score'] -= 20;
                $candidate['reasons'][] = '✗ ' . count($strong) . ' tagihan bisa menjelaskan nominal ini';
            }

            $confidence = max(0, min(100, $candidate['score']));

            if (! $candidate['identity']) {
                $confidence = min($confidence, $isStrong && $unique && $distinctive ? 88 : 70);
                $candidate['reasons'][] = '✗ nama/nomor tidak ada di berita transfer';
            }

            $candidate['confidence'] = $confidence;
        }

        return $candidates;
    }

    /**
     * Pasti bila keyakinan teratas ≥ 80% dan jauh (≥ 20 poin) di atas kandidat
     * berikutnya. Tagihan kembar dari klien yang sama (retainer bulanan)
     * dipilih yang dibuat paling dekat sebelum transfer bila jaraknya jelas.
     * Di bawah 25% dianggap tanpa pasangan.
     */
    private function classify(array $candidates): array
    {
        if ($candidates === []) {
            return $this->result('none');
        }

        usort($candidates, fn ($a, $b) => [$b['confidence'], $b['score']] <=> [$a['confidence'], $a['score']]);
        $candidates = array_slice($candidates, 0, 6);
        $top        = $candidates[0];
        $next       = $candidates[1] ?? null;

        if ($top['confidence'] < 25) {
            return $this->result('none', note: 'Kandidat terlalu lemah: ' . $top['label']);
        }

        $sure = $top['confidence'] >= 80 && (! $next || $top['score'] - $next['score'] >= 20) ? 0 : null;

        // Tagihan kembar: nominal sama, klien sama, nama/nomornya cocok (mis. retainer bulanan).
        $twins = $top['exact'] && $top['identity']
            ? array_keys(array_filter($candidates, fn ($candidate) => $candidate['exact'] && $candidate['identity'] && $candidate['client'] === $top['client'] && $candidate['confidence'] >= 70))
            : [];

        if ($sure === null && $top['confidence'] >= 80 && count($twins) > 1) {
            $sure = $this->clearlyClosest($candidates, $twins);
        }

        if ($sure !== null && count($twins) > 1 && in_array($sure, $twins, true)) {
            $candidates[$sure]['note'] = trim(($candidates[$sure]['note'] ?? '') . '; ada ' . (count($twins) - 1)
                . ' tagihan lain bernominal sama dari nama yang sama, dipilih yang tanggalnya paling dekat sebelum transfer', '; ');
        }

        $clean = array_map(fn ($candidate) => array_intersect_key($candidate, array_flip(['targets', 'label', 'note', 'identity', 'days', 'confidence', 'reasons'])), $candidates);

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
     * Kata nama yang cocok antara berita transfer dan nama klien/karyawan.
     * BCA memotong nama di belakang ("INDONES" untuk Indonesia), jadi kata di
     * berita boleh lebih pendek (≥ 4 huruf) dari namanya, tapi tidak lebih
     * panjang: "WIJAYAKUSUMA" bukan "Wijaya".
     *
     * @return array<int, string>
     */
    private function nameMatches(array $words, array $tokens): array
    {
        $matched = [];

        foreach ($tokens as $token) {
            foreach ($words['words'] as $word) {
                if ($word === $token || (mb_strlen($word) >= 4 && str_starts_with($token, $word))) {
                    $matched[] = $token;
                    break;
                }
            }
        }

        return $matched;
    }

    /**
     * 0 = tidak cocok, 1 = satu kata pendek, 2 = kuat (kata ≥ 5 huruf atau dua kata).
     */
    private function nameScore(array $words, array $tokens): int
    {
        $matched = $this->nameMatches($words, $tokens);
        $long    = (bool) array_filter($matched, fn (string $token) => mb_strlen($token) >= 5);

        return match (true) {
            count($matched) >= 2 || (count($matched) === 1 && $long) => 2,
            count($matched) === 1                                   => 1,
            default                                                 => 0,
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
     * Skor dan alasan dari jarak tanggal dokumen ke transfer.
     *
     * @return array{0: int, 1: ?string}
     */
    private function dateEvidence(?int $days, string $what): array
    {
        $score = $this->proximity($days);

        return [$score, match (true) {
            $days === null => null,
            $days < -7     => "✗ {$what} " . abs($days) . ' hari setelah transfer',
            $days < 0      => "• {$what} " . abs($days) . ' hari setelah transfer (wajar untuk uang muka)',
            $days <= 45    => "✓ {$what} {$days} hari sebelum transfer",
            $days <= 120   => "• {$what} {$days} hari sebelum transfer",
            default        => "✗ {$what} sudah {$days} hari sebelum transfer",
        }];
    }

    /**
     * Ditransfer sekitar jatuh tempo.
     */
    private function dueBonus(string $date, mixed $dueDate): int
    {
        $days = $this->daysAfter($date, $dueDate);

        return $days !== null && abs($days) <= 7 ? 5 : 0;
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
