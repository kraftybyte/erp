<?php

namespace App\Services\Telegram;

use App\Models\PaymentAccount;
use App\Models\User;
use App\Services\BankStatement\AiReconciler;
use App\Services\BankStatement\MutationMatcher;
use App\Services\BankStatement\MutationRecorder;
use App\Services\BankStatement\StatementReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Unggah mutasi rekening di bot laporan keuangan: file dibaca, tiap baris
 * dicarikan pasangannya (invoice, expense, advance), lalu Finance memastikan
 * dengan satu tombol. Yang ragu dipilih satu per satu. Tidak ada yang
 * berubah di ERP sebelum tombol Catat ditekan.
 *
 * Hak akses (Director, Finance, super_admin) diperiksa FinanceBot di setiap
 * pesan dan tombol.
 */
class BankStatementFlow
{
    private const TTL_MINUTES = 120;

    private const LIST_PAGE = 20;

    public function __construct(
        private TelegramClient $telegram,
        private StatementReader $reader,
        private MutationMatcher $matcher,
        private MutationRecorder $recorder,
        private ?AiReconciler $ai = null,
    ) {}

    public function help(int|string $chatId): void
    {
        $this->telegram->sendMessage($chatId, implode("\n", [
            '🏦 <b>Catat pembayaran dari mutasi rekening</b>',
            '',
            'Kirim file mutasi ke chat ini:',
            '• <b>CSV / Excel</b> dari internet banking (KlikBCA, Livin\', BRImo, …), dibaca tanpa AI',
            '• <b>PDF</b> rekening koran / e-statement',
            '• <b>Foto / screenshot</b> mutasi m-banking',
            '',
            'Bot mencocokkan setiap baris:',
            '⬇️ uang masuk → invoice yang belum lunas (nominal, nomor invoice, nama klien di berita transfer)',
            '⬆️ uang keluar → expense & advance yang sudah Disetujui (nominal, nama karyawan)',
            '',
            'Hasilnya ditampilkan dulu. Tidak ada yang berubah di ERP sebelum kamu menekan <b>Catat</b>. Mutasi yang sudah pernah dicatat atau sudah ada di ERP dilewati otomatis.',
        ]));
    }

    /**
     * Pesan berisi dokumen atau foto. Diunduh sekarang; dibaca setelah
     * Telegram menerima jawaban webhook, karena Gemini bisa butuh 1–2 menit.
     */
    public function receive(int|string $chatId, array $message, User $user): void
    {
        $file = $message['document'] ?? (isset($message['photo']) ? end($message['photo']) : null);

        if (! $file || ($file['file_size'] ?? 0) > StatementReader::MAX_BYTES) {
            $this->telegram->sendMessage($chatId, 'File mutasi maksimal 20 MB.');

            return;
        }

        $data = $this->telegram->download($file['file_id'], StatementReader::MAX_BYTES);

        if ($data === null) {
            $this->telegram->sendMessage($chatId, 'File tidak bisa diunduh dari Telegram. Coba kirim ulang.');

            return;
        }

        $this->forget($chatId);
        $this->telegram->sendMessage($chatId, '⏳ Membaca mutasi rekening… File panjang bisa butuh 1–2 menit.');

        $name = (string) ($message['document']['file_name'] ?? 'mutasi.jpg');
        $done = false;

        app()->terminating(function () use (&$done, $chatId, $data, $name, $user) {
            // Callback terminating tetap terdaftar di proses yang melayani beberapa request (tes).
            if ($done) {
                return;
            }

            $done = true;
            @set_time_limit(300);
            ignore_user_abort(true);

            try {
                $this->process($chatId, $data, $name, $user);
            } catch (Throwable $e) {
                report($e);
                AdminAlerts::exception($e, 'mutasi rekening');
                $this->telegram->sendMessage($chatId, '⚠️ Mutasi gagal diproses. Coba kirim ulang, atau kirim versi CSV/Excel.');
            }
        });
    }

    public function process(int|string $chatId, string $data, string $name, User $user): void
    {
        $result = $this->reader->read($data, $name);

        if (isset($result['error'])) {
            $this->telegram->sendMessage($chatId, '⚠️ ' . e($result['error']));

            return;
        }

        $statement = $result['statement'];
        $state     = [
            'upload'    => (string) Str::uuid(),
            'user_id'   => $user->id,
            'statement' => array_diff_key($statement, ['rows' => true]),
            'rows'      => $statement['rows'],
            'picked'    => [],
            'skipped'   => [],
        ];

        $account = $this->detectAccount($statement['account_number'] ?? null);

        if (! $account) {
            $this->save($chatId, $state);
            $this->askAccount($chatId, $statement['account_number'] ?? null);

            return;
        }

        $this->analyze($chatId, $state, $account, null);
    }

