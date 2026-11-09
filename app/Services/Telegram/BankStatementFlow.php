<?php

namespace App\Services\Telegram;

use App\Models\PaymentAccount;
use App\Models\User;
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
            'rev'    => $this->startReview($chatId, $messageId, $state, all: false),
            'revall' => $this->startReview($chatId, $messageId, $state, all: true),
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

        if (($statement['skipped'] ?? 0) > 0) {
            $lines[] = "⚠️ {$statement['skipped']} baris tidak terbaca (tanggal/nominal) dan dilewati.";
        }

        $lines[] = '';
        $lines[] = '✅ <b>Siap dicatat (' . count($selected) . ')</b>' . ($selected ? '' : ' — belum ada');

        foreach (array_slice($selected, 0, 12, true) as $index => $candidate) {
            $lines[] = '• ' . $this->rowHead($rows[$index]) . ' → ' . e(Text::short($results[$index]['candidates'][$candidate]['label'], 70))
                . (isset($state['picked'][$index]) ? ' <i>(dipilih)</i>' : '');
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
                $lines[] = '• ' . $this->rowHead($rows[$index]) . ' · ' . e(Text::short($rows[$index]['description'], 45));
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

    private function startReview(int|string $chatId, int $messageId, array $state, bool $all): void
    {
        $state['queue'] = array_values(array_keys(array_filter(
            $state['results'],
            fn ($result) => $result['status'] === 'review' || ($all && $result['status'] === 'sure')
        )));

        // Tanpa "semua": hanya yang belum diputuskan.
        if (! $all) {
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
            $row['direction'] === 'in' ? 'Pembayaran invoice yang mana?' : 'Pembayaran expense/advance yang mana?',
        ];

        $buttons = [];

        foreach ($result['candidates'] as $candidateIndex => $candidate) {
            $chosen  = ($selection[$index] ?? null) === $candidateIndex;
            $lines[] = ($candidateIndex + 1) . '. ' . ($chosen ? '✅ ' : '') . e($candidate['label'])
                . ($this->distance($candidate['days'] ?? null, $row['direction']) ? "\n   <i>" . $this->distance($candidate['days'], $row['direction']) . '</i>' : '')
                . ($candidate['note'] ? "\n   <i>" . e($candidate['note']) . '</i>' : '')
                . ($candidate['identity'] ? '' : "\n   <i>nama/nomor tidak ada di berita transfer</i>");

            $buttons[] = [[
                'text'          => ($chosen ? '✅ ' : '') . ($candidateIndex + 1) . '. ' . Text::short($candidate['label'], 45),
                'callback_data' => "mut:pick:{$index}:{$candidateIndex}",
            ]];
        }

        $buttons[] = [
            ['text' => '🚫 Jangan dicatat', 'callback_data' => "mut:skip:{$index}"],
            ['text' => '◀️ Ringkasan', 'callback_data' => 'mut:sum'],
        ];

        $this->show($chatId, $messageId, implode("\n", $lines), $buttons);
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
            $lines[] = '• potongan PPh 23 ' . $this->rupiah($withheld) . ' dicatat di invoice (minta bukti potongnya ke klien)';
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
