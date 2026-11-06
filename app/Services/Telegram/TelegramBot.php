<?php

namespace App\Services\Telegram;

use App\Enums\ExpenseStatus;
use App\Enums\FinanceCategory;
use App\Helpers\ClientLabel;
use App\Models\Advance;
use App\Models\Expense;
use App\Models\ItemAdvance;
use App\Models\Project;
use App\Models\User;
use App\Services\AutoApproval;
use App\Services\GeminiService;
use App\Services\RealisasiService;
use App\Services\ReceiptScanner;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Menangani update webhook Telegram: menghubungkan akun, menyusun draft
 * expense/advance lewat chat, lalu membuat record ERP setelah user menekan Kirim.
 *
 * Record dibuat dengan status Pending seperti dari form, jadi ExpenseObserver
 * dan AdvanceObserver tetap memberi tahu Finance & Director.
 */
class TelegramBot
{
    private const PROJECTS_PER_PAGE = 8;

    private const PROJECT_CHOICES = 50;

    // Sama dengan batas dan jenis file upload invoice di ERP.
    private const MAX_ATTACHMENT_BYTES = 5 * 1024 * 1024;

    // Salinan sementara struk yang sudah diunduh, di disk lokal (bukan public).
    private const RECEIPT_CACHE = 'telegram-struk';

    // telegram_receipts.scope untuk struk expense/advance; realisasi memakai "realisasi:{advance_id}".
    private const RECEIPT_SCOPE = 'pengajuan';

    // Advance per halaman daftar pilihan: 8 kartu lengkap masih muat dalam satu pesan Telegram.
    private const ADVANCES_PER_PAGE = 8;