    public function handleCallback(array $callback, User $user): void
    {
        $chatId    = $callback['message']['chat']['id'];
        $messageId = $callback['message']['message_id'];
        $parts     = explode(':', (string) $callback['data']);
        [$action, $a, $b] = array_pad(array_slice($parts, 1), 3, null);
        $state     = $this->state($chatId);

        $this->telegram->answerCallback($callback['id'], $action === 'ok' ? 'Mencatat…' : null);

        if (! $state) {
            $this->telegram->removeButtons($chatId, $messageId);
            $this->telegram->sendMessage($chatId, 'Data mutasi ini sudah tidak berlaku. Kirim ulang file mutasinya.');

            return;
        }

        match ($action) {
            'acc'    => $this->chooseAccount($chatId, $messageId, $state, (int) $a),
            'ok'     => $this->confirm($chatId, $messageId, $state, $user),
            'rev'     => $this->startReview($chatId, $messageId, $state, 'pending'),
            'revall'  => $this->startReview($chatId, $messageId, $state, 'all'),
            'revnone' => $this->startReview($chatId, $messageId, $state, 'none'),
            'find'    => $this->startFind($chatId, $messageId, $state, (int) $a),
            'use'     => $this->useFound($chatId, $messageId, $state, (int) $a, (string) $b, (int) ($parts[4] ?? 0)),
            'pick'   => $this->pick($chatId, $messageId, $state, (int) $a, (int) $b),
            'skip'   => $this->skip($chatId, $messageId, $state, (int) $a),
            'sum'    => $this->showSummary($chatId, $messageId, $state),
            'list'   => $this->showList($chatId, $messageId, $state, (int) $a),
            'fees'   => $this->toggleFees($chatId, $messageId, $state),
            default  => $this->cancel($chatId, $messageId),
        };
    }

    // =================================================================
    // Rekening
    // =================================================================

    /**
     * Rekening ERP dari nomor rekening di mutasi; satu-satunya rekening aktif
     * bila nomornya tidak tertera. Null bila harus dipilih.
     */
    private function detectAccount(?string $number): ?PaymentAccount
    {
        $accounts = PaymentAccount::active()->orderBy('name')->get();
        $digits   = preg_replace('/\D/', '', (string) $number);

        if ($digits !== '') {
            $match = $accounts->first(function (PaymentAccount $account) use ($digits) {
                $own = preg_replace('/\D/', '', (string) $account->account_number);

                return $own !== '' && ($own === $digits || (strlen($own) >= 6 && strlen($digits) >= 6 && (str_ends_with($own, $digits) || str_ends_with($digits, $own))));
            });

            if ($match) {
                return $match;
            }

            return null;
        }

        return $accounts->count() === 1 ? $accounts->first() : null;
    }

    private function askAccount(int|string $chatId, ?string $number): void
    {
        $accounts = PaymentAccount::active()->orderBy('name')->get();

        if ($accounts->isEmpty()) {
            $this->telegram->sendMessage($chatId, 'Belum ada rekening aktif di ERP. Tambahkan dulu di menu Rekening, lalu kirim ulang mutasinya.');

            return;
        }

        $buttons = $accounts->map(fn (PaymentAccount $account) => [[
            'text'          => Text::short("{$account->name} · {$account->bank_name} {$account->account_number}", 50),
            'callback_data' => "mut:acc:{$account->id}",
        ]])->push([['text' => '❌ Batal', 'callback_data' => 'mut:x']])->all();

        $this->telegram->sendMessage(
            $chatId,
            '🏦 Mutasi ini untuk rekening yang mana?' . ($number ? "\nNomor rekening di mutasi: <b>" . e($number) . '</b> (tidak ada di daftar rekening ERP).' : ''),
            $buttons
        );
    }

    private function chooseAccount(int|string $chatId, int $messageId, array $state, int $accountId): void
    {
        $account = PaymentAccount::active()->find($accountId);

        if (! $account) {
            $this->askAccount($chatId, $state['statement']['account_number'] ?? null);

            return;
        }

        $this->analyze($chatId, $state, $account, $messageId);
    }

    private function analyze(int|string $chatId, array $state, PaymentAccount $account, ?int $messageId): void
    {
        $state['account_id'] = $account->id;
        $state['results']    = $this->matcher->match($state['rows'], $account);
        $state['picked']     = [];
        $state['skipped']    = [];

        // Pendapat kedua: Gemini sebagai konsultan keuangan & pajak senior.
        if ($this->ai) {
            $this->telegram->typing($chatId);
            $review            = $this->ai->review($state['rows'], $state['results']);
            $state['results']  = $review['results'];
            $state['ai']       = ['summary' => $review['summary'], 'error' => $review['error']];
        }

        $this->save($chatId, $state);
        $this->showSummary($chatId, $messageId, $state);
    }

    // =================================================================
    // Ringkasan
    // =================================================================

