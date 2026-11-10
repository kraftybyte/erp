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
 * - fee       biaya admin, pajak, atau bunga bank;
 * - other     bukan pembayaran tagihan: pengembalian dana atau salah transfer.
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

    // Kata isi invoice yang terlalu umum untuk membedakan satu invoice dari yang lain.
    private const GENERIC = [
        'jasa', 'biaya', 'pembuatan', 'pembayaran', 'pelunasan', 'lunas', 'termin', 'tahap', 'uang', 'muka', 'down', 'payment', 'sisa',
        'tagihan', 'invoice', 'proforma', 'project', 'proyek', 'paket', 'bulan', 'bulanan', 'service', 'services', 'total', 'unit', 'item',
        'untuk', 'dengan', 'pengerjaan', 'pekerjaan', 'pemasangan', 'produksi', 'desain', 'design',
    ];

    /** @var array<int, array<int, string>> id invoice => kata isi invoice (item, nama, catatan, project) */
    private array $content = [];

    /** @var array<string, int> kata isi => jumlah invoice terbuka yang memuatnya */
    private array $frequency = [];

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

        $this->indexContent($invoices);

        $senders = $this->knownSenders();
        $results = [];
        $open    = [];

        foreach ($rows as $index => $row) {
            $other = $this->notABill($row);

            $results[$index] = match (true) {
                $processed->has($row['key']) => $this->result('duplicate', note: 'Sudah dicatat dari unggahan mutasi sebelumnya.'),
                isset($recorded[$index])      => $this->result('recorded', note: $recorded[$index]),
                $this->isFee($row)            => $this->feeResult($row),
                $other !== null               => $this->result('other', note: $other),
                $row['direction'] === 'out'   => $this->classify($this->payoutCandidates($row, $payees)),
                default                       => null,
            };

            if ($results[$index] === null) {
                $open[] = $index;
            }
        }

        // Uang masuk: transfer yang dipecah dinilai bersama, lalu pengirim yang
        // sudah pasti di file ini membantu baris lain dari pengirim yang sama.
        $groups = $this->splitGroups($rows, $open);

        foreach ($open as $index) {
            $results[$index] = $this->classify($this->incomingCandidates($rows, $index, $groups, $invoices, $senders));
        }

        $learned = $this->statementSenders($rows, $results, $invoices);

        if ($learned !== []) {
            foreach ($open as $index) {
                $own = $this->senderWords($rows[$index]);

                if (in_array($results[$index]['status'], ['review', 'none'], true)
                    && array_filter($learned, fn ($sender) => $sender['row'] !== $index && BcaDescription::sameSender($own, $sender['words']))) {
                    $results[$index] = $this->classify($this->incomingCandidates($rows, $index, $groups, $invoices, array_merge($senders, $learned)));
                }
            }
        }

        $results = $this->resolveConflicts($rows, $results, $invoices);

        foreach ($open as $index) {
            if ($results[$index]['status'] === 'none') {
                $results[$index]['note'] = $this->numberHint($rows[$index]) ?? $this->alreadyPaidHint($rows[$index]);
            }
        }

        return $results;
    }

    /**
     * Satu invoice yang dianggap pasti oleh beberapa baris sekaligus dengan
     * jumlah melebihi sisa tagihannya: hanya yang paling yakin tetap pasti,
     * sisanya ditinjau. Transfer yang dipecah jumlahnya pas, jadi tetap pasti.
     *
     * @param  Collection<int, Invoice>  $invoices
     */
    private function resolveConflicts(array $rows, array $results, Collection $invoices): array
    {
        $claims = [];

        foreach ($results as $index => $result) {
            if ($result['status'] !== 'sure' || $rows[$index]['direction'] !== 'in') {
                continue;
            }

            $candidate = $result['candidates'][$result['pick']];

            foreach ($candidate['targets'] as $target) {
                if ($target['type'] === 'invoice') {
                    $claims[$target['id']][] = ['row' => $index, 'value' => $target['amount'] + ($target['withholding'] ?? 0), 'confidence' => $candidate['confidence']];
                }
            }
        }

        $byId = $invoices->keyBy('id');

        foreach ($claims as $id => $list) {
            $invoice = $byId->get($id);

            if (count($list) < 2 || ! $invoice || array_sum(array_column($list, 'value')) <= InvoicePayment::remaining($invoice) + self::TOLERANCE) {
                continue;
            }

            usort($list, fn ($a, $b) => [$b['confidence'], $a['row']] <=> [$a['confidence'], $b['row']]);

            foreach (array_slice($list, 1) as $claim) {
                $results[$claim['row']]['status'] = 'review';
                $results[$claim['row']]['pick']   = null;
                $results[$claim['row']]['note']   = "{$invoice->uuid} juga cocok dengan mutasi " . $this->rupiah($rows[$list[0]['row']]['amount'])
                    . ' tanggal ' . Carbon::parse($rows[$list[0]['row']]['date'])->locale('id')->translatedFormat('j M') . '; jumlah keduanya melebihi sisa tagihan.';
            }
        }

        return $results;
    }

    /**
     * Pengembalian dana dan salah transfer: bukan pembayaran invoice atau
     * pengajuan, jadi tidak dicocokkan. Null bila bukan keduanya.
     */
    private function notABill(array $row): ?string
    {
        $bca  = BcaDescription::parse($row['description']);
        $who  = $bca['sender'] !== '' ? $bca['sender'] : $bca['counterparty'];
        $who  = $who !== '' ? ' ' . ($row['direction'] === 'in' ? 'dari' : 'ke') . ' ' . $who : '';

        return match ($bca['purpose']) {
            'refund' => $row['direction'] === 'in'
                ? "Pengembalian dana{$who}: bukan pembayaran invoice, tidak dicatat otomatis."
                : "Pengembalian dana{$who}: bukan expense/advance, tidak dicatat otomatis.",
            'wrong'  => $row['direction'] === 'in'
                ? "Salah transfer{$who}: bukan pendapatan; biasanya dikembalikan ke pengirim."
                : "Salah transfer{$who}: minta dikembalikan; bukan expense/advance.",
            default  => null,
        };
    }

    /**
     * Kandidat satu baris uang masuk, ditambah kandidat dari transfer yang
     * dipecah (Rp 20 jt + Rp 9 jt untuk satu tagihan Rp 29 jt).
     *
     * @param  array<int, array<int, int>>  $groups  indeks baris => anggota kelompoknya
     */
    private function incomingCandidates(array $rows, int $index, array $groups, Collection $invoices, array $senders): array
    {
        $own = $this->invoiceCandidates($rows[$index], $invoices, $senders);

        if (! isset($groups[$index])) {
            return $own;
        }

        $members = $groups[$index];
        $amounts = array_map(fn ($member) => $rows[$member]['amount'], $members);
        $total   = round(array_sum($amounts), 2);
        $whole   = ['amount' => $total] + $rows[$members[0]];
        $last    = end($members) === $index;
        $split   = '✓ transfer dipecah ' . count($members) . 'x di hari yang sama (berita/referensi sama): '
            . implode(' + ', array_map(fn ($amount) => $this->rupiah($amount), $amounts)) . ' = ' . $this->rupiah($total);

        $joined = [];

        foreach ($this->invoiceCandidates($whole, $invoices, $senders) as $candidate) {
            if (! empty($candidate['combo']) || $candidate['kind'] === 'partial' || count($candidate['targets']) !== 1) {
                continue;
            }

            // Potongan (PPh 23, biaya) dicatat sekali, di baris terakhir kelompoknya.
            $target = ['amount' => round($rows[$index]['amount'], 2)] + $candidate['targets'][0];

            if (! $last) {
                unset($target['withholding'], $target['withholding_label']);
            }

            $joined[] = array_merge($candidate, [
                'targets' => [$target],
                'note'    => 'bagian dari transfer ' . $this->rupiah($total) . ' yang dipecah ' . count($members) . 'x'
                    . ($last && $candidate['note'] ? '; ' . $candidate['note'] : ''),
                'reasons' => [$split, ...$candidate['reasons']],
                'score'   => $candidate['score'] + 5,
            ]);
        }

        if ($joined === []) {
            return $own;
        }

        // Pembayaran sebagian ke invoice yang sudah dijelaskan oleh gabungannya tidak perlu ditampilkan lagi.
        $covered = array_map(fn ($candidate) => $candidate['targets'][0]['id'] ?? null, $joined);
        $own     = array_filter($own, fn ($candidate) => ! (in_array($candidate['kind'], ['partial', 'dp'], true) && count($candidate['targets']) === 1 && in_array($candidate['targets'][0]['id'] ?? null, $covered, true)));

        return [...$joined, ...array_values($own)];
    }

    /**
     * Baris uang masuk yang tampaknya satu pembayaran yang dipecah: tanggal
     * sama dan nomor referensi sama, atau berita dan pengirim sama persis.
     *
     * @param  array<int, int>  $open
     * @return array<int, array<int, int>> indeks baris => anggota kelompoknya (2–6 baris)
     */
    private function splitGroups(array $rows, array $open): array
    {
        $buckets = [];

        foreach ($open as $index) {
            $bca = BcaDescription::parse($rows[$index]['description']);
            $key = $bca['reference'] ?? ($bca['sender'] !== '' && $bca['remark'] !== '' ? $this->compact($bca['remark']) . '|' . $this->compact($bca['sender']) : null);

            if ($key !== null) {
                $buckets[$rows[$index]['date'] . '|' . $key][] = $index;
            }
        }

        $groups = [];

        foreach ($buckets as $members) {
            if (count($members) >= 2 && count($members) <= 6) {
                foreach ($members as $member) {
                    $groups[$member] = $members;
                }
            }
        }

        return $groups;
    }

    /**
     * Pengirim dari baris yang sudah pasti di file ini dan klien yang
     * dibayarnya: baris lain dari pengirim yang sama kemungkinan besar
     * membayar klien yang sama.
     *
     * @return array<int, array{words: array<int, string>, deals: array<int, int>, companies: array<int, string>, source: string, row: int}>
     */
    private function statementSenders(array $rows, array $results, Collection $invoices): array
    {
        $byId    = $invoices->keyBy('id');
        $senders = [];

        foreach ($results as $index => $result) {
            if ($result['status'] !== 'sure' || $rows[$index]['direction'] !== 'in') {
                continue;
            }

            $words = $this->senderWords($rows[$index]);
            $paid  = collect($result['candidates'][$result['pick']]['targets'])->where('type', 'invoice')->map(fn ($target) => $byId->get($target['id']))->filter();

            if ($words && $paid->isNotEmpty()) {
                $senders[] = [
                    'words'     => $words,
                    'deals'     => $paid->pluck('deal_id')->filter()->unique()->values()->all(),
                    'companies' => $paid->map(fn (Invoice $invoice) => mb_strtolower(trim((string) $invoice->nama_perusahaan)))->filter()->unique()->values()->all(),
                    'source'    => 'statement',
                    'row'       => $index,
                ];
            }
        }

        return $senders;
    }

    /**
     * Kata nama pengirim: bagian nama rekening bila keterangannya terpisah
     * (KlikBCA Bisnis), selain itu seluruh lawan transaksi.
     *
     * @return array<int, string>
     */
    private function senderWords(array $row): array
    {
        $bca = BcaDescription::parse($row['description']);

        return BcaDescription::nameWords($bca['sender'] !== '' ? $bca['sender'] : $bca['counterparty']);
    }

    /**
     * Berita menyebut nomor invoice/proforma, tapi tidak ada pasangannya:
     * jelaskan keadaan invoice itu (sudah Lunas, dibatalkan, nominal lebih besar).
     */
    private function numberHint(array $row): ?string
    {
        $remark = BcaDescription::parse($row['description'])['counterparty'];

        if (! preg_match_all('/(\d{7})\D{0,3}?(\d{3,6})(?!\d)/', $remark, $found, PREG_SET_ORDER)) {
            return null;
        }

        foreach ($found as [, $code, $sequence]) {
            $invoice = Invoice::whereIn('type', ['sale', 'estimate'])
                ->where(fn ($query) => $query->where('uuid', 'like', "%{$code}%{$sequence}")->orWhere('proforma_uuid', 'like', "%{$code}%{$sequence}"))
                ->latest('id')->first();

            if (! $invoice) {
                continue;
            }

            $remaining = InvoicePayment::remaining($invoice);
            $named     = $invoice->uuid . ($invoice->proforma_uuid && ! str_contains(preg_replace('/\D/', '', $invoice->uuid), $code . $sequence) ? " (dulu {$invoice->proforma_uuid})" : '');

            return match (true) {
                $invoice->status === 'paid' || $remaining <= 0 => "Berita menyebut {$named}, yang sudah Lunas. Mungkin sudah dicatat manual, atau lebih bayar.",
                $invoice->status === 'cancelled'               => "Berita menyebut {$named}, yang sudah dibatalkan.",
                $row['amount'] > $remaining + self::TOLERANCE  => "Berita menyebut {$named}, tapi nominal transfer lebih besar dari sisa tagihannya (" . $this->rupiah($remaining) . '). Periksa: lebih bayar atau untuk beberapa invoice.',
                default                                        => null,
            };
        }

        return null;
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

            if ($amount && collect([$remaining, $invoice->amountDue(), (float) $invoice->grand_total])->contains(fn ($value) => abs($value - $amount) <= self::TOLERANCE)) {
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
     * Invoice bayar penuh (bukan invoice DP, bukan pelunasan): yang ditagih = Total.
     */
    public static function billsFullPrice(Invoice $invoice): bool
    {
        return $invoice->dpPercentage() === null && $invoice->type_payment !== 'final_payment';
    }

    /**
     * Invoice penjualan yang belum lunas dan proforma yang belum dibayar.
     *
     * @return Collection<int, Invoice>
     */
    public static function openInvoices(): Collection
    {
        return Invoice::with(['deal', 'parentInvoice', 'invoicesItems:id,invoice_id,item,description', 'projects:id,invoice_id,nama_project'])
            ->whereIn('type', ['sale', 'estimate'])->whereNotIn('status', ['paid', 'cancelled'])->get()
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
        $known   = array_values(array_filter($senders, fn ($known) => BcaDescription::sameSender($this->senderWords($row), $known['words'])));
        $purpose = $bca['purpose'];

        // Kata di berita (tanpa nama rekening pengirim) yang cocok dengan isi invoice.
        $remark  = array_values(array_filter($this->words($bca['remark'] !== '' ? $bca['remark'] : $text)['words'], fn ($word) => mb_strlen($word) >= 4 && ! in_array($word, self::GENERIC, true)));
        $content = [];
        foreach ($invoices as $invoice) {
            $content[$invoice->id] = $this->contentMatch($remark, $invoice);
        }
        $bestContent = $content ? max(array_column($content, 'score')) : 0;
        $bestOwner   = $bestContent > 0 ? $invoices->first(fn (Invoice $invoice) => $content[$invoice->id]['score'] === $bestContent && $content[$invoice->id]['identity']) : null;

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
            $proforma    = ! $number && $invoice->proforma_uuid && $this->numberMatch($invoice->proforma_uuid, $compact, $words);
            $po          = $this->poMatch($invoice, $compact);
            $matchedName = $this->nameMatches($words, $this->clientTokens($invoice));
            $name        = $this->nameScore($words, $this->clientTokens($invoice));
            $learned     = $this->paidBefore($known, $invoice);
            $about       = $content[$invoice->id];
            $identity    = $number || $proforma || $po || $name > 0 || $learned !== null || $about['identity'];
            $explanation = $this->explainAmount($invoice, $remaining, $amount, $identity);

            if ($explanation === null) {
                continue;
            }

            // "DP" di berita: pembayaran sebagian pertama berapa pun persennya adalah uang muka.
            if ($purpose === 'dp' && $explanation['kind'] === 'partial' && (float) $invoice->paid <= 0 && (float) $invoice->grand_total > 0 && self::billsFullPrice($invoice)) {
                $percent     = round($amount / (float) $invoice->grand_total * 100);
                $explanation = ['kind' => 'dp', 'score' => 35, 'withholding' => 0.0, 'label' => null,
                    'reason' => "berita menyebut DP: {$percent}% dari total " . $this->rupiah($invoice->grand_total),
                    'note'   => "pembayaran DP {$percent}%; sisa setelah ini " . $this->rupiah($remaining - $amount)];
            }

            $days    = $this->daysAfter($row['date'], $invoice->date);
            $client  = $this->clientKey($invoice);
            $reasons = ['✓ ' . $explanation['reason']];
            $score   = $explanation['score'];

            if ($number || $proforma) {
                $score += 35;
                $reasons[] = $proforma ? "✓ nomor proforma {$invoice->proforma_uuid} tertulis di berita (sekarang {$invoice->uuid})" : '✓ nomor invoice tertulis di berita';
            }

            if ($po) {
                $score += 35;
                $reasons[] = '✓ nomor PO klien tertulis di berita';
            }

            if ($name > 0) {
                $score += $name === 2 ? 30 : 15;
                $reasons[] = '✓ nama klien di berita (' . mb_strtoupper(implode(' ', $matchedName)) . ')';
            }

            if ($learned !== null) {
                $score += $learned === 'statement' ? 20 : 25;
                $reasons[] = $learned === 'statement'
                    ? '✓ pengirim yang sama membayar invoice klien ini di baris lain file ini'
                    : '✓ pengirim ini pernah membayar invoice klien yang sama';
            }

            if ($about['score'] > 0) {
                $score += $about['score'];
                $reasons[] = ($about['score'] >= 8 ? '✓' : '•') . ' berita menyebut isi invoice (' . mb_strtoupper(implode(', ', $about['words'])) . ')';
            }

            if ($bestOwner && $bestOwner->id !== $invoice->id && $about['score'] <= $bestContent - 15 && ! $number && ! $proforma && ! $po) {
                $score -= 15;
                $reasons[] = '✗ berita lebih cocok dengan isi ' . $bestOwner->uuid;
            }

            if ($purpose === 'dp' && $explanation['kind'] === 'dp' && ! str_starts_with($explanation['reason'], 'berita')) {
                $score += 10;
                $reasons[] = '✓ berita menyebut DP';
            } elseif ($purpose === 'settlement') {
                $settles   = in_array($explanation['kind'], ['exact', 'pph', 'wapu', 'fee', 'diff'], true);
                $score    += $settles ? 10 : -10;
                $reasons[] = $settles ? '✓ berita menyebut pelunasan' : '✗ berita menyebut pelunasan, tapi nominal kurang dari sisa tagihan';
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

        // Pola DP hanya untuk invoice bayar penuh: invoice DP dan pelunasan sudah
        // menagih bagiannya sendiri (Invoice::amountDue()), jadi DP-nya = sisa tagihan persis.
        if ((float) $invoice->paid <= 0 && $total > 0 && self::billsFullPrice($invoice)) {
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
            $words = $this->senderWords(['description' => (string) $mutation->description]);
            $paid  = collect($mutation->targets)->where('type', 'invoice')->map(fn ($target) => $invoices->get($target['id']))->filter();

            if ($words && $paid->isNotEmpty()) {
                $senders[] = [
                    'words'     => $words,
                    'deals'     => $paid->pluck('deal_id')->filter()->unique()->values()->all(),
                    'companies' => $paid->map(fn (Invoice $invoice) => mb_strtolower(trim((string) $invoice->nama_perusahaan)))->filter()->unique()->values()->all(),
                    'source'    => 'history',
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

    /**
     * 'history' bila pengirim ini pernah membayar klien yang sama lewat mutasi
     * yang sudah dicatat, 'statement' bila dari baris lain di file ini, null bila tidak.
     */
    private function paidBefore(array $known, Invoice $invoice): ?string
    {
        $company = mb_strtolower(trim((string) $invoice->nama_perusahaan));
        $found   = null;

        foreach ($known as $sender) {
            if (($invoice->deal_id && in_array($invoice->deal_id, $sender['deals'], true)) || ($company !== '' && in_array($company, $sender['companies'], true))) {
                if (($sender['source'] ?? 'history') === 'history') {
                    return 'history';
                }

                $found = 'statement';
            }
        }

        return $found;
    }

    // =================================================================
    // Isi invoice (item, nama, catatan, project) di berita transfer
    // =================================================================

    /**
     * Kata isi setiap invoice terbuka dan berapa invoice yang memuatnya:
     * kata yang hanya ada di satu-dua invoice ("Grandmall", "Sukamaju") membedakan,
     * kata yang ada di banyak invoice ("signage") hampir tidak.
     *
     * @param  Collection<int, Invoice>  $invoices
     */
    private function indexContent(Collection $invoices): void
    {
        $this->content   = [];
        $this->frequency = [];

        foreach ($invoices as $invoice) {
            $tokens = array_values(array_diff($this->contentTokens($invoice), $this->clientTokens($invoice)));
            $this->content[$invoice->id] = $tokens;

            foreach ($tokens as $token) {
                $this->frequency[$token] = ($this->frequency[$token] ?? 0) + 1;
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function contentTokens(Invoice $invoice): array
    {
        $parts = [$invoice->name, Text::short(strip_tags((string) $invoice->notes), 300)];

        foreach ($invoice->invoicesItems ?? [] as $item) {
            $parts[] = $item->item;
            $parts[] = Text::short(strip_tags((string) $item->description), 200);
        }

        foreach ($invoice->projects ?? [] as $project) {
            $parts[] = $project->nama_project;
        }

        return array_values(array_filter(
            $this->tokens(implode(' ', array_filter($parts))),
            fn (string $token) => mb_strlen($token) >= 4 && ! in_array($token, self::GENERIC, true) && ! in_array($token, self::NOISE, true),
        ));
    }

    /**
     * Kata berita yang cocok dengan isi invoice. Kata khas (ada di ≤ 2 invoice
     * atau ≤ 10% invoice terbuka) bernilai 8–12, kata umum 3; maksimal 30.
     * Dianggap identitas bila dua kata khas cocok, atau satu kata ≥ 5 huruf
     * yang hanya ada di invoice ini. Salah ketik satu huruf ("SIGNANGE") dan
     * kata terpotong di belakang tetap dihitung.
     *
     * @param  array<int, string>  $remark
     * @return array{score: int, words: array<int, string>, identity: bool}
     */
    private function contentMatch(array $remark, Invoice $invoice): array
    {
        $tokens = $this->content[$invoice->id] ?? [];

        if ($tokens === [] || $remark === []) {
            return ['score' => 0, 'words' => [], 'identity' => false];
        }

        $limit    = max(2, intdiv(count($this->content), 10));
        $score    = 0;
        $words    = [];
        $distinct = 0;
        $unique   = false;

        foreach ($tokens as $token) {
            foreach ($remark as $word) {
                if (! $this->similarWord($word, $token)) {
                    continue;
                }

                $frequency = $this->frequency[$token] ?? 1;

                if ($frequency <= $limit) {
                    $score += mb_strlen($token) >= 5 ? 12 : 8;
                    $distinct++;
                    $unique = $unique || ($frequency === 1 && mb_strlen($token) >= 5);
                } else {
                    $score += 3;
                }

                $words[] = $word;

                break;
            }
        }

        return ['score' => min($score, 30), 'words' => array_values(array_unique($words)), 'identity' => $distinct >= 2 || $unique];
    }

    private function similarWord(string $word, string $token): bool
    {
        if ($word === $token) {
            return true;
        }

        $wordLength  = mb_strlen($word);
        $tokenLength = mb_strlen($token);

        return ($wordLength >= 5 && str_starts_with($token, $word))
            || ($wordLength >= 6 && $tokenLength >= 6 && abs($wordLength - $tokenLength) <= 1 && levenshtein($word, $token) <= 1);
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
                    $number     = $collection->contains(fn (Invoice $invoice) => $this->numberMatch($invoice->uuid, $compact, $words) || ($invoice->proforma_uuid && $this->numberMatch($invoice->proforma_uuid, $compact, $words)) || $this->poMatch($invoice, $compact));
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
                        'identity' => $name > 0 || $learned !== null || $number,
                        'kind'     => $withPph ? 'combo-pph' : 'combo',
                        'exact'    => true,
                        'combo'    => true,
                        'client'   => $client,
                        'days'     => $days,
                        'score'    => ($withPph ? 45 : 50) + ($name === 2 ? 30 : ($name ? 15 : 0)) + ($learned !== null ? 25 : 0) + ($number ? 35 : 0) + $dateScore,
                        'reasons'  => array_values(array_filter([
                            '✓ nominal = jumlah sisa ' . count($subset) . ' invoice klien yang sama' . ($withPph ? ' setelah PPh 23' : ''),
                            $name ? '✓ nama klien di berita' : null,
                            $learned !== null ? '✓ pengirim ini pernah membayar invoice klien yang sama' : null,
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