    private const ATTACHMENT_TYPES = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];

    // 'model' dipakai untuk cek izin create; realisasi tidak membuat record baru
    // milik user lain, cukup advance-nya milik pengirim sendiri.
    private const TYPES = [
        'expense'   => ['label' => 'Expense', 'icon' => '💰', 'model' => Expense::class],
        'advance'   => ['label' => 'Advance', 'icon' => '💸', 'model' => Advance::class],
        'realisasi' => ['label' => 'Realisasi', 'icon' => '🧾', 'model' => null],
    ];

    public function __construct(
        private TelegramClient $telegram,
        private SubmissionParser $parser,
        private TelegramLinker $linker,
        private ProjectMatcher $matcher,
        private GeminiService $gemini,
        private OpenAdvances $advances,
        private RealisasiService $realisasi,
        private ReceiptScanner $scanner,
    ) {}

    public function handle(array $update): void
    {
        // Telegram mengirim ulang update bila webhook lambat atau gagal; tanpa
        // ini satu pesan bisa diproses dua kali.
        if (isset($update['update_id']) && ! Cache::add("telegram:update:{$update['update_id']}", true, now()->addDay())) {
            return;
        }

        $chat = data_get($update, 'message.chat') ?? data_get($update, 'callback_query.message.chat');

        // Bot hanya melayani chat pribadi: data pengajuan tidak boleh tampil di grup.
        if (! isset($chat['id']) || ($chat['type'] ?? null) !== 'private') {
            return;
        }

        // Update dari satu chat diproses berurutan, supaya dua tekanan tombol
        // Kirim yang beruntun tidak membuat dua pengajuan.
        // Batasnya longgar karena membaca album struk dengan Gemini bisa makan
        // beberapa detik per struk; foto album berikutnya menunggu giliran.
        Cache::lock("telegram:chat:{$chat['id']}", 120)->block(60, function () use ($update) {
            if (isset($update['callback_query'])) {
                $this->handleCallback($update['callback_query']);
            } elseif (isset($update['message'])) {
                $this->handleMessage($update['message']);
            }
        });
    }

    // =================================================================
    // Pesan teks
    // =================================================================

    private function handleMessage(array $message): void
    {
        $chatId = $message['chat']['id'];
        $text   = trim($message['text'] ?? $message['caption'] ?? '');

        [$command, $argument] = $this->splitCommand($text);

        if ($command === 'start') {
            $this->start($chatId, $argument);

            return;
        }

        $user = $this->userFor($chatId);

        if (! $user) {
            $this->sendNotLinked($chatId);

            return;
        }

        $file = $this->incomingFile($message);

        if (isset($file['error'])) {
            $this->telegram->sendMessage($chatId, $file['error']);

            return;
        }

        if ($file) {
            $this->receiveAttachment($chatId, $user, $file, $text, $command, $argument);

            return;
        }

        if ($text === '') {
            return;
        }

        match ($command) {
            'expense', 'advance', 'realisasi' => $this->startDraft($chatId, $user, $argument, $command, $this->draftAwaitingDetails($chatId)),
            'status'             => $this->sendStatus($chatId, $user),
            'listadvance', 'daftaradvance' => $this->sendAdvanceList($chatId, $user, query: $argument),
            'batal'              => $this->cancel($chatId),
            null                 => $this->handleText($chatId, $user, $text),
            default              => $this->sendHelp($chatId),
        };
    }

    private function handleText(int|string $chatId, User $user, string $text): void
    {
        // Struk sudah dikirim lebih dulu; pesan ini keterangannya.
        if ($draft = $this->draftAwaitingDetails($chatId)) {
            $this->startDraft($chatId, $user, $text, null, $draft);

            return;
        }

        $draft = Draft::load($chatId);

        if ($draft?->awaiting) {
            $this->answerPrompt($chatId, $user, $draft, $text);

            return;
        }

        // Pesan baru tanpa draft yang sedang ditanyakan dianggap pengajuan baru.
        $this->startDraft($chatId, $user, $text, null);
    }

    private function startDraft(int|string $chatId, User $user, string $text, ?string $type, ?Draft $draft = null): void
    {
        $draft ??= Draft::start($type);
        $draft->type = $type ?? $draft->type;
        // Perintah /expense, /advance, /realisasi adalah pilihan user sendiri.
        $draft->typeConfirmed = $draft->typeConfirmed || $type !== null;

        [$text, $reference, $unknownId] = $this->advanceReference($user, $text);

        if ($text !== '') {
            $this->telegram->typing($chatId);
            $draft->fill($this->parser->parse($text, $this->projectChoices(), $this->receiptForAi($draft)));
            // Perintah lebih kuat dari tebakan AI.
            $draft->type = $type ?? $draft->type;
        }

        // Struk/invoice yang diunggah hampir selalu laporan pemakaian advance.
        // AI cenderung menebak expense (uangnya sudah keluar), jadi tanpa jenis
        // yang ditulis user, realisasi didahulukan selama ada advance terbuka
        // untuk project-nya. Expense tetap satu tombol di draft realisasi.
        if (! $draft->typeConfirmed && $draft->type !== 'advance' && $draft->receiptCount() > 0 && $this->realisasiLikely($user, $draft)) {
            $draft->type = 'realisasi';
        }

        // "realisasi #12": ID dari /listadvance langsung memilih advance itu.
        // Jenis lain yang ditulis user sendiri ("expense ... #12") tetap dipakai.
        if ($reference && (! $draft->typeConfirmed || $draft->type === 'realisasi')) {
            $draft->type              = 'realisasi';
            $draft->typeConfirmed     = true;
            $draft->advanceId         = $reference->id;
            $draft->advanceCandidates = [];
        } elseif ($unknownId && $draft->type === 'realisasi') {
            $this->telegram->sendMessage($chatId, "⚠️ Advance #{$unknownId} tidak ada di daftar advance terbuka" . (OpenAdvances::seesAll($user) ? '' : ' milikmu') . '. Pilih dari daftar, atau cek lewat /listadvance.');
        }

        $this->proceed($chatId, $user, $draft);
    }

    /**
     * "#12" di pesan: ID advance terbuka (dari /listadvance atau daftar pilihan).
     * Dibuang dari teks supaya tidak terbaca sebagai nominal atau keterangan.
     * ID yang bukan advance terbuka user ini dibiarkan di teks.
     *
     * @return array{0: string, 1: ?Advance, 2: ?int} teks, advance-nya, atau ID yang tidak ditemukan
     */
    private function advanceReference(User $user, string $text): array
    {
        if (! preg_match('/(?<![\w#])#(\d{1,9})\b/u', $text, $match)) {
            return [$text, null, null];
        }

        $advance = $this->advances->find($user, (int) $match[1]);

        return $advance
            ? [trim(preg_replace('/\s+/u', ' ', str_replace($match[0], ' ', $text))), $advance, null]
            : [$text, null, (int) $match[1]];
    }

    /**
     * Ada advance terbuka yang bisa jadi sumber dana struk ini: untuk project
     * yang disebut, atau advance mana pun bila project-nya tidak disebut.
     */
    private function realisasiLikely(User $user, Draft $draft): bool
    {
        $open = $this->advances->realizableBy($user);

        return $draft->projectId ? $open->contains('project_id', $draft->projectId) : $open->isNotEmpty();
    }

    private function draftAwaitingDetails(int|string $chatId): ?Draft
    {
        $draft = Draft::load($chatId);

        // 'target': struk sudah dibaca, tapi user memilih menulis keterangannya.
        if (! in_array($draft?->awaiting, ['details', 'target'], true)) {
            return null;
        }

        $draft->awaiting = null;

        return $draft;
    }

    // =================================================================
    // Lampiran struk
    // =================================================================

    /**
     * Foto atau dokumen di pesan sebagai lampiran draft, ['error' => pesan]
     * bila tidak bisa dipakai, atau null bila pesan tidak membawa file.
     */
    private function incomingFile(array $message): ?array
    {
        if (! empty($message['photo'])) {
            // Telegram mengirim beberapa ukuran; yang terakhir paling besar.
            $source = end($message['photo']);
            $mime   = 'image/jpeg';
        } elseif (! empty($message['document'])) {
            $source = $message['document'];
            $mime   = $source['mime_type'] ?? '';
        } else {
            return null;
        }

        // file_unique_id sama untuk file yang persis sama walau dikirim ulang
        // atau di-forward: dipakai untuk menolak struk ganda.
        $file = ['file_id' => $source['file_id'], 'unique' => $source['file_unique_id'] ?? null, 'mime' => $mime, 'size' => $source['file_size'] ?? 0];

        if (! isset(self::ATTACHMENT_TYPES[$file['mime']])) {
            return ['error' => 'Format file ini belum didukung. Kirim sebagai <b>foto</b>, atau berkas JPG, PNG, WebP, atau PDF.'];
        }

        if ($file['size'] > self::MAX_ATTACHMENT_BYTES) {
            return ['error' => 'Ukuran file lebih dari 5 MB. Kirim sebagai <b>foto</b> supaya dikecilkan otomatis oleh Telegram.'];
        }

        return [
            'file_id'    => $file['file_id'],
            'unique'     => $file['unique'],
            'mime'       => $file['mime'],
            'ext'        => self::ATTACHMENT_TYPES[$file['mime']],
            'group'      => $message['media_group_id'] ?? null,
            'message_id' => $message['message_id'] ?? null,
        ];
    }

    private function receiveAttachment(int|string $chatId, User $user, array $file, string $text, ?string $command, string $argument): void
    {
        $draft = Draft::load($chatId);

        // Struk yang persis sama (dikirim ulang atau di-forward) tidak boleh
        // masuk dua kali, baik di draft ini maupun di pengajuan sebelumnya.
        if ($draft?->hasReceipt($file['unique'])) {
            $this->telegram->sendMessage($chatId, '⚠️ Struk ini sudah ada di draft. Tidak ditambahkan lagi.', replyTo: $file['message_id']);

            return;
        }

        if ($previous = $this->submittedReceipt($file['unique'])) {
            $this->telegram->sendMessage($chatId, "⚠️ Struk ini sudah pernah dikirim ({$previous}). Tidak ditambahkan supaya tidak tercatat dua kali.", replyTo: $file['message_id']);

            return;
        }

        $type      = array_key_exists((string) $command, self::TYPES) ? $command : null;
        $sameAlbum = $draft && $file['group'] && $draft->albumGroup() === $file['group'];

        // Album (Telegram mengirimnya sebagai beberapa pesan terpisah), atau
        // struk berikutnya selagi bot menunggu keterangan: masuk draft yang sama.
        if ($draft && ($sameAlbum || $draft->awaiting === 'details')) {
            $draft->addReceipt($file, $sameAlbum);

            if ($text !== '') {
                // Caption bisa menempel di foto mana pun dalam album.
                $draft->awaiting = null;
                $this->startDraft($chatId, $user, $type ? $argument : $text, $type, $draft);

                return;
            }

            $this->afterReceiptAdded($chatId, $user, $draft);

            return;
        }

        // Foto dengan caption: caption-nya keterangan pengajuan baru.
        if ($text !== '') {
            $draft = Draft::start($type);
            $draft->addReceipt($file, sameAlbum: false);
            $this->startDraft($chatId, $user, $type ? $argument : $text, $type, $draft);

            return;
        }

        // Foto susulan untuk draft yang sedang disusun: jadi struk berikutnya.
        if ($draft && ! $draft->isEmpty()) {
            $draft->addReceipt($file, sameAlbum: false);
            $this->telegram->sendMessage(
                $chatId,
                $draft->receiptCount() > 1 ? "📎 Struk ke-{$draft->receiptCount()} ditambahkan." : '📎 Struk dilampirkan ke draft.'
            );
            $this->afterReceiptAdded($chatId, $user, $draft);

            return;
        }

        // Struk lebih dulu: dibaca Gemini, lalu user tinggal memilih masuk ke
        // mana. Tanpa AI (atau struk tak terbaca) bot menunggu keterangannya.
        $draft = Draft::start();
        $draft->addReceipt($file, sameAlbum: false);

        if ($this->readFirstReceipt($chatId, $draft)) {
            $draft->awaiting = 'target';
            $this->showDestinations($chatId, $user, $draft);

            return;
        }

        $draft->awaiting = 'details';
        $this->showDetailsPrompt($chatId, $draft);
    }

    /**
     * Isi draft dari struk pertama yang dibaca Gemini. Hasilnya juga disimpan
     * di struk itu sendiri, supaya tidak dibaca ulang saat album bertambah.
     */
    private function readFirstReceipt(int|string $chatId, Draft $draft): bool
    {
        if (! $this->gemini->isConfigured() || ! $draft->attachment) {
            return false;
        }

        $this->telegram->typing($chatId);

        $data = $this->receiptData($draft->attachment);
        $read = $data === null ? null : $this->parser->readReceipt(['mime' => $draft->attachment['mime'], 'data' => $data]);

        if (! $read) {
            return false;
        }

        $draft->attachment['amount'] = $read['amount'];
        $draft->attachment['name']   = $read['description'] ?? $read['name'];
        $draft->attachment['items']  = $read['items'];
        $draft->attachment['shop']   = $read['name'];
        $draft->attachment['ai']     = true;

        $draft->amount      = $read['amount'];
        $draft->description = $read['description'] ?? $read['name'];
        $draft->date        = $read['date'] ?? $draft->date;
        $draft->category    = $read['category'];

        return true;
    }

    /**
     * Rincian struk yang terbaca, lalu tombol tujuan: realisasi salah satu
     * advance terbuka, expense baru, atau advance baru.
     */
    private function showDestinations(int|string $chatId, User $user, Draft $draft): void
    {
        $open = $this->advances->realizableBy($user);

        // Struk/invoice yang diunggah hampir selalu realisasi: satu-satunya
        // advance terbuka langsung dipakai. Draft realisasinya tetap punya
        // tombol "Jadikan Expense" dan "Advance lain", dan keterangan yang
        // diketik sesudahnya masih masuk ke struk ini (awaiting tetap 'target').
        if ($open->count() === 1 && ! $this->advances->blockedReason($open->first())) {
            $draft->type      = 'realisasi';
            $draft->advanceId = $open->first()->id;
            $this->proceed($chatId, $user, $draft, $draft->panelMessageId);

            return;
        }

        if ($draft->isMulti()) {
            $count = $draft->receiptCount();
            $text  = "📄 <b>{$count} struk terbaca</b>\n\n" . $this->receiptLines($draft, withItems: true)
                . "\nTotal: <b>" . $this->rupiah($draft->total()) . '</b>';
        } else {
            $text = '📄 <b>Isi struk</b>'
                . (! empty($draft->attachment['shop']) ? ' · ' . e($draft->attachment['shop']) : '')
                . ($draft->date ? ' · ' . Carbon::parse($draft->date)->locale('id')->translatedFormat('j M Y') : '') . "\n"
                . e((string) $draft->description) . "\n"
                . $this->itemLines($draft->attachment['items'] ?? [], 12)
                . 'Total: <b>' . $this->rupiah($draft->amount) . '</b>';
        }

        // Tombol hanya muat nama pendek; nama lengkap dan penandanya di pesan, dengan ID yang sama.
        $shown = $open->take(4)->values();

        if ($shown->isNotEmpty()) {
            $text .= "\n\n🧾 <b>Realisasi advance yang mana?</b>" . ($open->count() > 4 ? ' (4 terbaru dari ' . $open->count() . ')' : '')
                . "\n" . $this->advanceList($shown, $user);
        }

        $rows = $shown
            ->map(fn (Advance $advance) => [$this->button(
                '🧾 ' . $this->advanceButtonLabel($advance, $user),
                $draft,
                'to',
                "realisasi-{$advance->id}"
            )])
            ->all();

        if ($open->count() > 4) {
            $rows[] = [$this->button('🧾 Advance lain (' . ($open->count() - 4) . ' lagi)…', $draft, 'to', 'realisasi')];
        }

        $rows[] = [$this->button(($open->isNotEmpty() ? '💰 Bukan realisasi — Expense baru' : '💰 Expense baru — sudah bayar sendiri'), $draft, 'to', 'expense')];
        $rows[] = [$this->button('💸 Advance baru — minta dana', $draft, 'to', 'advance')];
        $rows[] = [$this->button('❌ Batal', $draft, 'cancel')];

        $this->showPanel(
            $chatId,
            $draft->panelMessageId,
            $draft,
            $text . "\n\n" . ($open->isNotEmpty()
                ? 'Pilih advance yang dananya dipakai untuk struk ini. Bila dibayar sendiri, pilih <b>Expense baru</b>.'
                : 'Masukkan ke mana?')
                . "\nAtau tulis keterangannya sendiri, misalnya <i>expense grab ke klien nestory</i>.",
            $rows
        );
    }

    /**
     * "  • Kabel HDMI — 2 × Rp125.000", paling banyak $limit baris.
     *
     * @param  array<int, array{name: string, qty: int, price: int}>  $items
     */
    private function itemLines(array $items, int $limit, string $indent = '  '): string
    {
        $lines = collect($items)->take($limit)
            ->map(fn (array $item) => $indent . '• ' . e($item['name']) . ' — '
                . ($item['qty'] > 1 ? $item['qty'] . ' × ' . $this->rupiah($item['price']) : $this->signedRupiah($item['price'])))
            ->implode("\n");

        if (count($items) > $limit) {
            $lines .= "\n{$indent}… dan " . (count($items) - $limit) . ' item lain';
        }

        return $lines !== '' ? $lines . "\n" : '';
    }

    private function signedRupiah(int|float $amount): string
    {
        return ($amount < 0 ? '−' : '') . $this->rupiah(abs($amount));
    }

    /**
     * "Kabel HDMI 2 × Rp125.000; Lakban Rp15.000": rincian sebagai teks, untuk
     * item realisasi (satu struk = satu item dengan struknya sendiri).
     *
     * @param  array<int, array{name: string, qty: int, price: int}>  $items
     */
    private function itemsText(array $items): ?string
    {
        if (! $items) {
            return null;
        }

        return Str::limit(collect($items)
            ->map(fn (array $item) => $item['name'] . ' ' . ($item['qty'] > 1 ? $item['qty'] . ' × ' : '') . strip_tags($this->signedRupiah($item['price'])))
            ->implode('; '), 1000);
    }

    /**
     * Setelah struk bertambah: perbarui pesan yang sedang aktif tanpa menimpa
     * pertanyaan yang belum dijawab.
     */
    private function afterReceiptAdded(int|string $chatId, User $user, Draft $draft): void
    {
        if ($draft->awaiting === 'details') {
            $this->showDetailsPrompt($chatId, $draft);

            return;
        }

        // Struk album berikutnya saat bot menunggu tujuan: dibaca juga, lalu
        // rinciannya diperbarui di pesan yang sama.
        if ($draft->awaiting === 'target') {
            if ($this->fillReceiptAmounts($chatId, $draft)) {
                $this->showDestinations($chatId, $user, $draft);
            }

            return;
        }

        // Pertanyaan nominal tunggal tidak berlaku lagi begitu struknya lebih
        // dari satu: nominal ditanyakan per struk.
        if ($draft->awaiting === 'amount' && $draft->isMulti()) {
            $draft->awaiting = null;
        }

        // Pertanyaan lain (nominal struk, project, …) dijawab dulu; struk baru
        // ikut diproses sesudahnya.
        if ($draft->awaiting) {
            $draft->save($chatId);

            return;
        }

        $this->proceed($chatId, $user, $draft, $draft->panelMessageId);
    }

    private function showDetailsPrompt(int|string $chatId, Draft $draft): void
    {
        $count = $draft->receiptCount();

        $this->showPanel(
            $chatId,
            $draft->panelMessageId,
            $draft,
            ($count > 1 ? "📎 {$count} struk diterima." : '📎 Struk diterima.')
                . "\n\nSekarang tulis keterangannya, misalnya:\n• <i>expense 150rb grab ke kantor klien nestory</i>\n• <i>realisasi sewa alat shooting nestory</i>",
            [[$this->button('❌ Batal', $draft, 'cancel')]]
        );
    }

    /**
     * Isi nominal tiap struk yang belum ada: dibaca Gemini bila aktif, selain
     * itu ditanyakan satu per satu sambil membalas foto struknya. False bila
     * sedang menunggu jawaban user.
     */
    private function fillReceiptAmounts(int|string $chatId, Draft $draft): bool
    {
        while (($index = $draft->nextReceiptWithoutAmount()) !== null) {
            $receipt = $draft->receipts[$index];

            if (! $receipt['ai'] && $this->gemini->isConfigured()) {
                $draft->receipts[$index]['ai'] = true;

                $data = $this->receiptData($receipt);
                $read = $data === null ? null : $this->parser->readReceipt(['mime' => $receipt['mime'], 'data' => $data]);

                if ($read) {
                    $draft->receipts[$index]['amount'] = $read['amount'];
                    $draft->receipts[$index]['name'] ??= $read['description'] ?? $read['name'];
                    $draft->receipts[$index]['items']  = $read['items'];

                    continue;
                }
            }

            $this->askReceipt($chatId, $draft, $index);

            return false;
        }

        return true;
    }

    private function askReceipt(int|string $chatId, Draft $draft, int $index): void
    {
        $draft->awaiting = "receipt:{$index}";

        // Ringkasan lama tidak boleh bisa dikirim selagi nominal struk ditanyakan.
        if ($draft->panelMessageId) {
            $this->telegram->removeButtons($chatId, $draft->panelMessageId);
            $draft->panelMessageId = null;
        }

        $draft->save($chatId);

        $this->telegram->sendMessage(
            $chatId,
            'Struk ' . ($index + 1) . ' ☝️ — berapa nominalnya? Boleh ditambah nama barangnya, misalnya <i>120rb sewa lighting</i>.',
            [[$this->button('🗑 Hapus struk ini', $draft, 'rdel', $index), $this->button('❌ Batal', $draft, 'cancel')]],
            replyTo: $draft->receipts[$index]['message_id'] ?? null
        );
    }

    /**
     * Unggah semua struk draft ke disk public. Bila satu gagal, yang sudah
     * terunggah dihapus lagi supaya tidak ada pengajuan setengah lampiran.
     *
     * @return array{files: array<int, array>, failed: ?int}
     */
    private function storeReceipts(Draft $draft, string $folder): array
    {
        $receipts = $draft->isMulti() ? $draft->receipts : array_filter([$draft->attachment]);
        $files    = [];

        foreach (array_values($receipts) as $index => $receipt) {
            $stored = $this->storeAttachment($receipt, $folder);

            if ($stored === null) {
                $this->deleteStored($files);

                return ['files' => [], 'failed' => $index + 1];
            }

            $files[] = $stored;
        }

        foreach ($receipts as $receipt) {
            Storage::disk('local')->delete($this->receiptCachePath($receipt));
        }

        return ['files' => $files, 'failed' => null];
    }

    /**
     * Isi file struk. Struk yang sama dibutuhkan beberapa kali: dibaca Gemini,
     * dibaca lagi per struk saat album bertambah, lalu disimpan saat Kirim.
     * Mengunduhnya ulang dari Telegram tiap kali (dua panggilan API per struk)
     * membuat bot lambat, jadi salinan pertama disimpan sementara.
     */
    private function receiptData(array $receipt): ?string
    {
        $disk = Storage::disk('local');
        $path = $this->receiptCachePath($receipt);

        if ($disk->exists($path)) {
            return $disk->get($path);
        }

        $data = $this->telegram->download($receipt['file_id'], self::MAX_ATTACHMENT_BYTES);

        if ($data !== null) {
            // Sisa draft yang dibatalkan atau kedaluwarsa.
            foreach ($disk->files(self::RECEIPT_CACHE) as $old) {
                if ($disk->lastModified($old) < now()->subDay()->getTimestamp()) {
                    $disk->delete($old);
                }
            }

            $disk->put($path, $data);
        }

        return $data;
    }

    private function receiptCachePath(array $receipt): string
    {
        return self::RECEIPT_CACHE . '/' . sha1($receipt['file_id']);
    }

    private function deleteStored(array $files): void
    {
        ReceiptScanner::delete(array_column($files, 'path'));
    }

    private function receiptFailedMessage(int|string $chatId, int $number, int $total): void
    {
        $this->telegram->sendMessage(
            $chatId,
            ($total > 1 ? "Struk ke-{$number} gagal" : 'Struk gagal') . ' diambil dari Telegram. Kirim ulang fotonya, lalu tekan <b>Kirim</b> lagi.'
        );
    }

    /**
     * Catat struk yang sudah masuk ERP, supaya file yang sama tidak bisa
     * diajukan lagi. Dipanggil di dalam transaksi pengajuannya.
     */
    private function rememberReceipts(Draft $draft, User $user, string $reference): void
    {
        $receipts = $draft->isMulti() ? $draft->receipts : array_filter([$draft->attachment]);

        foreach ($receipts as $receipt) {
            if (! $receipt['unique']) {
                continue;
            }

            DB::table('telegram_receipts')->insert([
                'file_unique_id' => $receipt['unique'],
                'scope'          => $this->receiptScope($draft),
                'user_id'        => $user->id,
                'reference'      => $reference,
                'created_at'     => now(),
            ]);
        }
    }

    /**
     * Struk expense/advance tidak boleh dipakai lagi di mana pun. Struk
     * realisasi boleh dipakai lagi untuk realisasi advance lain (satu struk
     * untuk beberapa advance), tapi tidak untuk advance yang sama.
     */
    private function receiptScope(Draft $draft): string
    {
        return $draft->type === 'realisasi' ? "realisasi:{$draft->advanceId}" : self::RECEIPT_SCOPE;
    }

    /**
     * Keterangan singkat pengajuan yang sudah memakai struk ini, atau null bila
     * struk ini masih boleh dipakai untuk $scope. Tanpa $scope (struk baru
     * diterima, jenis pengajuannya belum diketahui) hanya struk expense/advance
     * yang ditolak.
     */
    private function submittedReceipt(?string $unique, ?string $scope = null): ?string
    {
        if (! $unique) {
            return null;
        }

        $row = DB::table('telegram_receipts')
            ->where('file_unique_id', $unique)
            ->when($scope !== self::RECEIPT_SCOPE, fn ($query) => $query->whereIn('scope', array_filter([self::RECEIPT_SCOPE, $scope])))
            ->orderBy('id')
            ->first();

        return $row
            ? $row->reference . ', ' . Carbon::parse($row->created_at)->locale('id')->translatedFormat('j M Y')
            : null;
    }

    private function draftHasSubmittedReceipt(Draft $draft): ?string
    {
        $receipts = $draft->isMulti() ? $draft->receipts : array_filter([$draft->attachment]);

        foreach ($receipts as $receipt) {
            if ($previous = $this->submittedReceipt($receipt['unique'] ?? null, $this->receiptScope($draft))) {
                return $previous;
            }
        }

        return null;
    }

    /**
     * Isi struk untuk dibaca Gemini bersama keterangannya. Hanya diunduh bila
     * Gemini aktif; tanpa AI struk cukup dilampirkan.
     */
    private function receiptForAi(Draft $draft): ?array
    {
        if (! $draft->attachment || ! $this->gemini->isConfigured()) {
            return null;
        }

        $data = $this->receiptData($draft->attachment);

        return $data === null ? null : ['mime' => $draft->attachment['mime'], 'data' => $data];
    }

    /**
     * Unduh struk dari Telegram ke disk public ERP. Null bila gagal diunduh
     * atau isinya bukan gambar/PDF.
     *
     * @return array{path: string, size: int, mime: string, ext: string}|null
     */
    private function storeAttachment(array $receipt, string $folder): ?array
    {
        $data = $this->receiptData($receipt);

        if ($data === null) {
            return null;
        }

        // File ini bisa dibuka lewat link publik /storage, jadi jenisnya
        // diperiksa dari isinya, bukan dari label yang dikirim Telegram.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($data);

        if (! isset(self::ATTACHMENT_TYPES[$mime])) {
            Log::warning('Lampiran Telegram ditolak: isi file tidak cocok', ['mime' => $mime]);

            return null;
        }

        // Foto struk dirapikan seperti hasil scan; aslinya ikut disimpan.
        $stored = $this->scanner->store($data, $mime, $folder);

        return ['path' => $stored['path'], 'size' => $stored['size'], 'mime' => $stored['mime'], 'ext' => pathinfo($stored['path'], PATHINFO_EXTENSION)];
    }

    private function answerPrompt(int|string $chatId, User $user, Draft $draft, string $text): void
    {
        // Jawaban nominal untuk satu struk tertentu: "120rb sewa lighting".
        if (str_starts_with((string) $draft->awaiting, 'receipt:')) {
            $index  = (int) Str::after($draft->awaiting, 'receipt:');
            $amount = AmountParser::parse($text);

            if (! $amount || ! isset($draft->receipts[$index])) {
                $this->telegram->sendMessage(
                    $chatId,
                    'Nominalnya belum terbaca. Tulis misalnya <i>120rb</i> atau <i>120000</i>.',
                    replyTo: $draft->receipts[$index]['message_id'] ?? null
                );

                return;
            }

            $name = trim(preg_replace('/\s+/u', ' ', AmountParser::strip($text)), " \t\n\r\0\x0B,.-:");

            $draft->receipts[$index]['amount'] = $amount;
            $draft->receipts[$index]['name']   = mb_strlen($name) >= 3 ? Str::ucfirst(Str::limit($name, 100, '')) : $draft->receipts[$index]['name'];
            // Nominal diketik ulang: rincian dari Gemini tidak lagi sesuai.
            $draft->receipts[$index]['items']  = [];
            $draft->awaiting = null;

            // Struk dikirim tanpa keterangan dan tujuannya belum dipilih.
            if (! $draft->type && $draft->nextReceiptWithoutAmount() === null && collect($draft->receipts)->contains('ai', true)) {
                $draft->awaiting = 'target';
                $this->showDestinations($chatId, $user, $draft);

                return;
            }

            $this->proceed($chatId, $user, $draft);

            return;
        }

        switch ($draft->awaiting) {
            case 'amount':
                $amount = AmountParser::parse($text);

                if (! $amount) {
                    $this->telegram->sendMessage($chatId, 'Nominalnya belum terbaca. Tulis angkanya saja, misalnya <i>150000</i> atau <i>150rb</i>.');

                    return;
                }

                $draft->amount = $amount;
                break;

            case 'description':
                $draft->description = Str::limit($text, 500, '');
                break;

            case 'date':
                $date = DateParser::parse($text);

                if (! $date || $date > today()->toDateString()) {
                    $this->telegram->sendMessage(
                        $chatId,
                        $date
                            ? 'Tanggal pengajuan tidak boleh setelah hari ini. Tulis tanggal transaksinya.'
                            : 'Tanggalnya belum terbaca. Tulis misalnya <i>3/11</i>, <i>3 Nov 2026</i>, atau <i>kemarin</i>.'
                    );

                    return;
                }

                $draft->date = $date;
                break;

            case 'project':
                $this->showProjectSearch($chatId, $user, $draft, $text);

                return;

            case 'advance':
                $this->showAdvanceChoices($chatId, null, $draft, $user, $this->advances->search($user, $text), mode: 'search');

                return;
        }

        $draft->awaiting = null;
        $this->proceed($chatId, $user, $draft);
    }

    // =================================================================
    // Tombol
    // =================================================================

    private function handleCallback(array $callback): void
    {
        $chatId    = $callback['message']['chat']['id'];
        $messageId = $callback['message']['message_id'];

        [$draftId, $action, $value] = array_pad(explode(':', (string) ($callback['data'] ?? ''), 3), 3, '');

        // Halaman /listadvance: tidak terikat draft.
        if ($draftId === 'menu') {
            $this->telegram->answerCallback($callback['id']);

            if (($user = $this->userFor($chatId)) && $action === 'advances') {
                $this->sendAdvanceList($chatId, $user, (int) $value, messageId: $messageId);
            }

            return;
        }

        $user  = $this->userFor($chatId);
        $draft = Draft::load($chatId);

        if (! $user || ! $draft || $draft->id !== $draftId) {
            $this->telegram->answerCallback($callback['id'], 'Draft ini sudah tidak berlaku.');
            $this->telegram->removeButtons($chatId, $messageId);

            return;
        }

        $this->telegram->answerCallback($callback['id']);

        switch ($action) {
            case 'type':
                $draft->type          = array_key_exists($value, self::TYPES) ? $value : $draft->type;
                $draft->typeConfirmed = true;
                $draft->typePicked    = true;
                break;

            case 'adv':
                $draft->advanceId         = $this->advances->find($user, (int) $value)?->id;
                $draft->advanceCandidates = [];
                // Memilih advance berarti realisasi, juga bila jenisnya tadi masih tebakan.
                $draft->type          = 'realisasi';
                $draft->typeConfirmed = true;
                // Pencarian advance selesai begitu salah satu dipilih.
                $draft->awaiting = $draft->awaiting === 'advance' ? null : $draft->awaiting;
                break;

            // Tujuan struk yang sudah dibaca: "realisasi-<id advance>", "realisasi", "expense", "advance".
            case 'to':
                $draft->awaiting      = null;
                $draft->typeConfirmed = true;
                $draft->typePicked    = true;
                $draft->type          = str_starts_with($value, 'realisasi') ? 'realisasi' : (in_array($value, ['expense', 'advance'], true) ? $value : null);

                if ($draft->type === 'realisasi') {
                    $draft->advanceId         = $this->advances->find($user, (int) Str::after($value, 'realisasi-'))?->id;
                    $draft->advanceCandidates = [];
                }

                // Advance: satu nominal untuk semua lampirannya.
                if ($draft->type === 'advance' && $draft->isMulti()) {
                    $draft->amount = $draft->total();
                }
                break;

            case 'advlist':
                $this->showAdvanceChoices($chatId, $messageId, $draft, $user, $this->advances->realizableBy($user), mode: 'all', page: (int) $value);

                return;

            case 'ramt':
                // Ubah nominal satu struk: ditanyakan ulang sambil membalas fotonya.
                if (isset($draft->receipts[(int) $value])) {
                    $draft->receipts[(int) $value]['amount'] = null;
                    $draft->receipts[(int) $value]['ai']     = true;
                    $this->askReceipt($chatId, $draft, (int) $value);

                    return;
                }
                break;

            case 'rdel':
                if ($draft->isMulti() && isset($draft->receipts[(int) $value])) {
                    $draft->removeReceipt((int) $value);
                } elseif (! $draft->isMulti()) {
                    $draft->attachment = null;
                }

                // Pertanyaan untuk struk yang dihapus tidak berlaku lagi.
                if (str_starts_with((string) $draft->awaiting, 'receipt:')) {
                    $draft->awaiting = null;
                }
                break;

            case 'project':
                $draft->projectId         = Project::whereKey((int) $value)->exists() ? (int) $value : null;
                $draft->projectCandidates = [];
                $draft->awaiting          = null;
                break;

            case 'page':
                $this->showProjects($chatId, $messageId, $draft, (int) $value);

                return;

            case 'category':
                $draft->category = FinanceCategory::cases()[(int) $value]->value ?? $draft->category;
                break;

            case 'edit':
                // Tanggal tidak dikosongkan dulu: selama belum dijawab, tanggal lama tetap berlaku.
                if ($value === 'date') {
                    $this->ask(
                        $chatId,
                        $messageId,
                        $draft,
                        'date',
                        'Tanggal sekarang: <b>' . Carbon::parse($draft->date)->locale('id')->translatedFormat('j M Y') . "</b>.\n"
                            . 'Tulis tanggal yang benar, misalnya <i>3/11</i> (3 November), <i>3 Nov</i>, atau <i>kemarin</i>.'
                    );

                    return;
                }

                match ($value) {
                    'amount'      => $draft->amount = null,
                    'description' => $draft->description = null,
                    'project'     => [$draft->projectId, $draft->projectCandidates] = [null, []],
                    'advance'     => [$draft->advanceId, $draft->advanceCandidates] = [null, []],
                    'category'    => $draft->category = null,
                    default       => null,
                };
                break;

            case 'submit':
                $this->submit($chatId, $messageId, $user, $draft);

                return;

            case 'cancel':
                $this->cancel($chatId, $messageId);

                return;
        }

        $this->proceed($chatId, $user, $draft, $messageId);
    }

    // =================================================================
    // Alur draft
    // =================================================================

    /**
     * Tanyakan field berikutnya yang masih kosong, atau tampilkan ringkasan
     * bila semuanya sudah terisi.
     */
    private function proceed(int|string $chatId, User $user, Draft $draft, ?int $messageId = null): void
    {
        if (! $draft->type) {
            $draft->save($chatId);

            $rows = [
                [$this->button('💰 Expense — sudah bayar, minta ganti', $draft, 'type', 'expense')],
                [$this->button('💸 Advance — minta uang dulu', $draft, 'type', 'advance')],
            ];

            if ($this->advances->realizableBy($user)->isNotEmpty()) {
                $rows[] = [$this->button('🧾 Realisasi — laporan pemakaian advance', $draft, 'type', 'realisasi')];
            }

            $rows[] = [$this->button('❌ Batal', $draft, 'cancel')];

            $this->show($chatId, $messageId, 'Ini pengajuan apa?', $rows);

            return;
        }

        if ($draft->type === 'realisasi') {
            $this->proceedRealisasi($chatId, $user, $draft, $messageId);

            return;
        }

        $type = self::TYPES[$draft->type];

        if (! $user->can('create', $type['model'])) {
            Draft::forget($chatId);
            $this->show($chatId, $messageId, "Akun kamu tidak punya izin mengajukan {$type['label']}. Hubungi admin bila ini keliru.");

            return;
        }

        // Expense: satu pengajuan per struk, jadi nominal per struk. Advance:
        // lampiran hanya pendukung, cukup satu nominal advance.
        if ($draft->isMulti() && $draft->type === 'expense') {
            if (! $this->fillReceiptAmounts($chatId, $draft)) {
                return;
            }
        } elseif (! $draft->amount) {
            $this->ask($chatId, $messageId, $draft, 'amount', 'Berapa nominalnya? Contoh: <i>150rb</i> atau <i>1,5jt</i>');

            return;
        }

        if (! $draft->description) {
            $this->ask($chatId, $messageId, $draft, 'description', 'Untuk keperluan apa? Tulis singkat, misalnya <i>Grab ke kantor klien</i>.');

            return;
        }

        if (! $draft->projectId) {
            $draft->projectCandidates
                ? $this->showProjectCandidates($chatId, $messageId, $draft)
                : $this->showProjects($chatId, $messageId, $draft, 0);

            return;
        }

        // Cegah klaim ganda: biaya untuk project yang masih punya advance
        // terbuka bisa jadi sudah dibayar dari advance itu. Untuk advance,
        // tetap ditanyakan walau kata "advance" ditulis sendiri (lihat
        // Draft::$typePicked); hanya pilihan lewat tombol yang dianggap pasti.
        // Untuk advance, atau jenis yang masih tebakan, Finance/Director juga
        // melihat advance karyawan: "advance belamy 200rb" dari mereka
        // biasanya realisasi advance karyawan, bukan minta advance untuk diri
        // sendiri. Expense yang mereka tulis sendiri tidak ditahan advance
        // karyawan lain.
        $openForProject = ($draft->type === 'advance' || ! $draft->typeConfirmed ? $this->advances->realizableBy($user) : $this->advances->forUser($user))
            ->where('project_id', $draft->projectId)
            ->values();
        $sure           = $draft->type === 'advance' ? $draft->typePicked : $draft->typeConfirmed;

        if ($openForProject->isNotEmpty() && ! $sure) {
            $this->askRealisasiOrNew($chatId, $messageId, $draft, $openForProject, $user);

            return;
        }

        if (! $draft->category) {
            $this->showCategories($chatId, $messageId, $draft);

            return;
        }

        $draft->awaiting = null;

        $advanceWarning = match (true) {
            $openForProject->isEmpty()   => '',
            $draft->type === 'advance'   => "⚠️ Project ini masih punya advance terbuka. Bila ini pemakaian dana advance itu, pilih <b>Jadikan realisasi advance</b>.\n",
            default                      => "⚠️ Project ini masih punya advance terbuka. Bila biaya ini dibayar dari advance itu, pilih <b>Jadikan realisasi</b> supaya tidak tercatat dua kali.\n",
        };

        if ($draft->isMulti() && $draft->type === 'expense') {
            $count = $draft->receiptCount();

            $rows = [
                [$this->button("✅ Kirim {$count} struk", $draft, 'submit')],
                ...$this->receiptButtons($draft),
                [$this->button('📁 Project', $draft, 'edit', 'project'), $this->button('🏷 Kategori', $draft, 'edit', 'category')],
                [$this->button('✏️ Keterangan', $draft, 'edit', 'description'), $this->button('✏️ Tanggal', $draft, 'edit', 'date'), $this->button('❌ Batal', $draft, 'cancel')],
            ];

            if ($openForProject->isNotEmpty()) {
                array_splice($rows, 1, 0, [[$this->button('🧾 Jadikan realisasi advance', $draft, 'type', 'realisasi')]]);
            }

            $project = Project::with('deal')->find($draft->projectId);

            $this->showPanel(
                $chatId,
                $messageId,
                $draft,
                "📝 <b>Draft Expense — {$count} struk</b>\nMenjadi {$count} pengajuan terpisah, masing-masing dengan struknya sendiri.\n\n"
                    . '📅 Tanggal: ' . Carbon::parse($draft->date)->locale('id')->translatedFormat('j M Y') . "\n"
                    . '🏷 Kategori: ' . e($draft->category) . "\n"
                    . '📁 Project: ' . ($project ? $this->projectLines($project) : '-') . "\n"
                    . '🗒 Keterangan: ' . e($draft->description) . "\n\n"
                    . $this->receiptLines($draft) . "\n"
                    . 'Total: <b>' . $this->rupiah($draft->total()) . "</b>\n\n"
                    . $this->totalMismatchWarning($draft)
                    . $advanceWarning
                    . 'Periksa nominal tiap struk, lalu tekan <b>Kirim</b>.',
                $rows
            );

            return;
        }

        $other = $draft->type === 'expense' ? 'advance' : 'expense';

        $rows = [
            [$this->button('✅ Kirim', $draft, 'submit')],
            [$this->button('✏️ Nominal', $draft, 'edit', 'amount'), $this->button('✏️ Keterangan', $draft, 'edit', 'description'), $this->button('✏️ Tanggal', $draft, 'edit', 'date')],
            [$this->button('📁 Project', $draft, 'edit', 'project'), $this->button('🏷 Kategori', $draft, 'edit', 'category')],
            [$this->button('🔁 Jadikan ' . self::TYPES[$other]['label'], $draft, 'type', $other), $this->button('❌ Batal', $draft, 'cancel')],
        ];

        if ($openForProject->isNotEmpty()) {
            array_splice($rows, 1, 0, [[$this->button('🧾 Jadikan realisasi advance', $draft, 'type', 'realisasi')]]);
        }

        // Beberapa lampiran advance: tiap file bisa dihapus bila salah kirim.
        if ($draft->isMulti()) {
            array_splice($rows, 1, 0, $this->receiptButtons($draft, edit: false));
        }

        $this->showPanel(
            $chatId,
            $messageId,
            $draft,
            "📝 <b>Draft {$type['label']}</b>\n\n{$this->details($draft)}\n\n"
                . $advanceWarning
                . ($draft->attachment ? '' : "💡 Kirim foto struk sekarang supaya admin tidak perlu upload.\n")
                . 'Periksa lagi, lalu tekan <b>Kirim</b>.',
            $rows
        );
    }

    /**
     * Daftar struk bernomor beserta nominalnya.
     */
    private function receiptLines(Draft $draft, bool $withItems = false): string
    {
        return collect($draft->receipts)
            ->map(fn (array $receipt, int $index) => ($index + 1) . '. 📎 ' . e($this->receiptName($draft, $index))
                . ' — ' . ($receipt['amount'] ? $this->rupiah($receipt['amount']) : '❓')
                . ($withItems && ! empty($receipt['items']) ? "\n" . rtrim($this->itemLines($receipt['items'], 3, '     ')) : ''))
            ->implode("\n");
    }

    private function receiptName(Draft $draft, int $index): string
    {
        return $draft->receipts[$index]['name'] ?: ($draft->description ?: 'Struk ' . ($index + 1));
    }

    /**
     * Baris tombol ✏️/🗑 per struk, lima per baris.
     */
    private function receiptButtons(Draft $draft, bool $edit = true): array
    {
        $indexes = array_keys($draft->receipts);
        $rows    = [];

        if ($edit) {
            foreach (array_chunk($indexes, 5) as $chunk) {
                $rows[] = array_map(fn ($index) => $this->button('✏️ ' . ($index + 1), $draft, 'ramt', $index), $chunk);
            }
        }

        foreach (array_chunk($indexes, 5) as $chunk) {
            $rows[] = array_map(fn ($index) => $this->button('🗑 ' . ($index + 1), $draft, 'rdel', $index), $chunk);
        }

        return $rows;
    }

    /**
     * Peringatan bila total yang ditulis user ("realisasi 250rb …") tidak sama
     * dengan jumlah nominal per struk.
     */
    private function totalMismatchWarning(Draft $draft): string
    {
        if (! $draft->isMulti() || ! $draft->amount || abs($draft->amount - $draft->total()) <= RealisasiService::EXACT_MATCH_TOLERANCE) {
            return '';
        }

        return '⚠️ Jumlah struk ' . $this->rupiah($draft->total()) . ' berbeda dengan ' . $this->rupiah($draft->amount)
            . " yang kamu tulis. Periksa nominal tiap struk.\n";
    }

    private function askRealisasiOrNew(int|string $chatId, ?int $messageId, Draft $draft, Collection $open, User $user): void
    {
        $draft->save($chatId);

        // Semua advance di sini satu project dengan pengajuan: project-nya cukup disebut sekali.
        $project = $open->first()?->project;
        $intro   = ($open->every(fn (Advance $advance) => $advance->user_id === $user->id)
            ? 'Kamu punya advance terbuka untuk project ini:'
            : 'Project ini punya advance terbuka:')
            . ($project ? "\n📁 " . $this->projectLines($project, '    ') : '');
        $list = $this->advanceList($open, $user, withProject: false);

        [$question, $new] = $draft->type === 'advance'
            ? ['Ini laporan pemakaian advance tersebut, atau minta advance baru?', '💸 Bukan, minta advance baru']
            : ['Apakah pengeluaran ini dibayar dari advance tersebut?', '💰 Bukan, expense baru'];

        $this->show(
            $chatId,
            $messageId,
            "{$intro}\n\n{$list}\n\n{$question}",
            [
                [$this->button('🧾 Ya, realisasi advance', $draft, 'type', 'realisasi')],
                [$this->button($new, $draft, 'type', $draft->type)],
                [$this->button('❌ Batal', $draft, 'cancel')],
            ]
        );
    }

    // =================================================================
    // Realisasi advance
    // =================================================================

    private function proceedRealisasi(int|string $chatId, User $user, Draft $draft, ?int $messageId): void
    {
        $open = $this->advances->realizableBy($user);

        if ($open->isEmpty()) {
            Draft::forget($chatId);
            $this->show($chatId, $messageId, (OpenAdvances::seesAll($user) ? 'Tidak ada advance' : 'Kamu tidak punya advance') . ' yang bisa direalisasi. Realisasi bisa diisi setelah advance berstatus <b>Dibayarkan</b>.');

            return;
        }

        // Advance yang dipilih harus tetap milik user dan masih terbuka.
        if ($draft->advanceId && ! $open->contains('id', $draft->advanceId)) {
            $draft->advanceId = null;
        }

        if (! $draft->advanceId && ! $draft->advanceCandidates) {
            $match = $this->advances->match($user, $draft->projectId);
            $draft->advanceId         = $match['advance_id'];
            $draft->advanceCandidates = $match['candidates'];
        }

        if (! $draft->advanceId) {
            // Tanpa advance yang cocok dengan project-nya: semua advance terbuka, per halaman.
            $draft->advanceCandidates
                ? $this->showAdvanceChoices($chatId, $messageId, $draft, $user, $open->whereIn('id', $draft->advanceCandidates)->values())
                : $this->showAdvanceChoices($chatId, $messageId, $draft, $user, $open, mode: 'all');

            return;
        }

        $advance = $open->firstWhere('id', $draft->advanceId);

        // Dulu baru ditolak saat Kirim, setelah semua struk dan nominal diisi.
        if ($blocked = $this->advances->blockedReason($advance)) {
            $draft->advanceId = null;
            $draft->save($chatId);
            $this->show(
                $chatId,
                $messageId,
                "⛔ Advance #{$advance->id} <b>" . $this->advanceName($advance) . "</b> belum bisa ditambah realisasinya.\n" . e($blocked),
                [
                    [$this->button('📋 Advance lain', $draft, 'advlist', 0), $this->button('💰 Jadikan Expense', $draft, 'type', 'expense')],
                    [$this->button('❌ Batal', $draft, 'cancel')],
                ]
            );

            return;
        }

        $remaining = $this->advances->remaining($advance);

        if ($draft->isMulti()) {
            // Nama item per struk opsional; yang kosong memakai keterangan umum.
            if (! $this->fillReceiptAmounts($chatId, $draft)) {
                return;
            }

            $draft->awaiting = null;
            $count = $draft->receiptCount();
            $after = $remaining - $draft->total();

            $this->showPanel(
                $chatId,
                $messageId,
                $draft,
                "🧾 <b>Draft Realisasi — {$count} struk</b>\nMenjadi 1 batch dengan {$count} item, masing-masing dengan struknya sendiri.\n\n"
                    . '💸 Advance #' . $advance->id . ': <b>' . $this->advanceName($advance) . '</b> — ' . $this->rupiah($advance->paid) . "\n"
                    . ($advance->user_id !== $user->id ? '👤 Pengaju: ' . e($this->name($advance->user)) . "\n" : '')
                    . ($advance->project ? '📁 Project: ' . $this->projectLines($advance->project) . "\n" : '')
                    . "\n" . $this->receiptLines($draft) . "\n"
                    . 'Total: <b>' . $this->rupiah($draft->total()) . "</b>\n"
                    . 'Sisa advance: ' . $this->rupiah($remaining) . ' → ' . $this->rupiah($after) . "\n\n"
                    . $this->totalMismatchWarning($draft)
                    . ($after < -RealisasiService::EXACT_MATCH_TOLERANCE
                        ? '⚠️ Melebihi sisa advance ' . $this->rupiah(abs($after)) . ". Butuh persetujuan Finance, dan realisasi berikutnya ditahan sampai disetujui.\n"
                        : '')
                    . $this->realisasiGuessNote($draft)
                    . 'Periksa nominal tiap struk, lalu tekan <b>Kirim</b>.',
                [
                    [$this->button("✅ Kirim {$count} struk", $draft, 'submit')],
                    ...$this->receiptButtons($draft),
                    [$this->button('🔁 Advance lain', $draft, 'advlist'), $this->button('💰 Jadikan Expense', $draft, 'type', 'expense')],
                    [$this->button('❌ Batal', $draft, 'cancel')],
                ]
            );

            return;
        }

        if (! $draft->amount) {
            $this->ask($chatId, $messageId, $draft, 'amount', 'Berapa nominal di struk ini? Contoh: <i>350rb</i>');

            return;
        }

        if (! $draft->description) {
            $this->ask($chatId, $messageId, $draft, 'description', 'Untuk apa? Tulis nama barang/jasanya, misalnya <i>Sewa lighting</i>.');

            return;
        }

        $draft->awaiting = null;
        $after           = $remaining - $draft->amount;

        $this->showPanel(
            $chatId,
            $messageId,
            $draft,
            "🧾 <b>Draft Realisasi</b>\n\n{$this->realisasiDetails($draft, $advance, $remaining, $user)}\n\n"
                . ($after < -RealisasiService::EXACT_MATCH_TOLERANCE
                    ? '⚠️ Melebihi sisa advance ' . $this->rupiah(abs($after)) . ". Butuh persetujuan Finance, dan realisasi berikutnya ditahan sampai disetujui.\n"
                    : '')
                . ($draft->attachment ? '' : "💡 Kirim foto struk sekarang sebagai bukti untuk Finance.\n")
                . $this->realisasiGuessNote($draft)
                . 'Periksa lagi, lalu tekan <b>Kirim</b>.',
            [
                [$this->button('✅ Kirim', $draft, 'submit')],
                [$this->button('✏️ Nominal', $draft, 'edit', 'amount'), $this->button('✏️ Keterangan', $draft, 'edit', 'description')],
                [$this->button('🔁 Advance lain', $draft, 'advlist'), $this->button('💰 Jadikan Expense', $draft, 'type', 'expense')],
                [$this->button('❌ Batal', $draft, 'cancel')],
            ]
        );
    }

    /**
     * Daftar advance dengan detail lengkap di teks dan tombol pendek berisi ID
     * advance (sama dengan kolom ID di ERP) — tombol Telegram memotong teks panjang.
     *
     * $mode: 'candidates' (tebakan dari pesan), 'all' (semua advance terbuka,
     * per halaman), atau 'search' (hasil ketikan).
     *
     * @param  Collection<int, Advance>  $choices
     */
    private function showAdvanceChoices(int|string $chatId, ?int $messageId, Draft $draft, User $user, Collection $choices, string $mode = 'candidates', int $page = 0): void
    {
        $total = $choices->count();
        $pages = max(1, (int) ceil($total / self::ADVANCES_PER_PAGE));
        $page  = $mode === 'all' ? min(max($page, 0), $pages - 1) : 0;
        $shown = $choices->slice($page * self::ADVANCES_PER_PAGE, self::ADVANCES_PER_PAGE)->values();

        $rows = $shown
            ->map(fn (Advance $advance) => [$this->button($this->advanceButtonLabel($advance, $user), $draft, 'adv', $advance->id)])
            ->all();

        // Dulu "Advance lain" hanya menampilkan 8 advance terbaru; sisanya tidak bisa dipilih.
        if ($mode === 'all' && $pages > 1) {
            $rows[] = array_values(array_filter([
                $page > 0 ? $this->button('⬅️ Sebelumnya', $draft, 'advlist', $page - 1) : null,
                $page < $pages - 1 ? $this->button('Berikutnya ➡️', $draft, 'advlist', $page + 1) : null,
            ]));
        }

        if ($mode !== 'all') {
            $rows[] = [$this->button('📋 Semua advance terbuka', $draft, 'advlist', 0)];
        }

        // Realisasi yang masih tebakan (struk diunggah tanpa kata "realisasi").
        if (! $draft->typeConfirmed) {
            $rows[] = [$this->button('💰 Bukan realisasi — Expense baru', $draft, 'type', 'expense')];
        }
        $rows[] = [$this->button('❌ Batal', $draft, 'cancel')];

        $heading = match (true) {
            $total === 0      => 'Tidak ada advance yang cocok.',
            $mode === 'all'   => "Realisasi untuk advance yang mana? <b>{$total} advance terbuka</b>"
                . ($pages > 1 ? ', halaman ' . ($page + 1) . " dari {$pages}." : '.'),
            $mode === 'search' => "Ditemukan {$total} advance" . ($total > self::ADVANCES_PER_PAGE ? ', ' . self::ADVANCES_PER_PAGE . ' teratas ditampilkan.' : '.'),
            default           => 'Realisasi untuk advance yang mana?',
        };

        // Daftar lengkap dan hasil pencarian: ketik ID atau nama untuk mencari.
        $search = '';
        if ($mode !== 'candidates') {
            $draft->awaiting = 'advance';
            $search = "\n\nKetik ID advance (mis. <i>#" . ($shown->first()?->id ?? 12) . '</i>), '
                . (OpenAdvances::seesAll($user) ? 'nama pengaju, ' : '') . 'klien, atau project untuk mencari.';
        }

        $draft->save($chatId);

        $lines = $this->advanceList($shown, $user);
        $this->show($chatId, $messageId, $heading . ($lines !== '' ? "\n\n{$lines}" : '') . $search, $rows);
    }

    /**
     * " (Budi)" untuk advance milik orang lain; Finance/Director melihat semua advance.
     */
    private function ownerTag(Advance $advance, User $user): string
    {
        return $advance->user_id !== $user->id && $advance->user ? ' (' . Text::short($advance->user->firstname ?: $this->name($advance->user), 12) . ')' : '';
    }

    /**
     * Keterangan advance utuh. Batas 150 karakter hanya menjaga 8 advance
     * tetap muat dalam satu pesan Telegram (maks. 4096 karakter).
     */
    private function advanceName(Advance $advance): string
    {
        return e(Text::short($advance->keterangan_advance, 150));
    }

    /**
     * "#12 · Advance Booth Pameran (Budi)": ID-nya sama dengan daftar di pesan dan kolom ID di ERP.
     */
    private function advanceButtonLabel(Advance $advance, User $user): string
    {
        return "#{$advance->id} · " . Text::short($advance->keterangan_advance, 28) . $this->ownerTag($advance, $user);
    }

    /**
     * @param  Collection<int, Advance>  $advances
     */
    private function advanceList(Collection $advances, User $user, bool $withProject = true): string
    {
        return $advances
            ->map(fn (Advance $advance) => $this->advanceSummary($advance, $user, $withProject))
            ->implode("\n\n");
    }

    /**
     * Nama lengkap advance dan penandanya (ID, project, kategori, tanggal,
     * pengaju, rincian) supaya advance yang namanya mirip bisa dibedakan.
     */
    private function advanceSummary(Advance $advance, User $user, bool $withProject = true): string
    {
        $lines = ["#{$advance->id} · <b>" . $this->advanceName($advance) . '</b>'];

        if ($withProject && $advance->project) {
            $lines[] = '   📁 ' . $this->projectLines($advance->project, '       ');
        }

        $lines[] = '   ' . implode(' · ', array_filter([
            $advance->category ? '🏷 ' . e($advance->category) : null,
            '🗓 ' . Carbon::parse($advance->submission_date ?? $advance->created_at)->locale('id')->translatedFormat('j M Y'),
            $advance->user_id !== $user->id && $advance->user ? '👤 ' . e($this->name($advance->user)) : null,
        ]));

        // Rincian yang sama dengan nama advance tidak menambah penanda.
        $items = $advance->itemAdvance
            ->map(fn ($item) => trim((string) $item->name))
            ->filter(fn (string $name) => $name !== '' && mb_strtolower($name) !== mb_strtolower(trim((string) $advance->keterangan_advance)))
            ->unique()
            ->values();

        if ($items->isNotEmpty()) {
            $lines[] = '   📝 Rincian: ' . e(Text::short($items->take(3)->implode(', '), 60))
                . ($items->count() > 3 ? ' +' . ($items->count() - 3) . ' lainnya' : '');
        }

        $remaining = $this->advances->remaining($advance);
        $lines[]   = '   💸 Cair ' . $this->rupiah($advance->paid)
            . ($remaining < 0 ? ' · melebihi <b>' . $this->rupiah(-$remaining) . '</b>' : ' · sisa <b>' . $this->rupiah($remaining) . '</b>');

        if ($blocked = $this->advances->blockedReason($advance)) {
            $lines[] = '   ⛔ ' . e($blocked);
        }

        return implode("\n", $lines);
    }

    /**
     * Draft realisasi yang jenisnya tebakan: struk diunggah tanpa kata "realisasi".
     */
    private function realisasiGuessNote(Draft $draft): string
    {
        return $draft->typeConfirmed ? '' : "ℹ️ Struk/invoice dianggap realisasi advance. Bila dibayar sendiri, pilih <b>💰 Jadikan Expense</b>.\n";
    }

    private function realisasiDetails(Draft $draft, Advance $advance, float $remaining, User $user): string
    {
        return implode("\n", array_filter([
            '💸 Advance #' . $advance->id . ': <b>' . $this->advanceName($advance) . '</b> — ' . $this->rupiah($advance->paid),
            $advance->user_id !== $user->id ? '👤 Pengaju: ' . e($this->name($advance->user)) : null,
            $advance->project ? '📁 Project: ' . $this->projectLines($advance->project) : null,
            '🗒 Item: ' . e($draft->description),
            $draft->lineItems() ? "🧾 Rincian:\n" . rtrim($this->itemLines($draft->lineItems(), 12)) : null,
            '💰 Nominal: <b>' . $this->rupiah($draft->amount) . '</b>',
            '📎 Lampiran: ' . $this->attachmentLabel($draft),
            '📉 Sisa advance: ' . $this->rupiah($remaining) . ' → ' . $this->rupiah($remaining - $draft->amount),
        ]));
    }

    private function submitRealisasi(int|string $chatId, int $messageId, User $user, Draft $draft): void
    {
        $advance = $this->advances->find($user, $draft->advanceId);

        if (! $advance || ! $draft->isComplete()) {
            $draft->advanceId = $advance?->id;
            $this->proceed($chatId, $user, $draft, $messageId);

            return;
        }

        if ($previous = $this->draftHasSubmittedReceipt($draft)) {
            $this->telegram->sendMessage($chatId, "⚠️ Salah satu struk sudah pernah dikirim ({$previous}). Hapus struk itu dulu.");

            return;
        }

        // Folder yang sama dengan unggahan dari halaman realisasi di ERP.
        $stored = $this->storeReceipts($draft, "realisasi/{$advance->id}");

        if ($stored['failed']) {
            $this->receiptFailedMessage($chatId, $stored['failed'], $draft->receiptCount());

            return;
        }

        $files   = $stored['files'];
        $fileRow = fn (array $file) => [
            'original_name' => "struk-telegram.{$file['ext']}",
            'filepath'      => $file['path'],
            'filesize'      => $file['size'],
            'mime_type'     => $file['mime'],
        ];

        // Satu struk = satu item dengan strukny sendiri, supaya Finance tahu
        // bukti mana untuk item mana.
        // Rincian struk dari Gemini menjadi keterangan item tersebut.
        $items = $draft->isMulti()
            ? collect($draft->receipts)->map(fn (array $receipt, int $index) => [
                'name'        => Str::limit($this->receiptName($draft, $index), 100, ''),
                'description' => $this->itemsText($receipt['items'] ?? []) ?? $draft->description,
                'quantity'    => 1,
                'price'       => $receipt['amount'],
                'files'       => [$fileRow($files[$index])],
            ])->all()
            : [[
                'name'        => Str::limit($draft->description, 100, ''),
                'description' => $this->itemsText($draft->lineItems()) ?? $draft->description,
                'quantity'    => 1,
                'price'       => $draft->amount,
                'files'       => $files ? [$fileRow($files[0])] : [],
            ]];

        try {
            DB::transaction(function () use ($advance, $user, $draft, $items) {
                // Aturan realisasi (status advance, overrun yang belum disetujui)
                // dijaga RealisasiService, sama seperti dari halaman ERP.
                $this->realisasi->createBatch($advance, ['notes' => 'Dikirim lewat bot Telegram', 'uploaded_by' => $user->id], $items);
                $this->rememberReceipts($draft, $user, 'Realisasi ' . Text::short($advance->keterangan_advance, 40));
            });
        } catch (InvalidArgumentException $e) {
            $this->deleteStored($files);
            $this->telegram->sendMessage($chatId, '⚠️ ' . e($e->getMessage()));

            return;
        } catch (UniqueConstraintViolationException) {
            // Struk yang sama dikirim dari draft lain di saat bersamaan.
            $this->deleteStored($files);
            $this->telegram->sendMessage($chatId, '⚠️ Salah satu struk baru saja dikirim dari pengajuan lain. Tidak ada yang tersimpan.');

            return;
        } catch (Throwable $e) {
            $this->deleteStored($files);

            throw $e;
        }

        Draft::forget($chatId);

        $advance->refresh();
        $evaluation = $this->realisasi->evaluateStatus($advance);
        $percent    = (float) $advance->paid > 0 ? round($evaluation['total_realisasi'] / (float) $advance->paid * 100) : 0;

        $position = match ($evaluation['status']) {
            'exact_match' => 'Total realisasi sudah sama dengan advance. Finance akan menyelesaikannya.',
            'under_run'   => 'Sisa ' . $this->rupiah($evaluation['remaining']) . '. Kirim struk berikutnya, atau kembalikan sisanya ke Finance.',
            'over_run'    => 'Melebihi advance ' . $this->rupiah($evaluation['overrun']) . '. Menunggu persetujuan Finance.',
            default       => '',
        };

        $link    = url("/admin/advances/{$advance->id}/realisasi");
        $buttons = str_starts_with($link, 'https://') ? [[['text' => 'Lihat di ERP', 'url' => $link]]] : [];

        $this->show(
            $chatId,
            $messageId,
            "✅ <b>Realisasi terkirim</b>\n\n"
                . 'Advance #' . $advance->id . ': <b>' . $this->advanceName($advance) . "</b>\n"
                . ($advance->user_id !== $user->id ? 'Pengaju: ' . e($this->name($advance->user)) . "\n" : '')
                . ($draft->isMulti()
                    ? $this->receiptLines($draft) . "\nTotal: " . $this->rupiah($draft->total()) . "\n\n"
                    : 'Item: ' . e($draft->description) . ' — ' . $this->rupiah($draft->amount) . "\n"
                        . 'Lampiran: ' . $this->attachmentLabel($draft) . "\n\n")
                . 'Terealisasi ' . $this->rupiah($evaluation['total_realisasi']) . ' dari ' . $this->rupiah($advance->paid) . " ({$percent}%).\n"
                . $position,
            $buttons
        );
    }

    private function ask(int|string $chatId, ?int $messageId, Draft $draft, string $field, string $question): void
    {
        $draft->awaiting = $field;
        $draft->save($chatId);

        $this->show($chatId, $messageId, $question, [[$this->button('❌ Batal', $draft, 'cancel')]]);
    }

    private function showProjects(int|string $chatId, ?int $messageId, Draft $draft, int $page): void
    {
        $pages = array_chunk($this->projectChoices(), self::PROJECTS_PER_PAGE, true);
        $page  = max(0, min($page, count($pages) - 1));

        $rows = [];
        foreach ($pages[$page] ?? [] as $id => $label) {
            $rows[] = [$this->button(Text::short($label, 60), $draft, 'project', $id)];
        }

        $nav = [];
        if ($page > 0) {
            $nav[] = $this->button('⬅️ Sebelumnya', $draft, 'page', $page - 1);
        }
        if ($page < count($pages) - 1) {
            $nav[] = $this->button('Berikutnya ➡️', $draft, 'page', $page + 1);
        }
        if ($nav) {
            $rows[] = $nav;
        }
        $rows[] = [$this->button('❌ Batal', $draft, 'cancel')];

        // Teks yang diketik saat daftar ini tampil dipakai untuk mencari project.
        $draft->awaiting = 'project';
        $draft->save($chatId);

        $this->show(
            $chatId,
            $messageId,
            $pages
                ? 'Untuk project apa? Pilih di bawah, atau ketik nama klien/project untuk mencari.'
                : 'Belum ada project aktif. Ketik nama klien/project untuk mencari.',
            $rows
        );
    }

    private function showProjectCandidates(int|string $chatId, ?int $messageId, Draft $draft): void
    {
        $projects = ProjectMatcher::projects($draft->projectCandidates);

        if ($projects->isEmpty()) {
            $draft->projectCandidates = [];
            $this->showProjects($chatId, $messageId, $draft, 0);

            return;
        }

        [$lines, $rows] = $this->numberedProjects($projects, $draft);

        $rows[] = [$this->button('📋 Project lain', $draft, 'page', 0), $this->button('❌ Batal', $draft, 'cancel')];

        $draft->awaiting = 'project';
        $draft->save($chatId);

        $this->show($chatId, $messageId, "Ada beberapa project yang cocok. Maksud kamu yang mana?\n\n{$lines}", $rows);
    }

    /**
     * Daftar bernomor: detail lengkap (klien, project, PIC) di teks pesan dan
     * tombol pendek bernomor, karena Telegram memotong teks tombol yang panjang
     * — padahal project satu klien sering hanya beda di PIC-nya.
     *
     * @param  Collection<int, Project>  $projects
     * @return array{0: string, 1: array<int, array<int, array>>}
     */
    private function numberedProjects(Collection $projects, Draft $draft): array
    {
        $projects = $projects->values();

        $lines = $projects
            ->map(fn (Project $project, int $index) => ($index + 1) . '. ' . $this->projectLines($project, '    '))
            ->implode("\n");

        // PIC di depan label, jadi project satu klien yang beda PIC tetap
        // terbedakan walau teks tombolnya terpotong.
        $rows = $projects
            ->map(fn (Project $project, int $index) => [
                $this->button(Text::short(($index + 1) . '. ' . $this->projectLabel($project), 60), $draft, 'project', $project->id),
            ])
            ->all();

        return [$lines, $rows];
    }

    private function showProjectSearch(int|string $chatId, User $user, Draft $draft, string $query): void
    {
        // Pencocokan nama klien yang toleran salah ketik dulu; bila hanya satu
        // yang cocok, langsung dipakai tanpa perlu menekan tombol lagi.
        $match = $this->matcher->match($query);

        if ($match['project_id']) {
            $draft->projectId         = $match['project_id'];
            $draft->projectCandidates = [];
            $draft->awaiting          = null;
            $this->proceed($chatId, $user, $draft);

            return;
        }

        $like = '%' . addcslashes($query, '%_\\') . '%';

        $projects = $match['candidates']
            ? ProjectMatcher::projects($match['candidates'])
            : Project::with('deal')
                ->where(fn ($q) => $q
                    ->where('nama_project', 'like', $like)
                    ->orWhere('nama_perusahaan', 'like', $like)
                    ->orWhereHas('deal', fn ($deal) => $deal->where('nama_perusahaan', 'like', $like)))
                ->latest('id')
                ->limit(self::PROJECTS_PER_PAGE)
                ->get();

        [$lines, $rows] = $this->numberedProjects($projects, $draft);

        $rows[] = [$this->button('📋 Lihat daftar', $draft, 'page', 0), $this->button('❌ Batal', $draft, 'cancel')];

        $this->telegram->sendMessage(
            $chatId,
            $projects->isEmpty()
                ? 'Project dengan kata <i>' . e($query) . '</i> tidak ditemukan. Coba kata lain, atau buka daftar.'
                : "Pilih project:\n\n{$lines}",
            $rows
        );
    }

    private function showCategories(int|string $chatId, ?int $messageId, Draft $draft): void
    {
        $draft->awaiting = null;
        $draft->save($chatId);

        $buttons = collect(FinanceCategory::cases())
            ->map(fn (FinanceCategory $category, int $index) => $this->button($category->value, $draft, 'category', $index))
            ->chunk(2)
            ->map(fn ($row) => $row->values()->all())
            ->values()
            ->all();

        $buttons[] = [$this->button('❌ Batal', $draft, 'cancel')];

        $this->show($chatId, $messageId, 'Pilih kategori:', $buttons);
    }

    private function submit(int|string $chatId, int $messageId, User $user, Draft $draft): void
    {
        if ($draft->type === 'realisasi') {
            $this->submitRealisasi($chatId, $messageId, $user, $draft);

            return;
        }

        $type = self::TYPES[$draft->type] ?? null;

        // Draft bisa berubah setelah ringkasan tampil (mis. tombol ✏️ ditekan,
        // atau project-nya dihapus), jadi semuanya dicek ulang sebelum menyimpan.
        // proceed() yang menanyakan ulang bagian yang kurang atau menolak izinnya.
        if ($draft->projectId && ! Project::whereKey($draft->projectId)->exists()) {
            $draft->projectId = null;
        }

        if (! $type || ! $draft->isComplete() || ! $user->can('create', $type['model'])) {
            $this->proceed($chatId, $user, $draft, $messageId);

            return;
        }

        if ($previous = $this->draftHasSubmittedReceipt($draft)) {
            $this->telegram->sendMessage($chatId, "⚠️ Salah satu struk sudah pernah dikirim ({$previous}). Hapus struk itu dulu.");

            return;
        }

        // Struk diunduh sebelum apa pun disimpan: lebih baik user mengirim ulang
        // fotonya daripada pengajuan masuk tanpa lampiran yang dikiranya ada.
        $folder = ($draft->type === 'advance' ? 'advance/attachment/' : 'expense/invoice/') . now()->format('Y/m/d');
        $stored = $this->storeReceipts($draft, $folder);

        if ($stored['failed']) {
            $this->receiptFailedMessage($chatId, $stored['failed'], $draft->receiptCount());

            return;
        }

        $files = $stored['files'];

        // Expense: satu struk = satu pengajuan (kolom lampiran expense hanya
        // muat satu file). Advance: satu pengajuan dengan semua lampirannya.
        $entries = $draft->isMulti() && $draft->type === 'expense'
            ? collect($draft->receipts)->map(fn (array $receipt, int $index) => [
                'amount'      => $receipt['amount'],
                'description' => $this->receiptName($draft, $index),
                'path'        => $files[$index]['path'],
                'paths'       => [$files[$index]['path']],
                'items'       => $receipt['items'] ?? [],
            ])->all()
            : [[
                'amount'      => $draft->amount,
                'description' => $draft->description,
                'path'        => $files[0]['path'] ?? null,
                'paths'       => array_column($files, 'path'),
                'items'       => $draft->lineItems(),
            ]];

        try {
            $records = DB::transaction(function () use ($draft, $user, $entries) {
                $records = [];

                foreach ($entries as $entry) {
                    $attributes = [
                        'user_id'         => $user->id,
                        'submission_date' => $draft->date,
                        'project_id'      => $draft->projectId,
                        'category'        => $draft->category,
                        'amount'          => $entry['amount'],
                        'attachment'      => $entry['path'],
                    ];

                    if ($draft->type === 'advance') {
                        $advance = Advance::create($attributes + [
                            'keterangan_advance' => $entry['description'],
                            'attachments'        => $entry['paths'] ?: null,
                            'status'             => Advance::STATUS_PENDING,
                        ]);

                        // Rincian invoice/penawaran yang dibaca dari lampiran.
                        foreach ($entry['items'] as $item) {
                            ItemAdvance::create(['advance_id' => $advance->id] + $this->purchaseItem($item));
                        }

                        $records[] = $advance;

                        continue;
                    }

                    $expense = Expense::create($attributes + ['keterangan_expense' => $entry['description'], 'status' => ExpenseStatus::Pending->value]);

                    // Tombol "Upload Invoice Pembelian" di ERP — yang juga mengisi item
                    // pembelian — hilang begitu lampiran ada, jadi itemnya dibuat di sini:
                    // rincian struk bila terbaca, selain itu satu item senilai pengajuan.
                    if ($entry['items']) {
                        foreach ($entry['items'] as $item) {
                            $expense->itemExpenses()->create($this->purchaseItem($item));
                        }
                    } elseif ($entry['path']) {
                        $expense->itemExpenses()->create([
                            'name'        => Str::limit($entry['description'], 100, ''),
                            'description' => $entry['description'],
                            'quantity'    => 1,
                            'price'       => $entry['amount'],
                        ]);
                    }

                    $records[] = $expense;
                }

                $this->rememberReceipts($draft, $user, self::TYPES[$draft->type]['label'] . ' ' . Text::short($draft->description, 40));

                return $records;
            });
        } catch (UniqueConstraintViolationException) {
            // Struk yang sama dikirim dari draft lain di saat bersamaan.
            $this->deleteStored($files);
            $this->telegram->sendMessage($chatId, '⚠️ Salah satu struk baru saja dikirim dari pengajuan lain. Tidak ada yang tersimpan.');

            return;
        } catch (Throwable $e) {
            $this->deleteStored($files);

            throw $e;
        }

        Draft::forget($chatId);

        $path    = $draft->type === 'advance' ? 'advances' : 'expenses';
        $link    = url(count($records) === 1 ? "/admin/{$path}/{$records[0]->id}" : "/admin/{$path}");
        $buttons = str_starts_with($link, 'https://') ? [[['text' => 'Lihat di ERP', 'url' => $link]]] : [];

        $next = collect($records)->every(fn ($record) => AutoApproval::wasApplied($record))
            ? 'dan langsung <b>disetujui</b> (auto approve), tinggal menunggu pembayaran Finance.'
            : 'dan menunggu persetujuan Finance.';

        $this->show(
            $chatId,
            $messageId,
            $draft->isMulti() && $draft->type === 'expense'
                ? '✅ <b>' . count($records) . " expense terkirim</b> {$next}\n\n"
                    . $this->receiptLines($draft) . "\nTotal: " . $this->rupiah($draft->total()) . "\n\nKetik /status untuk memantau."
                : "✅ <b>{$type['label']} #{$records[0]->id} terkirim</b> {$next}\n\n{$this->details($draft)}\n\nKetik /status untuk memantau.",
            $buttons
        );
    }

    /**
     * Baris rincian struk sebagai item pembelian expense/advance.
     *
     * @param  array{name: string, qty: int, price: int}  $item
     */
    private function purchaseItem(array $item): array
    {
        return [
            'name'        => $item['name'],
            'description' => 'Dari struk (dibaca otomatis)',
            'quantity'    => $item['qty'],
            'price'       => $item['price'],
        ];
    }

    private function cancel(int|string $chatId, ?int $messageId = null): void
    {
        Draft::forget($chatId);
        $this->show($chatId, $messageId, 'Draft dibatalkan. Tidak ada yang tersimpan.');
    }

    // =================================================================
    // Perintah lain
    // =================================================================

    private function start(int|string $chatId, string $token): void
    {
        if ($token !== '') {
            $user = $this->linker->consume($token, $chatId);

            if (! $user) {
                $this->telegram->sendMessage($chatId, 'Link ini sudah kedaluwarsa atau sudah dipakai. Buat link baru dari ERP → <b>Profil</b> → <b>Hubungkan Telegram</b>.');

                return;
            }

            $this->telegram->sendMessage($chatId, '✅ Terhubung sebagai <b>' . e($this->name($user)) . "</b>.\n\n" . $this->helpText());

            return;
        }

        $user = $this->userFor($chatId);

        if (! $user) {
            $this->sendNotLinked($chatId);

            return;
        }

        $this->telegram->sendMessage($chatId, 'Halo, <b>' . e($this->name($user)) . "</b>!\n\n" . $this->helpText());
    }

    /**
     * /listadvance [kata kunci]: advance terbuka beserta ID-nya, per halaman.
     * Karyawan melihat advance miliknya; Finance/Director/super_admin semua.
     * ID-nya dipakai di pesan realisasi ("realisasi #12 ...").
     */
    private function sendAdvanceList(int|string $chatId, User $user, int $page = 0, string $query = '', ?int $messageId = null): void
    {
        $query = trim($query);
        $all   = $query === '' ? $this->advances->realizableBy($user) : $this->advances->search($user, $query);
        $mine  = OpenAdvances::seesAll($user) ? '' : ' milikmu';

        if ($all->isEmpty()) {
            $this->show($chatId, $messageId, $query === ''
                ? "Belum ada advance terbuka{$mine}. Advance muncul di sini setelah berstatus <b>Dibayarkan</b>."
                : 'Tidak ada advance terbuka' . $mine . ' yang cocok dengan <i>' . e($query) . '</i>. Ketik /listadvance untuk melihat semuanya.');

            return;
        }

        $pages = (int) ceil($all->count() / self::ADVANCES_PER_PAGE);
        $page  = min(max($page, 0), $pages - 1);
        $shown = $all->slice($page * self::ADVANCES_PER_PAGE, self::ADVANCES_PER_PAGE)->values();

        $heading = $query === ''
            ? "📋 <b>Advance terbuka{$mine}</b> · {$all->count()} advance" . ($pages > 1 ? ' · halaman ' . ($page + 1) . " dari {$pages}" : '')
            : '📋 <b>Advance yang cocok dengan <i>' . e($query) . "</i></b> · {$all->count()} advance";

        // Hasil pencarian tidak diberi halaman: data tombol Telegram maks. 64 byte.
        $buttons = $query === '' && $pages > 1
            ? [array_values(array_filter([
                $page > 0 ? ['text' => '⬅️ Sebelumnya', 'callback_data' => 'menu:advances:' . ($page - 1)] : null,
                $page < $pages - 1 ? ['text' => 'Berikutnya ➡️', 'callback_data' => 'menu:advances:' . ($page + 1)] : null,
            ]))]
            : [];

        $example = $shown->first()->id;

        $this->show(
            $chatId,
            $messageId,
            "{$heading}\n\n" . $this->advanceList($shown, $user)
                . ($query !== '' && $all->count() > $shown->count() ? "\n\n" . $shown->count() . ' teratas ditampilkan; ketik kata yang lebih spesifik.' : '')
                . "\n\nUntuk realisasi, kirim foto struk dengan keterangan <i>realisasi #{$example}</i>."
                . "\nCari: <i>/listadvance belamy</i> atau <i>/listadvance {$example}</i>.",
            $buttons
        );
    }

    private function sendStatus(int|string $chatId, User $user): void
    {
        $expenses = Expense::where('user_id', $user->id)->latest('id')->limit(5)->get()
            ->map(fn (Expense $e) => ['type' => 'expense', 'id' => $e->id, 'at' => $e->created_at, 'amount' => $e->amount, 'status' => $e->status, 'description' => $e->keterangan_expense]);

        $advances = Advance::where('user_id', $user->id)->latest('id')->limit(5)->get()
            ->map(fn (Advance $a) => ['type' => 'advance', 'id' => $a->id, 'at' => $a->created_at, 'amount' => $a->amount, 'status' => $a->getStatusLabel(), 'description' => $a->keterangan_advance]);

        $items = $expenses->concat($advances)->sortByDesc('at')->take(5);
        $open  = $this->advances->forUser($user);

        if ($items->isEmpty()) {
            $this->telegram->sendMessage($chatId, 'Belum ada pengajuan.');

            return;
        }

        $lines = $items->map(function (array $item) {
            $type = self::TYPES[$item['type']];
            $date = $item['at'] ? Carbon::parse($item['at'])->locale('id')->translatedFormat('j M') . ' · ' : '';

            return "{$type['icon']} <b>{$type['label']} #{$item['id']}</b> · {$this->rupiah($item['amount'])} · <i>" . e($item['status']) . "</i>\n"
                . $date . e(Text::short($item['description'], 70));
        });

        $message = "<b>{$items->count()} pengajuan terakhir</b>\n\n" . $lines->implode("\n\n");

        if ($open->isNotEmpty()) {
            $progress = $open->map(function (Advance $advance) {
                $realized = $this->advances->realized($advance);
                $percent  = (float) $advance->paid > 0 ? round($realized / (float) $advance->paid * 100) : 0;

                return '🧾 #' . $advance->id . ' · ' . $this->advanceName($advance) . "\n"
                    . ($advance->project ? '📁 ' . e($this->projectLabel($advance->project)) . "\n" : '')
                    . 'Terealisasi ' . $this->rupiah($realized) . ' dari ' . $this->rupiah($advance->paid) . " ({$percent}%)";
            });

            $message .= "\n\n<b>Advance yang perlu direalisasi</b>\n\n" . $progress->implode("\n\n")
                . "\n\nUntuk melaporkan pemakaiannya, kirim foto struk dengan keterangan <i>realisasi 350rb …</i>";
        }

        $this->telegram->sendMessage($chatId, $message);
    }

    private function sendHelp(int|string $chatId): void
    {
        $this->telegram->sendMessage($chatId, $this->helpText());
    }

    private function sendNotLinked(int|string $chatId): void
    {
        $this->telegram->sendMessage($chatId, "Akun Telegram ini belum terhubung ke ERP.\n\nBuka ERP → <b>Profil</b> → <b>Hubungkan Telegram</b>, lalu tekan link yang muncul.");
    }

    private function helpText(): string
    {
        return <<<'HTML'
        <b>Cara mengajukan</b>
        Cukup kirim <b>foto struk/invoice</b>: bot membaca isi dan rinciannya, lalu kamu tinggal memilih masuk ke advance mana, expense baru, atau advance baru.

        Atau tulis keperluannya dalam satu pesan (boleh dengan foto), misalnya:
        • <i>expense 150rb grab ke kantor klien nestory</i>
        • <i>advance 2jt beli properti shooting</i>
        • <i>realisasi 350rb sewa lighting nestory</i>

        <i>Realisasi</i> = laporan pemakaian advance yang sudah dibayarkan. Tulis ID advance-nya untuk langsung memilihnya, misalnya <i>realisasi #12 sewa lighting</i>; ID ada di /listadvance.

        Bot mengenali kategori, project, dan advance-nya, lalu menanyakan yang kurang. Struk ikut tersimpan di ERP, jadi tidak perlu upload lagi.
        Belum ada yang tersimpan sampai kamu menekan <b>Kirim</b>.

        <b>Perintah</b>
        /expense – ajukan expense (reimburse)
        /advance – ajukan advance (uang muka)
        /realisasi – laporkan pemakaian advance
        /listadvance – daftar advance terbuka & ID-nya
        /status – pengajuan terakhir & progres advance
        /batal – batalkan draft
        HTML;
    }

    // =================================================================
    // Helper
    // =================================================================

    /**
     * @return int|null id pesan yang menampilkan $html
     */
    private function show(int|string $chatId, ?int $messageId, string $html, array $buttons = []): ?int
    {
        // Saat menanggapi tombol, ubah pesan yang sama supaya chat tidak penuh.
        if ($messageId && $this->telegram->editMessage($chatId, $messageId, $html, $buttons)) {
            return $messageId;
        }

        return $this->telegram->sendMessage($chatId, $html, $buttons)['message_id'] ?? null;
    }

    /**
     * Tampilkan ringkasan/panel draft dan ingat pesannya, supaya bisa
     * diperbarui di tempat saat struk dari album yang sama masih berdatangan.
     */
    private function showPanel(int|string $chatId, ?int $messageId, Draft $draft, string $html, array $buttons): void
    {
        $draft->panelMessageId = $this->show($chatId, $messageId, $html, $buttons);
        $draft->save($chatId);
    }

    private function button(string $text, Draft $draft, string $action, string|int $value = ''): array
    {
        return ['text' => $text, 'callback_data' => "{$draft->id}:{$action}:{$value}"];
    }

    private function details(Draft $draft): string
    {
        $project = Project::with('deal')->find($draft->projectId);

        return implode("\n", [
            '📅 Tanggal: ' . Carbon::parse($draft->date)->locale('id')->translatedFormat('j M Y'),
            '💰 Nominal: <b>' . $this->rupiah($draft->amount) . '</b>',
            '🏷 Kategori: ' . e($draft->category),
            '📁 Project: ' . ($project ? $this->projectLines($project) : '-'),
            '🗒 Keterangan: ' . e($draft->description),
            ...($draft->lineItems() ? ["🧾 Rincian:\n" . rtrim($this->itemLines($draft->lineItems(), 12))] : []),
            '📎 Lampiran: ' . $this->attachmentLabel($draft),
        ]);
    }

    /**
     * "PIC - Perusahaan", lalu nama project di baris berikutnya. Dalam satu baris,
     * "PIC - Perusahaan | Project" terpenggal tidak rapi di layar HP.
     */
    private function projectLines(Project $project, string $indent = '      '): string
    {
        [$client, $name] = ClientLabel::projectParts($project);

        if ($client === null || $name === null) {
            return e($this->projectLabel($project));
        }

        return e($client) . "\n{$indent}↳ " . e($name);
    }

    private function attachmentLabel(Draft $draft): string
    {
        if ($draft->isMulti()) {
            return "📎 {$draft->receiptCount()} file";
        }

        return match ($draft->attachment['ext'] ?? null) {
            null    => 'belum ada',
            'pdf'   => '📎 PDF struk',
            default => '📎 foto struk',
        };
    }

    /**
     * Project yang ditawarkan ke AI dan ke daftar tombol: yang belum selesai,
     * terbaru dulu. Project lain tetap bisa dicari dengan mengetik namanya.
     *
     * @return array<int, string>
     */
    private function projectChoices(): array
    {
        return Project::with('deal')
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['completed', 'Completed']))
            ->latest('id')
            ->limit(self::PROJECT_CHOICES)
            ->get()
            // PIC ikut dikirim ke AI dan tampil di tombol: project satu klien
            // sering hanya bisa dibedakan dari PIC-nya.
            ->mapWithKeys(fn (Project $project) => [$project->id => $this->projectLabel($project)])
            ->all();
    }

    /**
     * "PIC - Perusahaan | Nama project", sama di semua bot.
     */
    private function projectLabel(Project $project): string
    {
        return ClientLabel::project($project);
    }

    private function userFor(int|string $chatId): ?User
    {
        return User::where('telegram_chat_id', (string) $chatId)->where('is_active', true)->first();
    }

    private function name(User $user): string
    {
        return trim("{$user->firstname} {$user->lastname}") ?: $user->username;
    }

    private function rupiah(int|float|string|null $amount): string
    {
        return 'Rp ' . number_format((float) $amount, 0, ',', '.');
    }

    /**
     * "/expense@krafty_bot 150rb grab" → ['expense', '150rb grab'].
     *
     * @return array{0: ?string, 1: string}
     */
    private function splitCommand(string $text): array
    {
        if (! preg_match('/^\/(\w+)(?:@\w+)?(?:\s+(.*))?$/su', $text, $m)) {
            return [null, $text];
        }

        return [Str::lower($m[1]), trim($m[2] ?? '')];
    }
}