    private function showSummary(int|string $chatId, ?int $messageId, array $state): void
    {
        $account   = PaymentAccount::find($state['account_id']);
        $statement = $state['statement'];
        $rows      = $state['rows'];
        $results   = $state['results'];
        $selected  = $this->selection($state);
        $pending   = $this->pendingReview($state);
        $count     = fn (string $status) => count(array_filter($results, fn ($result) => $result['status'] === $status));
        $sum       = fn (string $direction) => array_sum(array_map(fn ($row) => $row['direction'] === $direction ? $row['amount'] : 0, $rows));
        $countDir  = fn (string $direction) => count(array_filter($rows, fn ($row) => $row['direction'] === $direction));

        $lines = [
            '🏦 <b>Mutasi ' . e(trim(($statement['bank'] ?? '') . ' ' . ($statement['account_number'] ?? ''))) . '</b>' . ($account ? ' → rekening ERP <b>' . e($account->name) . '</b>' : ''),
            $this->periodLine($statement, $rows) . ' · ' . count($rows) . ' transaksi',
            "⬇️ Masuk {$countDir('in')} · " . $this->rupiah($sum('in')) . "   ⬆️ Keluar {$countDir('out')} · " . $this->rupiah($sum('out')),
            $this->checkLine($statement),
        ];

        if ($account && ($statement['account_number'] ?? null) && ! str_contains(preg_replace('/\D/', '', (string) $account->account_number), preg_replace('/\D/', '', $statement['account_number']))
            && ! str_contains(preg_replace('/\D/', '', $statement['account_number']), preg_replace('/\D/', '', (string) $account->account_number))) {
            $lines[] = '⚠️ Nomor rekening di mutasi (' . e($statement['account_number']) . ') berbeda dengan rekening ERP yang dipilih (' . e((string) $account->account_number) . ').';
        }

        if ($state['ai']['summary'] ?? null) {
            $ai      = $state['ai']['summary'];
            $lines[] = "🤖 Diperiksa AI (konsultan keuangan): setuju {$ai['agree']} · meragukan {$ai['doubt']} · saran baru {$ai['added']}";
        } elseif ($state['ai']['error'] ?? null) {
            $lines[] = '🤖 Pemeriksaan AI dilewati: ' . e($state['ai']['error']);
        }

        if (($statement['skipped'] ?? 0) > 0) {
            $lines[] = "⚠️ {$statement['skipped']} baris tidak terbaca (tanggal/nominal) dan dilewati.";
        }

        $lines[] = '';
        $lines[] = '✅ <b>Siap dicatat (' . count($selected) . ')</b>' . ($selected ? '' : ' — belum ada');

        foreach (array_slice($selected, 0, 12, true) as $index => $candidate) {
            $chosen  = $results[$index]['candidates'][$candidate];
            $lines[] = '• ' . $this->rowHead($rows[$index]) . ' → ' . e(Text::short($chosen['label'], 70))
                . (isset($chosen['confidence']) && $results[$index]['status'] !== 'fee' ? " · <b>{$chosen['confidence']}%</b>" : '')
                . (isset($state['picked'][$index]) ? ' <i>(dipilih)</i>' : '');

            foreach ($this->targetDetails($chosen['targets'], short: true) as $detail) {
                $lines[] = "   {$detail}";
            }
        }

        if (count($selected) > 12) {
            $lines[] = '  …dan ' . (count($selected) - 12) . ' lainnya (lihat Rincian).';
        }

        if ($pending) {
            $lines[] = '';
            $lines[] = '🟡 <b>Perlu dipilih (' . count($pending) . ')</b>: ada kandidat, tapi belum pasti. Tekan <b>Tinjau</b>.';
        }

        $none = array_keys(array_filter($results, fn ($result) => $result['status'] === 'none'));

        if ($none) {
            $lines[] = '';
            $lines[] = '❓ <b>Tanpa pasangan di ERP (' . count($none) . ')</b>';

            foreach (array_slice($none, 0, 6) as $index) {
                $lines[] = '• ' . $this->rowHead($rows[$index]) . ' · ' . e(Text::short($rows[$index]['description'], 45))
                    . ($results[$index]['note'] ? "\n   <i>" . e($results[$index]['note']) . '</i>' : '');
            }

            if (count($none) > 6) {
                $lines[] = '  …dan ' . (count($none) - 6) . ' lainnya.';
            }
        }

        $other = array_filter([
            $count('recorded') ? "⏭ sudah tercatat di ERP {$count('recorded')}" : null,
            $count('duplicate') ? "♻️ sudah dicatat dari unggahan sebelumnya {$count('duplicate')}" : null,
            $count('fee') && empty($state['fees']) ? "🏦 biaya/bunga bank {$count('fee')}" : null,
            count($state['skipped']) ? '🚫 dilewati ' . count($state['skipped']) : null,
        ]);

        if ($other) {
            $lines[] = '';
            $lines[] = 'Tidak dicatat: ' . implode(' · ', $other) . '.';
        }

        $lines[] = '';
        $lines[] = 'Tidak ada yang berubah di ERP sebelum kamu menekan <b>Catat</b>.';

        $buttons = [];

        if ($selected) {
            $buttons[] = [['text' => '✅ Catat ' . count($selected) . ' mutasi', 'callback_data' => 'mut:ok']];
        }

        if ($pending) {
            $buttons[] = [['text' => '🟡 Tinjau ' . count($pending) . ' mutasi', 'callback_data' => 'mut:rev']];
        }

        $unmatched = array_filter($none, fn ($index) => ! isset($state['picked'][$index]) && ! isset($state['skipped'][$index]));

        if ($unmatched) {
            $buttons[] = [['text' => '🔎 Cari pasangan ' . count($unmatched) . ' mutasi tanpa pasangan', 'callback_data' => 'mut:revnone']];
        }

        // Biaya admin/pajak/bunga ikut dicatat supaya saldo ERP sama dengan saldo bank.
        if ($count('fee')) {
            $buttons[] = [['text' => empty($state['fees']) ? "🏦 Catat juga biaya/bunga bank ({$count('fee')})" : '🏦 Jangan catat biaya/bunga bank', 'callback_data' => 'mut:fees']];
        }

        $buttons[] = array_values(array_filter([
            $selected ? ['text' => '✏️ Periksa yang siap', 'callback_data' => 'mut:revall'] : null,
            ['text' => '📄 Rincian semua baris', 'callback_data' => 'mut:list:0'],
        ]));
        $buttons[] = [['text' => '❌ Batal', 'callback_data' => 'mut:x']];

        $this->show($chatId, $messageId, implode("\n", $lines), $buttons);
    }

