<?php

namespace App\Services\Telegram;

use App\Helpers\ClientLabel;
use App\Models\Advance;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Services\BankStatement\AiReconciler;
use App\Services\BankStatement\BcaDescription;
use App\Services\BankStatement\MutationMatcher;
use App\Services\BankStatement\MutationRecorder;
use App\Services\BankStatement\StatementReader;
use App\Services\InvoicePayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Unggah mutasi rekening di bot laporan keuangan: file dibaca, tiap baris
 * dicarikan pasangannya (invoice, expense, advance), lalu Finance memastikan
 * dengan satu tombol. Yang ragu dipilih satu per satu. Tidak ada yang
 * berubah di ERP sebelum tombol Catat ditekan.
 *
 * Hak akses (izin Shield create_bank::mutation) diperiksa FinanceBot di setiap
 * pesan dan tombol.
 */
class BankStatementFlow
{
    private const TTL_MINUTES = 120;

    /** Jeda sebelum membaca album: foto berikutnya di album yang sama masih berdatangan. */
    private const ALBUM_WAIT_SECONDS = 4;

    private const LIST_PAGE = 20;

    // Panjang pesan Telegram maksimal 4096 (unit UTF-16); sisakan ruang untuk tombol dan emoji.
    private const BUDGET = 3600;

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
            '• <b>Foto / screenshot</b> mutasi m-banking. Mutasi panjang: kirim beberapa screenshot sekaligus sebagai satu album, digabung jadi satu mutasi',
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

        $since = (int) ($message['message_id'] ?? 0);

        // File terbaru di chat ini; yang lebih lama dan selesai dibaca belakangan (album) dilewati.
        Cache::put($this->latestKey($chatId), max($since, (int) Cache::get($this->latestKey($chatId), 0)), now()->addMinutes(self::TTL_MINUTES));

        $name = (string) ($message['document']['file_name'] ?? 'mutasi.jpg');

        // Album screenshot (mutasi panjang = beberapa layar): disimpan dulu, lalu dibaca sekaligus.
        if (($group = $message['media_group_id'] ?? null) && str_starts_with((string) StatementReader::type($data, $name), 'image/')) {
            $this->receiveAlbumPhoto($chatId, (string) $group, $data, $since, $user);

            return;
        }

        $this->forget($chatId);
        $this->telegram->sendMessage($chatId, '⏳ Membaca mutasi rekening… File panjang bisa butuh 1–2 menit.');

        $done = false;