    private function periodLine(array $statement, array $rows): string
    {
        $start = $statement['period_start'] ?? min(array_column($rows, 'date'));
        $end   = $statement['period_end'] ?? max(array_column($rows, 'date'));

        return 'Periode ' . $this->day($start) . ' – ' . $this->day($end, year: true);
    }

    private function checkLine(array $statement): string
    {
        $check = $statement['check'] ?? ['status' => 'unknown'];

        return match ($check['status']) {
            'ok'       => '✅ Saldo cocok (' . $check['method'] . '): tidak ada baris yang terlewat.',
            'mismatch' => $check['method'] === 'saldo awal & akhir'
                ? '⚠️ <b>Saldo tidak cocok</b>: saldo awal + mutasi selisih ' . $this->rupiah(abs((float) $check['difference'])) . ' dari saldo akhir. Mungkin ada baris yang terlewat atau salah baca; periksa sebelum mencatat.'
                : "⚠️ <b>Saldo per baris tidak berurutan di {$check['difference']} baris</b>: mungkin ada yang salah baca; periksa sebelum mencatat.",
            default    => 'ℹ️ Saldo awal/akhir tidak tertera, jadi kelengkapan baris tidak bisa diperiksa.',
        };
    }

    private function showList(int|string $chatId, int $messageId, array $state, int $page): void
    {
        $rows     = $state['rows'];
        $results  = $state['results'];
        $selected = $this->selection($state);
        $pages    = max(1, (int) ceil(count($rows) / self::LIST_PAGE));
        $page     = min(max($page, 0), $pages - 1);
        $icons    = ['sure' => '✅', 'review' => '🟡', 'none' => '❓', 'recorded' => '⏭', 'duplicate' => '♻️', 'fee' => '🏦'];

        $lines = ['📄 <b>Semua baris mutasi</b> · halaman ' . ($page + 1) . " dari {$pages}", ''];

        foreach (array_slice($rows, $page * self::LIST_PAGE, self::LIST_PAGE, true) as $index => $row) {
            $result = $results[$index];
            $icon   = isset($state['skipped'][$index]) ? '🚫' : (isset($selected[$index]) ? '✅' : $icons[$result['status']]);
            $target = isset($selected[$index]) ? ' → ' . e(Text::short($result['candidates'][$selected[$index]]['label'], 50)) : '';

            $lines[] = "{$icon} " . $this->rowHead($row) . ' · ' . e(Text::short($row['description'], 40)) . $target;
        }

        $lines[] = '';
        $lines[] = '✅ dicatat · 🟡 perlu dipilih · ❓ tanpa pasangan · ⏭ sudah di ERP · ♻️ sudah dicatat sebelumnya · 🏦 biaya bank · 🚫 dilewati';

        $nav = array_values(array_filter([
            $page > 0 ? ['text' => '⬅️ Sebelumnya', 'callback_data' => 'mut:list:' . ($page - 1)] : null,
            $page < $pages - 1 ? ['text' => 'Berikutnya ➡️', 'callback_data' => 'mut:list:' . ($page + 1)] : null,
        ]));

        $this->show($chatId, $messageId, implode("\n", $lines), array_values(array_filter([
            $nav ?: null,
            [['text' => '◀️ Kembali ke ringkasan', 'callback_data' => 'mut:sum']],
        ])));
    }

    // =================================================================
    // Tinjau
    // =================================================================

    /**
     * $mode: 'pending' (perlu dipilih, belum diputuskan), 'all' (juga yang
     * pasti, untuk diperiksa), atau 'none' (tanpa pasangan, untuk dicari manual).
     */
    private function startReview(int|string $chatId, int $messageId, array $state, string $mode): void
    {
        $state['queue'] = array_values(array_keys(array_filter(
            $state['results'],
            fn ($result) => match ($mode) {
                'none'  => $result['status'] === 'none',
                'all'   => in_array($result['status'], ['review', 'sure'], true),
                default => $result['status'] === 'review',
            }
        )));

        if ($mode !== 'all') {
            $state['queue'] = array_values(array_filter($state['queue'], fn ($index) => ! isset($state['picked'][$index]) && ! isset($state['skipped'][$index])));
        }

        $state['position'] = 0;
        $this->save($chatId, $state);
        $this->showReview($chatId, $messageId, $state);
    }

    private function showReview(int|string $chatId, int $messageId, array $state): void
    {
        $queue = $state['queue'] ?? [];
        $index = $queue[$state['position'] ?? 0] ?? null;

        if ($index === null) {
            $this->showSummary($chatId, $messageId, $state);

            return;
        }

        $row       = $state['rows'][$index];
        $result    = $state['results'][$index];
        $selection = $this->selection($state);

        $lines = [
            '🟡 <b>Tinjau ' . (($state['position'] ?? 0) + 1) . ' dari ' . count($queue) . '</b>',
            '',
            $this->rowHead($row, year: true),
            '<i>' . e(Text::short($row['description'], 300)) . '</i>',
            '',
            $result['candidates']
                ? ($row['direction'] === 'in' ? 'Pembayaran invoice yang mana?' : 'Pembayaran expense/advance yang mana?')
                : 'Belum ada kandidat.' . ($result['note'] ? "\n<i>" . e($result['note']) . '</i>' : '') . "\nTekan <b>🔎 Cari</b> lalu ketik nomor, nama klien/PIC, atau nominal.",
        ];

        $buttons = [];

        foreach ($result['candidates'] as $candidateIndex => $candidate) {
            $chosen  = ($selection[$index] ?? null) === $candidateIndex;
            // Alasan yang membuat kandidat ini cocok atau diragukan, dengan skor keyakinannya.
            // Rincian lengkap untuk dua kandidat teratas; sisanya singkat (batas panjang pesan Telegram).
            $details = $this->targetDetails($candidate['targets'], short: $candidateIndex >= 2);
            $lines[] = ($candidateIndex + 1) . '. ' . ($chosen ? '✅ ' : '') . e($candidate['label'])
                . (isset($candidate['confidence']) ? " — <b>{$candidate['confidence']}%</b>" : '')
                . ($details ? "\n   " . implode("\n   ", $details) : '')
                . ($candidate['reasons'] ?? [] ? "\n   " . implode("\n   ", array_map('e', array_slice($candidate['reasons'], 0, 7))) : '')
                . ($candidate['note'] ? "\n   <i>→ " . e($candidate['note']) . '</i>' : '');

            $buttons[] = [[
                'text'          => ($chosen ? '✅ ' : '') . ($candidateIndex + 1) . '. ' . Text::short($candidate['label'], 45),
                'callback_data' => "mut:pick:{$index}:{$candidateIndex}",
            ]];
        }

        $buttons[] = [['text' => $row['direction'] === 'in' ? '🔎 Cari invoice / proforma lain' : '🔎 Cari expense / advance lain', 'callback_data' => "mut:find:{$index}"]];
        $buttons[] = [
            ['text' => '🚫 Jangan dicatat', 'callback_data' => "mut:skip:{$index}"],
            ['text' => '◀️ Ringkasan', 'callback_data' => 'mut:sum'],
        ];

        $this->show($chatId, $messageId, implode("\n", $lines), $buttons);
    }

    // =================================================================
    // Cari manual
    // =================================================================

    public function awaitingText(int|string $chatId): bool
    {
        return isset($this->state($chatId)['finding']);
    }

    public function stopFinding(int|string $chatId): void
    {
        if ($state = $this->state($chatId)) {
            unset($state['finding']);
            $this->save($chatId, $state);
        }
    }

    private function startFind(int|string $chatId, int $messageId, array $state, int $index): void
    {
        if (! isset($state['rows'][$index])) {
            return;
        }

        $state['finding'] = $index;
        $this->save($chatId, $state);
        $row = $state['rows'][$index];

        $this->show($chatId, $messageId, '🔎 <b>Cari ' . ($row['direction'] === 'in' ? 'invoice / proforma' : 'expense / advance') . '</b> untuk ' . $this->rowHead($row) . "\n<i>" . e(Text::short($row['description'], 120)) . "</i>\n\n"
            . ($row['direction'] === 'in'
                ? 'Ketik nomor invoice (cukup sebagian, mis. <i>00012</i>), nama klien/PIC (mis. <i>nestory</i>), atau nominal (mis. <i>15jt</i>).'
                : 'Ketik ID pengajuan (mis. <i>45</i>), nama karyawan, atau nominal.'), [
            [['text' => '◀️ Kembali', 'callback_data' => 'mut:sum']],
        ]);
    }

    /**
     * Kata kunci pencarian manual: hasil diurutkan menurut kecocokan dengan
     * kata kunci dan dengan nominal mutasinya.
     */
    public function handleText(int|string $chatId, string $text): void
    {
        $state = $this->state($chatId);
        $index = $state['finding'] ?? null;

        if ($index === null || ! isset($state['rows'][$index])) {
            return;
        }

        $row   = $state['rows'][$index];
        $lines = ['🔎 Hasil untuk <i>' . e(Text::short($text, 40)) . '</i> · mutasi ' . $this->rowHead($row), ''];
        $rows  = [];

        if ($row['direction'] === 'in') {
            foreach ($this->matcher->searchInvoices($text, $row) as $number => $found) {
                $invoice = $found['invoice'];
                $label   = $this->matcher->invoiceLabel($invoice, $found['remaining']);
                $lines[] = ($number + 1) . '. ' . e($label)
                    . ($found['open'] ? '' : ' — <i>sudah Lunas</i>')
                    . "\n   " . implode("\n   ", $this->targetDetails([['type' => 'invoice', 'id' => $invoice->id]], short: true))
                    . ($found['explanation'] ? "\n   ✓ " . e($found['explanation']['reason']) : ($found['open'] ? "\n   • nominal mutasi tidak cocok dengan sisa tagihannya" : ''));

                if ($found['open']) {
                    $rows[] = [['text' => ($number + 1) . '. ' . Text::short($label, 45), 'callback_data' => "mut:use:{$index}:i:{$invoice->id}"]];
                }
            }
        } else {
            foreach ($this->matcher->searchPayees($text, $row) as $number => $found) {
                $code    = $found['payee'] instanceof \App\Models\Expense ? 'e' : 'a';
                $lines[] = ($number + 1) . '. ' . e($found['label']);
                $rows[]  = [['text' => ($number + 1) . '. ' . Text::short($found['label'], 45), 'callback_data' => "mut:use:{$index}:{$code}:{$found['payee']->id}"]];
            }
        }

        if (count($lines) === 2) {
            $lines[] = 'Tidak ada yang cocok. Coba kata lain: sebagian nomor, nama klien/PIC, atau nominal.';
        } else {
            $lines[] = '';
            $lines[] = 'Pilih yang benar, atau ketik kata kunci lain.';
        }

        $rows[] = [['text' => '◀️ Kembali ke tinjau', 'callback_data' => 'mut:rev'], ['text' => '📋 Ringkasan', 'callback_data' => 'mut:sum']];

        $this->telegram->sendMessage($chatId, implode("\n", $lines), $rows);
    }