        app()->terminating(function () use (&$done, $chatId, $data, $name, $user, $since) {
            // Callback terminating tetap terdaftar di proses yang melayani beberapa request (tes).
            if ($done) {
                return;
            }

            $done = true;
            @set_time_limit(300);
            ignore_user_abort(true);

            try {
                $this->process($chatId, $data, $name, $user, $since);
            } catch (Throwable $e) {
                report($e);
                AdminAlerts::exception($e, 'mutasi rekening');
                $this->telegram->sendMessage($chatId, '⚠️ Mutasi gagal diproses. Coba kirim ulang, atau kirim versi CSV/Excel.');
            }
        });
    }

    private function receiveAlbumPhoto(int|string $chatId, string $group, string $data, int $messageId, User $user): void
    {
        $dir   = $this->albumDir($chatId, $group);
        $first = ! is_dir($dir);

        File::ensureDirectoryExists($dir);
        File::put("{$dir}/{$messageId}", $data);

        if ($first) {
            $this->forget($chatId);
            $this->telegram->sendMessage($chatId, '⏳ Membaca screenshot mutasi… Semua foto di album ini digabung jadi satu mutasi.');
        }

        $done = false;

        app()->terminating(function () use (&$done, $chatId, $group, $user, $messageId) {
            if ($done) {
                return;
            }

            $done = true;
            @set_time_limit(300);
            ignore_user_abort(true);

            try {
                $this->processAlbum($chatId, $group, $user, $messageId);
            } catch (Throwable $e) {
                report($e);
                AdminAlerts::exception($e, 'mutasi rekening (album)');
                $this->telegram->sendMessage($chatId, '⚠️ Screenshot mutasi gagal diproses. Coba kirim ulang albumnya.');
            }
        });
    }

    /**
     * Foto-foto album datang sebagai pesan terpisah dalam hitungan detik.
     * Setelah menunggu sebentar, hanya foto terakhir yang membaca semuanya,
     * sehingga satu album = satu ringkasan dengan satu tombol Catat.
     */
    public function processAlbum(int|string $chatId, string $group, User $user, int $messageId): void
    {
        if (! app()->runningUnitTests()) {
            sleep(self::ALBUM_WAIT_SECONDS);
        }

        $dir = $this->albumDir($chatId, $group);
        $ids = collect(File::files($dir))->map(fn ($file) => (int) $file->getFilename())->sort()->values();

        if ($ids->isEmpty() || $messageId !== $ids->last()) {
            return;
        }

        $images = $ids->map(fn (int $id) => File::get("{$dir}/{$id}"))->all();
        File::deleteDirectory($dir);

        $this->afterRead($chatId, $this->reader->readImages($images), count($images) . ' screenshot mutasi', $user, $messageId);
    }

    /**
     * @param  int  $since  message_id file mutasinya; tombol di pesan yang lebih lama milik unggahan sebelumnya
     */
    public function process(int|string $chatId, string $data, string $name, User $user, int $since = 0): void
    {
        $this->afterRead($chatId, $this->reader->read($data, $name), $name, $user, $since);
    }

    /**
     * @param  array{statement?: array, error?: string}  $result
     */
    private function afterRead(int|string $chatId, array $result, string $name, User $user, int $since): void
    {
        if ($this->superseded($chatId, $since, $name)) {
            return;
        }

        if (isset($result['error'])) {
            $this->telegram->sendMessage($chatId, '⚠️ ' . e($result['error']));

            return;
        }

        $statement = $result['statement'];
        $state     = [
            'upload'    => (string) Str::uuid(),
            'since'     => $since,
            'name'      => $name,
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

        // message_id di chat pribadi selalu naik: pesan sebelum file yang aktif milik unggahan lama,
        // jadi tombol Catat di ringkasan Oktober tidak mencatat mutasi November.
        if (! $state || $messageId < ($state['since'] ?? 0)) {
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
            'ready'  => $this->showReady($chatId, $messageId, $state, (int) $a),
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

        if ($this->superseded($chatId, (int) ($state['since'] ?? 0), $state['name'] ?? null)) {
            return;
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
            '🏦 <b>Mutasi ' . e(trim(($statement['bank'] ?? '') . ' ' . ($statement['account_number'] ?? ''))) . '</b>'
                . (($statement['holder'] ?? null) ? ' a.n. ' . e(Text::short($statement['holder'], 40)) : '')
                . ($account ? ' → rekening ERP <b>' . e($account->name) . '</b>' : ''),
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

        // Bagian bawah dulu, supaya pasangan yang siap dicatat diisi sebanyak yang muat di satu pesan.
        $tail = [];

        if ($pending) {
            $tail[] = '';
            $tail[] = '🟡 <b>Perlu dipilih (' . count($pending) . ')</b>: ada kandidat, tapi belum pasti. Tekan <b>Tinjau</b>.';
        }

        $none = array_keys(array_filter($results, fn ($result) => $result['status'] === 'none'));

        if ($none) {
            $tail[] = '';
            $tail[] = '❓ <b>Tanpa pasangan di ERP (' . count($none) . ')</b>';
            array_push($tail, ...$this->looseBlocks($rows, $results, $none));
        }

        // Pengembalian dana dan salah transfer: ditampilkan supaya Finance menindaklanjuti, tidak dicatat.
        $notBills = array_keys(array_filter($results, fn ($result) => $result['status'] === 'other'));

        if ($notBills) {
            $tail[] = '';
            $tail[] = '↩️ <b>Bukan pembayaran tagihan (' . count($notBills) . ')</b>';
            array_push($tail, ...$this->looseBlocks($rows, $results, $notBills));
        }

        $other = array_filter([
            $count('recorded') ? "⏭ sudah tercatat di ERP {$count('recorded')}" : null,
            $count('duplicate') ? "♻️ sudah dicatat dari unggahan sebelumnya {$count('duplicate')}" : null,
            $count('fee') && empty($state['fees']) ? "🏦 biaya/bunga bank {$count('fee')}" : null,
            count($state['skipped']) ? '🚫 dilewati ' . count($state['skipped']) : null,
        ]);

        if ($other) {
            $tail[] = '';
            $tail[] = 'Tidak dicatat: ' . implode(' · ', $other) . '.';
        }

        $tail[] = '';
        $tail[] = 'Tidak ada yang berubah di ERP sebelum kamu menekan <b>Catat</b>.';

        // Satu blok per mutasi: dari siapa, ke invoice/pengajuan mana, rinciannya, dan akibatnya setelah dicatat.
        $lines[] = '';
        $lines[] = '✅ <b>Siap dicatat (' . count($selected) . ')</b>' . ($selected ? '' : ' — belum ada');
        $blocks  = $this->readyBlocks($state);
        $shown   = 0;

        foreach ($blocks as $block) {
            if ($this->length([...$lines, '', ...$block, '', '…dan 99 lainnya: tekan 📋 Lihat semua yang siap dicatat.', ...$tail]) > self::BUDGET) {
                break;
            }

            $lines[] = '';
            array_push($lines, ...$block);
            $shown++;
        }

        $hidden = count($blocks) - $shown;

        if ($hidden > 0) {
            $lines[] = '';
            $lines[] = "…dan {$hidden} lainnya: tekan <b>📋 Lihat semua yang siap dicatat</b>.";
        }

        array_push($lines, ...$tail);

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

        if ($hidden > 0) {
            $buttons[] = [['text' => '📋 Lihat semua yang siap dicatat (' . count($blocks) . ')', 'callback_data' => 'mut:ready:0']];
        }

        $buttons[] = array_values(array_filter([
            $selected ? ['text' => '✏️ Periksa yang siap', 'callback_data' => 'mut:revall'] : null,
            ['text' => '📄 Rincian semua baris', 'callback_data' => 'mut:list:0'],
        ]));
        $buttons[] = [['text' => '❌ Batal', 'callback_data' => 'mut:x']];

        $this->show($chatId, $messageId, implode("\n", $lines), $buttons);
    }

    /**
     * Semua pasangan yang siap dicatat, dalam blok yang sama dengan ringkasan,
     * dibagi per halaman menurut panjang pesan.
     */
    private function showReady(int|string $chatId, int $messageId, array $state, int $page): void
    {
        $blocks = array_values($this->readyBlocks($state));

        if ($blocks === []) {
            $this->showSummary($chatId, $messageId, $state);

            return;
        }

        $pages   = [];
        $current = [];

        foreach ($blocks as $block) {
            $joined = $current ? [...$current, '', ...$block] : $block;

            if ($current && $this->length($joined) > self::BUDGET - 300) {
                $pages[] = $current;
                $joined  = $block;
            }

            $current = $joined;
        }

        $pages[] = $current;
        $page    = min(max($page, 0), count($pages) - 1);
        $lines   = ['📋 <b>Siap dicatat (' . count($blocks) . ')</b> · halaman ' . ($page + 1) . ' dari ' . count($pages), '', ...$pages[$page]];

        $nav = array_values(array_filter([
            $page > 0 ? ['text' => '⬅️ Sebelumnya', 'callback_data' => 'mut:ready:' . ($page - 1)] : null,
            $page < count($pages) - 1 ? ['text' => 'Berikutnya ➡️', 'callback_data' => 'mut:ready:' . ($page + 1)] : null,
        ]));

        $this->show($chatId, $messageId, implode("\n", $lines), array_values(array_filter([
            $nav ?: null,
            [['text' => '✅ Catat ' . count($blocks) . ' mutasi', 'callback_data' => 'mut:ok']],
            [['text' => '◀️ Kembali ke ringkasan', 'callback_data' => 'mut:sum']],
        ])));
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
            'ok'       => ($check['method'] === 'total & jumlah mutasi' ? '✅ Cocok dengan ringkasan bank (total & jumlah mutasi)' : '✅ Saldo cocok (' . $check['method'] . ')') . ': tidak ada baris yang terlewat.',
            'mismatch' => match ($check['method']) {
                'saldo awal & akhir'    => '⚠️ <b>Saldo tidak cocok</b>: saldo awal + mutasi selisih ' . $this->rupiah(abs((float) $check['difference'])) . ' dari saldo akhir. Mungkin ada baris yang terlewat atau salah baca; periksa sebelum mencatat.',
                'total & jumlah mutasi' => '⚠️ <b>Tidak cocok dengan ringkasan bank</b>: ' . e((string) $check['difference']) . '. Mungkin ada baris yang terlewat atau salah baca; periksa sebelum mencatat.',
                default                 => "⚠️ <b>Saldo per baris tidak berurutan di {$check['difference']} baris</b>: mungkin ada yang salah baca; periksa sebelum mencatat.",
            },
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
        $icons    = ['sure' => '✅', 'review' => '🟡', 'none' => '❓', 'recorded' => '⏭', 'duplicate' => '♻️', 'fee' => '🏦', 'other' => '↩️'];

        $lines = ['📄 <b>Semua baris mutasi</b> · halaman ' . ($page + 1) . " dari {$pages}", ''];

        foreach (array_slice($rows, $page * self::LIST_PAGE, self::LIST_PAGE, true) as $index => $row) {
            $result = $results[$index];
            $icon   = isset($state['skipped'][$index]) ? '🚫' : (isset($selected[$index]) ? '✅' : $icons[$result['status']]);
            $target = isset($selected[$index]) ? ' → ' . e(Text::short($result['candidates'][$selected[$index]]['label'], 50)) : '';

            $lines[] = "{$icon} " . $this->rowHead($row) . ' · ' . e($this->brief($row, 40)) . $target;
        }

        $lines[] = '';
        $lines[] = '✅ dicatat · 🟡 perlu dipilih · ❓ tanpa pasangan · ↩️ bukan pembayaran tagihan · ⏭ sudah di ERP · ♻️ sudah dicatat sebelumnya · 🏦 biaya bank · 🚫 dilewati';

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
            '<b>' . $this->rowHead($row, year: true) . '</b>',
            '<i>' . e(Text::short($row['description'], 300)) . '</i>',
            ...$this->parsedLines($row),
            '',
            $result['candidates']
                ? ($row['direction'] === 'in' ? 'Pembayaran invoice yang mana?' : 'Pembayaran expense/advance yang mana?')
                : 'Belum ada kandidat.',
        ];

        if (! $result['candidates']) {
            if ($result['note']) {
                $lines[] = '<i>' . e($result['note']) . '</i>';
            }

            $lines[] = 'Tekan <b>🔎 Cari</b> lalu ketik nomor, nama klien/PIC, atau nominal.';
        }

        $buttons = [];
        $models  = $this->targetModels(array_merge(...array_map(fn ($candidate) => $candidate['targets'], $result['candidates'] ?: [['targets' => []]])));
        $running = $this->runningBefore($state, $index);

        foreach ($result['candidates'] as $candidateIndex => $candidate) {
            $chosen = ($selection[$index] ?? null) === $candidateIndex;
            // Setiap pilihan satu blok: tagihan dan rinciannya, akibatnya bila dicatat,
            // lalu alasan cocok/ragu. Dua teratas lengkap; sisanya ringkas (batas panjang pesan).
            $copy    = $running;
            $lines[] = '';
            $lines[] = '<b>' . ($chosen ? '✅ ' : '') . 'Pilihan ' . ($candidateIndex + 1) . '</b>' . (isset($candidate['confidence']) ? " — <b>{$candidate['confidence']}%</b>" : '');

            foreach ($candidate['targets'] as $target) {
                array_push($lines, ...$this->targetBlock($target, $models, $copy, $candidateIndex < 2 ? 4 : 0));
            }

            if ($candidate['note'] ?? null) {
                $lines[] = '📝 ' . e($candidate['note']);
            }

            foreach (array_slice($candidate['reasons'] ?? [], 0, $candidateIndex < 2 ? 8 : 3) as $reason) {
                $lines[] = e($reason);
            }

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

            // Disetujui, atau advance bayar paralel yang realisasinya belum dibayar seluruhnya.
            $payable = $payee && ($payee->status === 'Disetujui'
                || ($payee instanceof \App\Models\Advance && \App\Services\AdvanceParallel::allows($payee) && in_array($payee->status, \App\Services\AdvanceParallel::REALIZING, true)));

            if (! $payable || $row['amount'] > \App\Services\BankStatement\MutationMatcher::payeeLimit($payee) + 1) {
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

        // Hasilnya per mutasi: invoice mana yang sekarang lunas atau berapa sisanya.
        $tail = [];

        if ($outcome['failed']) {
            $tail[] = '';
            $tail[] = '⚠️ <b>Gagal dicatat (' . count($outcome['failed']) . ')</b>';

            foreach (array_slice($outcome['failed'], 0, 10) as $failed) {
                $tail[] = '• ' . $this->rowHead($failed['row']) . ': ' . e($failed['reason']);
            }
        }

        $left = count(array_filter(
            $state['results'],
            fn ($result, $index) => in_array($result['status'], ['review', 'none'], true) && ! isset($selected[$index]),
            ARRAY_FILTER_USE_BOTH
        ));

        if ($left > 0) {
            $tail[] = '';
            $tail[] = "❓ {$left} mutasi tanpa pasangan / belum dipilih tidak dicatat. Catat manual di ERP bila perlu, atau kirim ulang mutasinya nanti (yang sudah dicatat dilewati otomatis).";
        }

        // Sisa tagihan sesudah tiap mutasi, bukan sesudah semuanya: dihitung mundur dari sisa
        // terakhir (dua transfer untuk satu invoice: "sisa Rp …" lalu "LUNAS").
        $later = [];
        $after = [];

        foreach (array_reverse($outcome['recorded'], true) as $number => $item) {
            foreach ($item['targets'] as $position => $target) {
                if ($target['type'] === 'invoice') {
                    $after[$number][$position] = $later[$target['id']] ?? 0.0;
                    $later[$target['id']]      = ($later[$target['id']] ?? 0.0) + (float) $target['amount'] + (float) ($target['withholding'] ?? 0);
                }
            }
        }

        $shown = 0;

        foreach ($outcome['recorded'] as $number => $item) {
            $block = ['<b>' . ($number + 1) . ') ' . $this->rowHead($item['row'], year: true) . '</b>'];

            foreach ($item['targets'] as $position => $target) {
                $block[] = $this->recordedLine($target, $after[$number][$position] ?? 0.0);
            }

            if ($this->length([...$lines, '', ...$block, '', '…dan 99 mutasi lainnya (lihat di ERP).', ...$tail]) > self::BUDGET) {
                break;
            }

            $lines[] = '';
            array_push($lines, ...$block);
            $shown++;
        }

        if ($shown < count($outcome['recorded'])) {
            $lines[] = '';
            $lines[] = '…dan ' . (count($outcome['recorded']) - $shown) . ' mutasi lainnya (lihat di ERP).';
        }

        array_push($lines, ...$tail);

        $link    = url('/admin/bank-mutations');
        $buttons = str_starts_with($link, 'https://') ? [[['text' => 'Lihat di ERP', 'url' => $link]]] : [];

        $this->telegram->sendMessage($chatId, $this->fit(implode("\n", $lines)), $buttons);
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

                array_push($lines, ...$this->invoiceLines($invoice, 4));

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

    // =================================================================
    // Blok mutasi → tagihan
    // =================================================================

    /**
     * Blok setiap mutasi yang siap dicatat, berurutan seperti saat dicatat;
     * sisa tagihan dihitung berjalan, jadi dua transfer untuk satu invoice
     * terlihat "sisa jadi Rp …" lalu "LUNAS".
     *
     * @return array<int, array<int, string>> indeks baris => baris pesan
     */
    private function readyBlocks(array $state): array
    {
        $chosen = [];

        foreach ($this->selection($state) as $index => $candidate) {
            $chosen[$index] = $state['results'][$index]['candidates'][$candidate];
        }

        $models  = $this->targetModels(array_merge([], ...array_map(fn ($candidate) => $candidate['targets'], array_values($chosen))));
        $running = [];
        $blocks  = [];
        $number  = 0;

        foreach ($chosen as $index => $candidate) {
            $lines = ['<b>' . ++$number . ') ' . $this->rowHead($state['rows'][$index], year: true) . '</b>' . (isset($state['picked'][$index]) ? ' <i>(dipilih)</i>' : ''), ...$this->partyLines($state['rows'][$index])];

            foreach ($candidate['targets'] as $target) {
                array_push($lines, ...$this->targetBlock($target, $models, $running, 3, compactRepeats: true));
            }

            if ($candidate['note'] ?? null) {
                $lines[] = '📝 ' . e(Text::short($candidate['note'], 160));
            }

            $blocks[$index] = [...$lines, ...$this->reasonLines($candidate)];
        }

        return $blocks;
    }

    /**
     * Mutasi tanpa pasangan / bukan pembayaran tagihan: siapa, beritanya, dan
     * keterangannya; maksimal empat, sisanya di Rincian semua baris.
     *
     * @param  array<int, int>  $indexes
     * @return array<int, string>
     */
    private function looseBlocks(array $rows, array $results, array $indexes): array
    {
        $lines = [];

        foreach (array_slice($indexes, 0, 4) as $index) {
            $lines[] = '';
            $lines[] = '<b>' . $this->rowHead($rows[$index], year: true) . '</b>';
            array_push($lines, ...$this->partyLines($rows[$index]));

            if ($results[$index]['note']) {
                $lines[] = '<i>ℹ️ ' . e(Text::short($results[$index]['note'], 200)) . '</i>';
            }
        }

        if (count($indexes) > 4) {
            $lines[] = '';
            $lines[] = '…dan ' . (count($indexes) - 4) . ' lainnya (lihat 📄 Rincian semua baris).';
        }

        return $lines;
    }

    /**
     * Pengirim/penerima dan berita transfer, tanpa kode bank.
     *
     * @return array<int, string>
     */
    private function partyLines(array $row): array
    {
        $bca = BcaDescription::parse($row['description']);

        if ($bca['sender'] === '') {
            return ['💬 ' . e(Text::short($bca['counterparty'] !== '' ? $bca['counterparty'] : $row['description'], 90))];
        }

        return array_values(array_filter([
            '👤 ' . ($row['direction'] === 'in' ? 'Dari' : 'Ke') . ' <b>' . e($bca['sender']) . '</b>',
            $bca['remark'] !== '' ? '💬 ' . e(Text::short($bca['remark'], 90)) : null,
        ]));
    }

    /**
     * Invoice, expense, dan advance dari target-target, dimuat sekali.
     *
     * @return array{invoice: \Illuminate\Support\Collection, expense: \Illuminate\Support\Collection, advance: \Illuminate\Support\Collection}
     */
    private function targetModels(array $targets): array
    {
        $ids = collect($targets)->filter(fn ($target) => isset($target['id']))->groupBy('type')->map(fn ($group) => $group->pluck('id')->unique()->all());

        return [
            'invoice' => Invoice::with(['invoicesItems', 'projects', 'parentInvoice', 'deal'])->whereIn('id', $ids->get('invoice', []))->get()->keyBy('id'),
            'expense' => Expense::with(['project', 'user'])->whereIn('id', $ids->get('expense', []))->get()->keyBy('id'),
            'advance' => Advance::with(['project', 'user'])->whereIn('id', $ids->get('advance', []))->get()->keyBy('id'),
        ];
    }

    /**
     * Satu tagihan: nomor dan klien, rinciannya ($items baris item; 0 = ringkas),
     * lalu akibatnya bila dicatat.
     *
     * @param  array<string, float>  $running  sisa tagihan setelah mutasi sebelumnya di daftar yang sama
     * @return array<int, string>
     */
    private function targetBlock(array $target, array $models, array &$running, int $items, bool $compactRepeats = false): array
    {
        if (! isset($target['id'])) {
            $interest = $target['type'] === 'bank_interest';

            return [
                '➡️ <b>' . ($interest ? 'Bunga / jasa giro bank' : 'Biaya / pajak bank') . '</b>',
                '   ✍️ Dicatat sebagai transaksi ' . ($interest ? 'masuk ' : 'keluar ') . '<b>' . $this->rupiah($target['amount']) . '</b> supaya saldo ERP sama dengan bank',
            ];
        }

        if ($target['type'] === 'invoice') {
            $invoice = $models['invoice']->get($target['id']);

            if (! $invoice) {
                return ["➡️ Invoice #{$target['id']} tidak ditemukan (mungkin baru dihapus)"];
            }

            $key      = "invoice:{$invoice->id}";
            $first    = ! array_key_exists($key, $running);
            $before   = $running[$key] ?? InvoicePayment::remaining($invoice);
            $withheld = (float) ($target['withholding'] ?? 0);
            $after    = round($before - (float) $target['amount'] - $withheld, 2);
            $running[$key] = $after;

            $lines = ['➡️ <b>' . ($invoice->type === 'estimate' ? 'Proforma ' : 'Invoice ') . e($invoice->uuid) . '</b> · ' . e(Text::short(ClientLabel::invoice($invoice), 45))];

            // Invoice yang sama dengan mutasi sebelumnya di daftar (transfer dipecah): rinciannya tidak diulang.
            if ($items > 0 && ($first || ! $compactRepeats)) {
                foreach ($this->invoiceLines($invoice, $items) as $line) {
                    $lines[] = "   {$line}";
                }
            } elseif ($items > 0) {
                $lines[] = '   ↑ rincian sama dengan mutasi sebelumnya';
            }

            $lines[] = '   ✍️ Masuk <b>' . $this->rupiah($target['amount']) . '</b>'
                . ($withheld > 0 ? ' + potongan ' . e((string) ($target['withholding_label'] ?? 'PPh 23')) . ' ' . $this->rupiah($withheld) : '')
                . ' → ' . match (true) {
                    $after < -1 => '⚠️ lebih ' . $this->rupiah(-$after) . ' dari sisa tagihan',
                    $after <= 1 => '<b>LUNAS</b>',
                    default     => 'sisa jadi ' . $this->rupiah($after),
                }
                . ($invoice->type === 'estimate' && $first ? ' · proforma dijadikan invoice dulu' : '');

            return $lines;
        }

        $payee = $models[$target['type']]->get($target['id']) ?? null;

        if (! $payee) {
            return ['➡️ ' . ucfirst($target['type']) . " #{$target['id']} tidak ditemukan (mungkin baru dihapus)"];
        }

        $what      = $payee instanceof Expense ? $payee->keterangan_expense : $payee->keterangan_advance;
        $requested = \App\Services\BankStatement\MutationMatcher::payeeAmount($payee);
        $name      = $payee->user ? (trim("{$payee->user->firstname} {$payee->user->lastname}") ?: (string) $payee->user->username) : '-';

        return array_values(array_filter([
            '➡️ <b>' . ucfirst($target['type']) . " #{$payee->id}</b> · " . e($name),
            $items > 0 ? '   🗒 ' . e(Text::short((string) $what, 90)) . ($payee->category ? ' · 🏷 ' . e($payee->category) : '') : null,
            $items > 0 ? '   🗓 diajukan ' . $this->day((string) $payee->submission_date, year: true) . ($payee->project ? ' · 📁 ' . e(Text::short((string) $payee->project->nama_project, 40)) : '') : null,
            '   ✍️ Dibayarkan <b>' . $this->rupiah($target['amount']) . '</b>'
                . ($payee instanceof \App\Models\Advance && $payee->status !== 'Disetujui'
                    ? ' · bayar paralel: realisasi belum dibayar ' . $this->rupiah($requested)
                    : ((float) $target['amount'] < $requested - 1 ? ' dari pengajuan ' . $this->rupiah($requested) . ' (sebagian)' : ' = nominal pengajuan')),
        ]));
    }

    /**
     * Rincian invoice: tanggal dan jenis pembayaran, total/terbayar/sisa, item, project, PO.
     *
     * @return array<int, string>
     */
    private function invoiceLines(Invoice $invoice, int $maxItems): array
    {
        $items = $invoice->invoicesItems->map(fn ($item) => trim((string) ($item->item ?: $item->description))
            . ((float) $item->qty > 1 ? ' — ' . rtrim(rtrim(number_format((float) $item->qty, 2, ',', '.'), '0'), ',') . ' × ' . $this->rupiah($item->price) : ' — ' . $this->rupiah($item->total ?: $item->price)))
            ->filter(fn ($line) => ! str_starts_with($line, ' —'));

        $lines = [
            '🗓 ' . $this->day((string) $invoice->date, year: true) . ($invoice->due_date ? ' · jatuh tempo ' . $this->day((string) $invoice->due_date) : '') . ' · ' . e($this->paymentType($invoice)),
            '💰 ' . $this->billedText($invoice) . ' · terbayar ' . $this->rupiah($invoice->paid) . ' · sisa ' . $this->rupiah(InvoicePayment::remaining($invoice)),
        ];

        foreach ($items->take($maxItems) as $item) {
            $lines[] = '📦 ' . e(Text::short($item, 70));
        }

        if ($items->count() > $maxItems) {
            $lines[] = '📦 +' . ($items->count() - $maxItems) . ' item lain';
        }

        if ($project = $invoice->projects->first()) {
            $lines[] = '📁 Project: ' . e(Text::short((string) $project->nama_project, 50));
        }

        if (filled($invoice->po_number) && $invoice->po_number !== '-') {
            $lines[] = '🧾 PO: ' . e((string) $invoice->po_number);
        }

        return $lines;
    }

    /**
     * Yang ditagih invoice ini (Invoice::amountDue()): invoice DP menagih
     * persennya dari harga penuh, pelunasan menagih harga penuh dikurangi DP.
     */
    private function billedText(Invoice $invoice): string
    {
        $due = $invoice->amountDue();

        if (($percent = $invoice->dpPercentage()) !== null) {
            return "Ditagih {$this->rupiah($due)} (DP {$percent}% dari {$this->rupiah($invoice->grand_total)})";
        }

        if (($dp = $invoice->settledDp()) !== null) {
            return "Ditagih {$this->rupiah($due)} (pelunasan: {$this->rupiah($dp['full'])} − DP {$dp['percent']}% {$this->rupiah($dp['amount'])}"
                . ($invoice->parentInvoice ? ' ' . e($invoice->parentInvoice->uuid) : '') . ')';
        }

        return 'Total ' . $this->rupiah($invoice->grand_total);
    }

    /**
     * Keyakinan dan alasan utamanya (✓), serta keraguannya (✗) bila ada.
     *
     * @return array<int, string>
     */
    private function reasonLines(array $candidate): array
    {
        if (! collect($candidate['targets'])->contains(fn ($target) => isset($target['id']))) {
            return [];
        }

        $reasons = collect($candidate['reasons'] ?? []);
        $short   = fn (string $reason) => Text::short(trim(mb_substr($reason, 1)), 48);
        $good    = $reasons->filter(fn ($reason) => str_starts_with($reason, '✓'))->map($short)->take(3);
        $bad     = $reasons->filter(fn ($reason) => str_starts_with($reason, '✗'))->map($short)->take(2);

        return array_values(array_filter([
            isset($candidate['confidence']) ? "🎯 Yakin <b>{$candidate['confidence']}%</b>" . ($good->isNotEmpty() ? ': ' . e($good->implode(' · ')) : '') : null,
            $bad->isNotEmpty() ? '⚠️ ' . e($bad->implode(' · ')) : null,
        ]));
    }

    /**
     * Sisa tagihan setelah mutasi terpilih sebelum baris ini dicatat, untuk
     * layar Tinjau.
     *
     * @return array<string, float>
     */
    private function runningBefore(array $state, int $index): array
    {
        $targets = [];

        foreach ($this->selection($state) as $other => $candidate) {
            if ($other < $index) {
                array_push($targets, ...$state['results'][$other]['candidates'][$candidate]['targets']);
            }
        }

        $models  = $this->targetModels($targets);
        $running = [];

        foreach ($targets as $target) {
            if ($target['type'] === 'invoice' && ($invoice = $models['invoice']->get($target['id']))) {
                $key           = "invoice:{$invoice->id}";
                $running[$key] = ($running[$key] ?? InvoicePayment::remaining($invoice)) - (float) $target['amount'] - (float) ($target['withholding'] ?? 0);
            }
        }

        return $running;
    }

    /**
     * Hasil pencatatan satu target, dari data terbaru.
     */
    private function recordedLine(array $target, float $paidLater = 0.0): string
    {
        if (! isset($target['id'])) {
            return '➡️ ' . ($target['type'] === 'bank_interest' ? 'Bunga bank' : 'Biaya / pajak bank') . ' dicatat ' . $this->rupiah($target['amount']);
        }

        if ($target['type'] === 'invoice') {
            $invoice = Invoice::with('deal')->find($target['id']);

            if (! $invoice) {
                return "➡️ Invoice #{$target['id']}: dicatat";
            }

            // $paidLater: dibayar mutasi berikutnya di daftar yang sama.
            $remaining = InvoicePayment::remaining($invoice) + $paidLater;

            return '➡️ Invoice <b>' . e($invoice->uuid) . '</b>' . (isset($target['converted_from']) ? ' (dari proforma ' . e($target['converted_from']) . ')' : '')
                . ' · ' . e(Text::short(ClientLabel::invoice($invoice), 35))
                . ': masuk ' . $this->rupiah($target['amount'])
                . (($target['withholding'] ?? 0) > 0 ? ' + potongan ' . $this->rupiah($target['withholding']) : '')
                . ' → ' . ($remaining <= 1 || ($invoice->status === 'paid' && $paidLater <= 1) ? '<b>LUNAS</b>' : 'sisa ' . $this->rupiah($remaining));
        }

        return '➡️ ' . ucfirst($target['type']) . " #{$target['id']}: dibayarkan " . $this->rupiah($target['amount']);
    }

    /**
     * Panjang pesan seperti yang dihitung Telegram (unit UTF-16, tanpa tag HTML).
     *
     * @param  array<int, string>  $lines
     */
    private function length(array $lines): int
    {
        $text = html_entity_decode(strip_tags(implode("\n", $lines)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * Pengaman batas 4096: baris paling bawah dibuang bila pesan terlalu
     * panjang (setiap baris menutup tag HTML-nya sendiri).
     */
    private function fit(string $html): string
    {
        $lines = explode("\n", $html);

        if ($this->length($lines) <= 4000) {
            return $html;
        }

        while (count($lines) > 1 && $this->length([...$lines, '…']) > 4000) {
            array_pop($lines);
        }

        return implode("\n", [...$lines, '…']);
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

    /**
     * Berita dan nama pengirim tanpa kode bank; keterangan asli bila tidak terurai.
     */
    private function brief(array $row, int $limit): string
    {
        $bca  = BcaDescription::parse($row['description']);
        $text = trim($bca['remark'] . ($bca['sender'] !== '' ? ' · ' . $bca['sender'] : ''), ' ·');

        return Text::short($text !== '' ? $text : $row['description'], $limit);
    }

    /**
     * Hasil urai keterangan BCA untuk layar Tinjau.
     *
     * @return array<int, string>
     */
    private function parsedLines(array $row): array
    {
        $bca = BcaDescription::parse($row['description']);

        if ($bca['sender'] === '') {
            return [];
        }

        $purpose = ['dp' => 'DP / uang muka', 'settlement' => 'pelunasan', 'refund' => 'pengembalian dana', 'wrong' => 'salah transfer'][$bca['purpose']] ?? null;

        return array_values(array_filter([
            ($row['direction'] === 'in' ? 'Pengirim: ' : 'Penerima: ') . '<b>' . e($bca['sender']) . '</b>' . ($bca['remark'] !== '' ? ' · berita: ' . e($bca['remark']) : ''),
            $purpose ? "Tujuan menurut berita: {$purpose}" : null,
        ]));
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
        $html = $this->fit($html);

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

    /**
     * Ada file mutasi yang lebih baru di chat ini (mis. foto album yang lain):
     * hasil file ini tidak disimpan, supaya tombol di ringkasannya tidak
     * mencatat data file lain.
     */
    private function superseded(int|string $chatId, int $since, ?string $name): bool
    {
        if ($since >= (int) Cache::get($this->latestKey($chatId), 0)) {
            return false;
        }

        $this->telegram->sendMessage($chatId, '⏭️ ' . ($name ? '<b>' . e($name) . '</b> ' : 'File ini ') . 'dilewati karena ada file mutasi yang lebih baru. Kirim satu file mutasi per kali.');

        return true;
    }

    private function albumDir(int|string $chatId, string $group): string
    {
        return storage_path('app/mutasi-album/' . preg_replace('/\W/', '', (string) $chatId) . '-' . md5($group));
    }

    private function latestKey(int|string $chatId): string
    {
        return "telegram:finance-mutasi-latest:{$chatId}";
    }

    private function key(int|string $chatId): string
    {
        return "telegram:finance-mutasi:{$chatId}";
    }
}