    /**
     * Hasil pencarian dipilih: menjadi kandidat terpilih baris ini. Nominal
     * mutasi dijelaskan seperti pencocokan otomatis (potongan PPh 23, WAPU,
     * biaya transfer); di bawah sisa tagihan menjadi pembayaran sebagian.
     */
    private function useFound(int|string $chatId, int $messageId, array $state, int $index, string $code, int $id): void
    {
        $row = $state['rows'][$index] ?? null;

        if (! $row) {
            return;
        }

        if ($code === 'i') {
            $invoice   = \App\Models\Invoice::with('deal')->find($id);
            $remaining = $invoice ? \App\Services\InvoicePayment::remaining($invoice) : 0;

            if (! $invoice || $invoice->status === 'paid' || $remaining <= 0) {
                $this->telegram->sendMessage($chatId, 'Invoice ini sudah lunas atau tidak ditemukan.');

                return;
            }

            if ($row['amount'] > $remaining + 1) {
                $this->telegram->sendMessage($chatId, '⚠️ Nominal mutasi ' . $this->rupiah($row['amount']) . ' melebihi sisa tagihan ' . $this->rupiah($remaining) . '. Pilih invoice lain, atau catat sebagian di ERP.');

                return;
            }

            $explanation = $this->matcher->explainAmount($invoice, $remaining, $row['amount'], true);
            $target      = $this->matcher->invoiceTarget($invoice, round($row['amount'], 2))
                + (($explanation['withholding'] ?? 0) > 0 ? ['withholding' => $explanation['withholding'], 'withholding_label' => $explanation['label']] : []);
            $candidate = [
                'targets'    => [$target],
                'label'      => '🔎 ' . $this->matcher->invoiceLabel($invoice, $remaining),
                'note'       => $explanation['note'] ?? null,
                'identity'   => true,
                'days'       => null,
                'confidence' => null,
                'reasons'    => array_values(array_filter(['🔎 dipilih lewat pencarian', isset($explanation['reason']) ? '✓ ' . $explanation['reason'] : null])),
            ];
        } else {
            $payee = $code === 'e' ? \App\Models\Expense::with('user')->find($id) : \App\Models\Advance::with('user')->find($id);

            if (! $payee || $payee->status !== 'Disetujui' || $row['amount'] > (float) $payee->amount + 1) {
                $this->telegram->sendMessage($chatId, 'Pengajuan ini sudah dibayarkan, tidak ditemukan, atau nominalnya lebih kecil dari mutasi.');

                return;
            }

            $candidate = [
                'targets'    => [['type' => $code === 'e' ? 'expense' : 'advance', 'id' => $payee->id, 'amount' => round($row['amount'], 2)]],
                'label'      => '🔎 ' . ($code === 'e' ? 'Expense' : 'Advance') . " #{$payee->id} · " . trim(($payee->user?->firstname ?? '') . ' ' . ($payee->user?->lastname ?? '')),
                'note'       => null,
                'identity'   => true,
                'days'       => null,
                'confidence' => null,
                'reasons'    => ['🔎 dipilih lewat pencarian'],
            ];
        }

        $state['results'][$index]['candidates'][] = $candidate;
        $state['results'][$index]['status']       = $state['results'][$index]['status'] === 'none' ? 'review' : $state['results'][$index]['status'];
        $state['picked'][$index]                  = array_key_last($state['results'][$index]['candidates']);
        unset($state['skipped'][$index], $state['finding']);

        $this->telegram->sendMessage($chatId, '✅ Dipilih: ' . e($candidate['label']) . ($candidate['note'] ? "\n<i>→ " . e($candidate['note']) . '</i>' : ''));

        isset($state['queue']) ? $this->next($chatId, $messageId, $state) : $this->showSummary($chatId, null, $state);
    }

    private function pick(int|string $chatId, int $messageId, array $state, int $index, int $candidate): void
    {
        if (isset($state['results'][$index]['candidates'][$candidate])) {
            $state['picked'][$index] = $candidate;
            unset($state['skipped'][$index]);
        }

        $this->next($chatId, $messageId, $state);
    }

    private function skip(int|string $chatId, int $messageId, array $state, int $index): void
    {
        if (isset($state['results'][$index])) {
            $state['skipped'][$index] = true;
            unset($state['picked'][$index]);
        }

        $this->next($chatId, $messageId, $state);
    }

    private function next(int|string $chatId, int $messageId, array $state): void
    {
        $state['position'] = ($state['position'] ?? 0) + 1;
        $this->save($chatId, $state);
        $this->showReview($chatId, $messageId, $state);
    }

    // =================================================================
    // Catat
    // =================================================================

    private function confirm(int|string $chatId, int $messageId, array $state, User $user): void
    {
        $account  = PaymentAccount::find($state['account_id']);
        $selected = $this->selection($state);

        if (! $account || ! $selected) {
            $this->showSummary($chatId, $messageId, $state);

            return;
        }

        // Dihapus dulu: tombol yang ditekan dua kali tidak mencatat dua kali.
        $this->forget($chatId);
        $this->telegram->removeButtons($chatId, $messageId);

        $items = [];
        foreach ($selected as $index => $candidate) {
            $items[] = ['row' => $state['rows'][$index], 'targets' => $state['results'][$index]['candidates'][$candidate]['targets']];
        }

        $outcome = $this->recorder->record($account, $user, $items, $state['upload']);
        $account->refresh();

        $byType = collect($outcome['recorded'])->flatMap(fn ($item) => $item['targets'])->groupBy('type')->map->count();
        $closing = $state['statement']['closing'] ?? null;

        $lines = [
            '✅ <b>' . count($outcome['recorded']) . ' mutasi dicatat</b> ke rekening ' . e($account->name),
        ];

        foreach (['invoice' => 'invoice dibayar', 'expense' => 'expense dibayarkan', 'advance' => 'advance dibayarkan', 'bank_fee' => 'biaya/pajak bank dicatat', 'bank_interest' => 'bunga bank dicatat'] as $type => $label) {
            if ($byType->get($type)) {
                $lines[] = "• {$byType->get($type)} {$label}";
            }
        }

        $withheld = collect($outcome['recorded'])->flatMap(fn ($item) => $item['targets'])->sum(fn ($target) => $target['withholding'] ?? 0);

        if ($withheld > 0) {
            $lines[] = '• potongan pajak/selisih ' . $this->rupiah($withheld) . ' dicatat di invoice (untuk PPh 23/PPN WAPU, minta bukti potong/pungutnya ke klien)';
        }

        $lines[] = 'Saldo rekening di ERP sekarang <b>' . $this->rupiah($account->current_balance) . '</b>'
            . ($closing !== null ? ' · saldo akhir di mutasi ' . $this->rupiah($closing)
                . (abs((float) $account->current_balance - $closing) < 1 ? ' ✅' : ' (selisih ' . $this->rupiah(abs((float) $account->current_balance - $closing)) . ')') : '');

        if ($outcome['failed']) {
            $lines[] = '';
            $lines[] = '⚠️ <b>Gagal dicatat (' . count($outcome['failed']) . ')</b>';

            foreach (array_slice($outcome['failed'], 0, 10) as $failed) {
                $lines[] = '• ' . $this->rowHead($failed['row']) . ': ' . e($failed['reason']);
            }
        }

        $left = count(array_filter(
            $state['results'],
            fn ($result, $index) => in_array($result['status'], ['review', 'none'], true) && ! isset($selected[$index]),
            ARRAY_FILTER_USE_BOTH
        ));

        if ($left > 0) {
            $lines[] = '';
            $lines[] = "❓ {$left} mutasi tanpa pasangan / belum dipilih tidak dicatat. Catat manual di ERP bila perlu, atau kirim ulang mutasinya nanti (yang sudah dicatat dilewati otomatis).";
        }

        $link    = url('/admin/bank-mutations');
        $buttons = str_starts_with($link, 'https://') ? [[['text' => 'Lihat di ERP', 'url' => $link]]] : [];

        $this->telegram->sendMessage($chatId, implode("\n", $lines), $buttons);
    }

    private function toggleFees(int|string $chatId, int $messageId, array $state): void
    {
        $state['fees'] = empty($state['fees']);
        $this->save($chatId, $state);
        $this->showSummary($chatId, $messageId, $state);
    }

    private function cancel(int|string $chatId, int $messageId): void
    {
        $this->forget($chatId);
        $this->telegram->removeButtons($chatId, $messageId);
        $this->telegram->sendMessage($chatId, 'Dibatalkan. Tidak ada yang dicatat.');
    }

    // =================================================================
    // Alat bantu
    // =================================================================

    /**
     * Baris yang akan dicatat dan kandidat pilihannya: yang pasti, ditambah
     * pilihan saat tinjau, dikurangi yang ditandai "jangan dicatat".
     *
     * @return array<int, int> indeks baris => indeks kandidat
     */
    private function selection(array $state): array
    {
        $selected = [];

        foreach ($state['results'] ?? [] as $index => $result) {
            if (isset($state['skipped'][$index])) {
                continue;
            }

            if (isset($state['picked'][$index])) {
                $selected[$index] = $state['picked'][$index];
            } elseif ($result['status'] === 'sure' || ($result['status'] === 'fee' && ! empty($state['fees']) && $result['candidates'])) {
                $selected[$index] = $result['pick'] ?? 0;
            }
        }

        return $selected;
    }

    /**
     * @return array<int, int> indeks baris yang masih perlu dipilih
     */
    private function pendingReview(array $state): array
    {
        return array_keys(array_filter(
            $state['results'] ?? [],
            fn ($result, $index) => $result['status'] === 'review' && ! isset($state['picked'][$index]) && ! isset($state['skipped'][$index]),
            ARRAY_FILTER_USE_BOTH
        ));
    }

    /**
     * "invoice dibuat 5 hari sebelum transfer": jarak tanggal membantu memilih
     * di antara tagihan bernominal sama.
     */
    private function distance(?int $days, string $direction): ?string
    {
        if ($days === null) {
            return null;
        }

        $what = $direction === 'in' ? 'invoice dibuat' : 'diajukan';

        return match (true) {
            $days === 0 => "{$what} di hari yang sama dengan transfer",
            $days > 0   => "{$what} {$days} hari sebelum transfer",
            default     => "{$what} " . abs($days) . ' hari setelah transfer',
        };
    }

    /**
     * Rincian tagihan yang dibayar mutasi, supaya Finance tahu persis yang
     * mana: tanggal, jatuh tempo, jenis pembayaran, total/terbayar/sisa,
     * item, project, dan nomor PO (invoice); keterangan, kategori, dan project
     * (expense/advance). $short: satu baris untuk ringkasan.
     *
     * @return array<int, string> baris HTML
     */
    private function targetDetails(array $targets, bool $short = false): array
    {
        $ids      = collect($targets)->filter(fn ($target) => isset($target['id']))->groupBy('type')->map(fn ($group) => $group->pluck('id')->all());
        $invoices = \App\Models\Invoice::with(['invoicesItems', 'projects', 'parentInvoice'])->whereIn('id', $ids->get('invoice', []))->get()->keyBy('id');
        $expenses = \App\Models\Expense::with('project')->whereIn('id', $ids->get('expense', []))->get()->keyBy('id');
        $advances = \App\Models\Advance::with('project')->whereIn('id', $ids->get('advance', []))->get()->keyBy('id');
        $lines    = [];

        foreach ($targets as $target) {
            // Biaya/bunga bank tidak punya tagihan.
            if (! isset($target['id'])) {
                continue;
            }

            if ($target['type'] === 'invoice' && ($invoice = $invoices->get($target['id']))) {
                $items = $invoice->invoicesItems->map(fn ($item) => trim((string) ($item->item ?: $item->description))
                    . ((float) $item->qty > 1 ? ' — ' . rtrim(rtrim(number_format((float) $item->qty, 2, ',', '.'), '0'), ',') . ' × ' . $this->rupiah($item->price) : ' — ' . $this->rupiah($item->total ?: $item->price)))
                    ->filter(fn ($line) => ! str_starts_with($line, ' —'));

                if ($short) {
                    $lines[] = '📦 ' . e(Text::short($items->first() ?? '-', 45)) . ($items->count() > 1 ? ' +' . ($items->count() - 1) . ' item' : '')
                        . ' · ' . $this->day((string) $invoice->date) . ' · ' . e($this->paymentType($invoice));

                    continue;
                }

                $lines[] = '🗓 ' . $this->day((string) $invoice->date, year: true) . ($invoice->due_date ? ' · jatuh tempo ' . $this->day((string) $invoice->due_date) : '')
                    . ' · ' . e($this->paymentType($invoice));
                $lines[] = '💰 Total ' . $this->rupiah($invoice->grand_total) . ' · terbayar ' . $this->rupiah($invoice->paid) . ' · sisa ' . $this->rupiah(\App\Services\InvoicePayment::remaining($invoice));

                foreach ($items->take(4) as $item) {
                    $lines[] = '📦 ' . e(Text::short($item, 70));
                }

                if ($items->count() > 4) {
                    $lines[] = '📦 +' . ($items->count() - 4) . ' item lain';
                }

                if ($project = $invoice->projects->first()) {
                    $lines[] = '📁 Project: ' . e(Text::short((string) $project->nama_project, 50));
                }

                if (filled($invoice->po_number)) {
                    $lines[] = '🧾 PO: ' . e((string) $invoice->po_number);
                }

                continue;
            }

            $payee = $target['type'] === 'expense' ? $expenses->get($target['id']) : $advances->get($target['id']);

            if ($payee) {
                $what    = $payee instanceof \App\Models\Expense ? $payee->keterangan_expense : $payee->keterangan_advance;
                $lines[] = '🗒 ' . e(Text::short((string) $what, $short ? 45 : 90))
                    . ($short ? '' : ($payee->category ? ' · 🏷 ' . e($payee->category) : '') . ' · 🗓 ' . $this->day((string) $payee->submission_date)
                        . ($payee->project ? ' · 📁 ' . e(Text::short((string) $payee->project->nama_project, 40)) : ''));
            }
        }

        return $lines;
    }

    private function paymentType(\App\Models\Invoice $invoice): string
    {
        $type = (string) $invoice->type_payment;

        return match (true) {
            $invoice->type === 'estimate'        => 'Proforma',
            str_starts_with($type, 'dp_')        => 'DP ' . (int) substr($type, 3) . '%',
            $type === 'final_payment'            => 'Pelunasan' . ($invoice->parentInvoice ? ' dari ' . $invoice->parentInvoice->uuid : ''),
            default                              => 'Full payment',
        };
    }

    private function rowHead(array $row, bool $year = false): string
    {
        return $this->day($row['date'], $year) . ' ' . ($row['direction'] === 'in' ? '⬇️' : '⬆️') . ' ' . $this->rupiah($row['amount']);
    }

    private function day(string $date, bool $year = false): string
    {
        return Carbon::parse($date)->locale('id')->translatedFormat($year ? 'j M Y' : 'j M');
    }

    private function rupiah(float|string|null $amount): string
    {
        return 'Rp ' . number_format((float) $amount, 0, ',', '.');
    }

    private function show(int|string $chatId, ?int $messageId, string $html, array $buttons): void
    {
        if ($messageId && $this->telegram->editMessage($chatId, $messageId, $html, $buttons)) {
            return;
        }

        $this->telegram->sendMessage($chatId, $html, $buttons);
    }

    private function state(int|string $chatId): ?array
    {
        return Cache::get($this->key($chatId));
    }

    private function save(int|string $chatId, array $state): void
    {
        Cache::put($this->key($chatId), $state, now()->addMinutes(self::TTL_MINUTES));
    }

    private function forget(int|string $chatId): void
    {
        Cache::forget($this->key($chatId));
    }

    private function key(int|string $chatId): string
    {
        return "telegram:finance-mutasi:{$chatId}";
    }
}
