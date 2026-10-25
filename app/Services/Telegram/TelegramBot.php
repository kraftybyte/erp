<?php

namespace App\Services\Telegram;

use App\Enums\ExpenseStatus;
use App\Enums\FinanceCategory;
use App\Models\Advance;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\RealisasiService;
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
        Cache::lock("telegram:chat:{$chat['id']}", 30)->block(15, function () use ($update) {
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

        if ($text !== '') {
            $this->telegram->typing($chatId);
            $draft->fill($this->parser->parse($text, $this->projectChoices(), $this->receiptForAi($draft)));
            // Perintah lebih kuat dari tebakan AI.
            $draft->type = $type ?? $draft->type;
        }

        $this->proceed($chatId, $user, $draft);
    }

    private function draftAwaitingDetails(int|string $chatId): ?Draft
    {
        $draft = Draft::load($chatId);

        if ($draft?->awaiting !== 'details') {
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
            $photo = end($message['photo']);
            $file  = ['file_id' => $photo['file_id'], 'mime' => 'image/jpeg', 'size' => $photo['file_size'] ?? 0];
        } elseif (! empty($message['document'])) {
            $document = $message['document'];
            $file     = ['file_id' => $document['file_id'], 'mime' => $document['mime_type'] ?? '', 'size' => $document['file_size'] ?? 0];
        } else {
            return null;
        }

        if (! isset(self::ATTACHMENT_TYPES[$file['mime']])) {
            return ['error' => 'Format file ini belum didukung. Kirim sebagai <b>foto</b>, atau berkas JPG, PNG, WebP, atau PDF.'];
        }

        if ($file['size'] > self::MAX_ATTACHMENT_BYTES) {
            return ['error' => 'Ukuran file lebih dari 5 MB. Kirim sebagai <b>foto</b> supaya dikecilkan otomatis oleh Telegram.'];
        }

        return [
            'file_id' => $file['file_id'],
            'mime'    => $file['mime'],
            'ext'     => self::ATTACHMENT_TYPES[$file['mime']],
            'group'   => $message['media_group_id'] ?? null,
        ];
    }

    private function receiveAttachment(int|string $chatId, User $user, array $file, string $text, ?string $command, string $argument): void
    {
        $draft = Draft::load($chatId);

        // Album dikirim sebagai beberapa pesan. Satu pengajuan menyimpan satu
        // lampiran, jadi hanya foto pertama dari album yang dipakai.
        if ($file['group'] && ($draft?->attachment['group'] ?? null) === $file['group']) {
            return;
        }

        // Foto dengan caption: caption-nya keterangan pengajuan.
        if ($text !== '') {
            $type = array_key_exists((string) $command, self::TYPES) ? $command : null;

            $draft = Draft::start($type);
            $draft->attachment = $file;
            $this->startDraft($chatId, $user, $type ? $argument : $text, $type, $draft);

            return;
        }

        // Foto susulan untuk draft yang sedang disusun.
        if ($draft && ! $draft->isEmpty()) {
            $replaced = (bool) $draft->attachment;
            $draft->attachment = $file;
            $this->telegram->sendMessage($chatId, $replaced ? '📎 Lampiran diganti dengan foto terbaru.' : '📎 Struk dilampirkan ke draft.');
            $this->proceed($chatId, $user, $draft);

            return;
        }

        // Struk lebih dulu: simpan, lalu tunggu keterangannya.
        $draft = Draft::start();
        $draft->attachment = $file;
        $draft->awaiting   = 'details';
        $draft->save($chatId);

        $this->telegram->sendMessage(
            $chatId,
            "📎 Struk diterima.\n\nSekarang tulis keterangannya, misalnya:\n• <i>expense 150rb grab ke kantor klien nestory</i>\n• <i>realisasi 350rb sewa lighting nestory</i>",
            [[$this->button('❌ Batal', $draft, 'cancel')]]
        );
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

        $data = $this->telegram->download($draft->attachment['file_id'], self::MAX_ATTACHMENT_BYTES);

        return $data === null ? null : ['mime' => $draft->attachment['mime'], 'data' => $data];
    }

    /**
     * Unduh struk dari Telegram ke disk public ERP. Null bila gagal diunduh
     * atau isinya bukan gambar/PDF.
     *
     * @return array{path: string, size: int, mime: string, ext: string}|null
     */
    private function storeAttachment(Draft $draft, string $folder): ?array
    {
        $data = $this->telegram->download($draft->attachment['file_id'], self::MAX_ATTACHMENT_BYTES);

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

        $ext  = self::ATTACHMENT_TYPES[$mime];
        $path = "{$folder}/" . Str::uuid() . ".{$ext}";

        Storage::disk('public')->put($path, $data);

        return ['path' => $path, 'size' => strlen($data), 'mime' => $mime, 'ext' => $ext];
    }

    private function answerPrompt(int|string $chatId, User $user, Draft $draft, string $text): void
    {
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

            case 'project':
                $this->showProjectSearch($chatId, $user, $draft, $text);

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
                break;

            case 'adv':
                $draft->advanceId         = $this->advances->find($user, (int) $value)?->id;
                $draft->advanceCandidates = [];
                break;

            case 'advlist':
                $this->showAdvanceChoices($chatId, $messageId, $draft, $this->advances->forUser($user), all: true);

                return;

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

            if ($this->advances->forUser($user)->isNotEmpty()) {
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

        if (! $draft->amount) {
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
        // terbuka bisa jadi sudah dibayar dari advance itu.
        $openForProject = $draft->type === 'expense'
            ? $this->advances->forUser($user)->where('project_id', $draft->projectId)->values()
            : collect();

        if ($openForProject->isNotEmpty() && ! $draft->typeConfirmed) {
            $this->askExpenseOrRealisasi($chatId, $messageId, $draft, $openForProject);

            return;
        }

        if (! $draft->category) {
            $this->showCategories($chatId, $messageId, $draft);

            return;
        }

        $draft->awaiting = null;
        $draft->save($chatId);

        $other = $draft->type === 'expense' ? 'advance' : 'expense';

        $rows = [
            [$this->button('✅ Kirim', $draft, 'submit')],
            [$this->button('✏️ Nominal', $draft, 'edit', 'amount'), $this->button('✏️ Keterangan', $draft, 'edit', 'description')],
            [$this->button('📁 Project', $draft, 'edit', 'project'), $this->button('🏷 Kategori', $draft, 'edit', 'category')],
            [$this->button('🔁 Jadikan ' . self::TYPES[$other]['label'], $draft, 'type', $other), $this->button('❌ Batal', $draft, 'cancel')],
        ];

        if ($openForProject->isNotEmpty()) {
            array_splice($rows, 1, 0, [[$this->button('🧾 Jadikan realisasi advance', $draft, 'type', 'realisasi')]]);
        }

        $this->show(
            $chatId,
            $messageId,
            "📝 <b>Draft {$type['label']}</b>\n\n{$this->details($draft)}\n\n"
                . ($openForProject->isNotEmpty()
                    ? "⚠️ Project ini masih punya advance terbuka. Bila biaya ini dibayar dari advance itu, pilih <b>Jadikan realisasi</b> supaya tidak tercatat dua kali.\n"
                    : '')
                . ($draft->attachment ? '' : "💡 Kirim foto struk sekarang supaya admin tidak perlu upload.\n")
                . 'Periksa lagi, lalu tekan <b>Kirim</b>.',
            $rows
        );
    }

    private function askExpenseOrRealisasi(int|string $chatId, ?int $messageId, Draft $draft, Collection $open): void
    {
        $draft->save($chatId);

        $list = $open
            ->map(fn (Advance $advance) => '• ' . e(Str::limit((string) $advance->keterangan_advance, 60))
                . ' — sisa ' . $this->rupiah($this->advances->remaining($advance)))
            ->implode("\n");

        $this->show(
            $chatId,
            $messageId,
            "Kamu punya advance terbuka untuk project ini:\n{$list}\n\nApakah pengeluaran ini dibayar dari advance tersebut?",
            [
                [$this->button('🧾 Ya, realisasi advance', $draft, 'type', 'realisasi')],
                [$this->button('💰 Bukan, expense baru', $draft, 'type', 'expense')],
                [$this->button('❌ Batal', $draft, 'cancel')],
            ]
        );
    }

    // =================================================================
    // Realisasi advance
    // =================================================================

    private function proceedRealisasi(int|string $chatId, User $user, Draft $draft, ?int $messageId): void
    {
        $open = $this->advances->forUser($user);

        if ($open->isEmpty()) {
            Draft::forget($chatId);
            $this->show($chatId, $messageId, 'Kamu tidak punya advance yang bisa direalisasi. Realisasi bisa diisi setelah advance berstatus <b>Dibayarkan</b>.');

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
            $this->showAdvanceChoices($chatId, $messageId, $draft, $open->whereIn('id', $draft->advanceCandidates)->values());

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
        $draft->save($chatId);

        $advance   = $open->firstWhere('id', $draft->advanceId);
        $remaining = $this->advances->remaining($advance);
        $after     = $remaining - $draft->amount;

        $this->show(
            $chatId,
            $messageId,
            "🧾 <b>Draft Realisasi</b>\n\n{$this->realisasiDetails($draft, $advance, $remaining)}\n\n"
                . ($after < -RealisasiService::EXACT_MATCH_TOLERANCE
                    ? '⚠️ Melebihi sisa advance ' . $this->rupiah(abs($after)) . ". Butuh persetujuan Finance, dan realisasi berikutnya ditahan sampai disetujui.\n"
                    : '')
                . ($draft->attachment ? '' : "💡 Kirim foto struk sekarang sebagai bukti untuk Finance.\n")
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
     * Daftar bernomor dengan detail lengkap (project, PIC, sisa) di teks, dan
     * tombol pendek bernomor — tombol Telegram memotong teks panjang.
     *
     * @param  Collection<int, Advance>  $choices
     */
    private function showAdvanceChoices(int|string $chatId, ?int $messageId, Draft $draft, Collection $choices, bool $all = false): void
    {
        $choices = $choices->take(8)->values();

        $lines = $choices
            ->map(fn (Advance $advance, int $index) => ($index + 1) . '. ' . $this->advanceSummary($advance))
            ->implode("\n\n");

        $rows = $choices
            ->map(fn (Advance $advance, int $index) => [$this->button(
                ($index + 1) . '. ' . Str::limit((string) $advance->keterangan_advance, 28) . ' · sisa ' . $this->rupiah($this->advances->remaining($advance)),
                $draft,
                'adv',
                $advance->id
            )])
            ->all();

        if (! $all) {
            $rows[] = [$this->button('📋 Advance lain', $draft, 'advlist')];
        }
        $rows[] = [$this->button('❌ Batal', $draft, 'cancel')];

        $draft->save($chatId);

        $this->show($chatId, $messageId, "Realisasi untuk advance yang mana?\n\n{$lines}", $rows);
    }

    private function advanceSummary(Advance $advance): string
    {
        $lines = ['<b>' . e(Str::limit((string) $advance->keterangan_advance, 80)) . '</b> — ' . $this->rupiah($advance->paid)];

        if ($advance->project) {
            $lines[] = '   ' . e($this->projectLabel($advance->project)) . $this->picSuffix($advance->project);
        }

        $lines[] = '   Sisa: ' . $this->rupiah($this->advances->remaining($advance));

        return implode("\n", $lines);
    }

    private function realisasiDetails(Draft $draft, Advance $advance, float $remaining): string
    {
        return implode("\n", array_filter([
            'Advance: <b>' . e(Str::limit((string) $advance->keterangan_advance, 80)) . '</b> — ' . $this->rupiah($advance->paid),
            $advance->project ? 'Project: ' . e($this->projectLabel($advance->project)) . $this->picSuffix($advance->project) : null,
            'Item: ' . e($draft->description),
            'Nominal: <b>' . $this->rupiah($draft->amount) . '</b>',
            'Lampiran: ' . $this->attachmentLabel($draft),
            'Sisa advance: ' . $this->rupiah($remaining) . ' → ' . $this->rupiah($remaining - $draft->amount),
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

        $stored = null;
        if ($draft->attachment) {
            // Folder yang sama dengan unggahan dari halaman realisasi di ERP.
            $stored = $this->storeAttachment($draft, "realisasi/{$advance->id}");

            if ($stored === null) {
                $this->telegram->sendMessage($chatId, 'Struk gagal diambil dari Telegram. Kirim ulang fotonya, lalu tekan <b>Kirim</b> lagi.');

                return;
            }
        }

        try {
            // Aturan realisasi (status advance, overrun yang belum disetujui)
            // dijaga RealisasiService, sama seperti dari halaman ERP.
            $this->realisasi->createBatch(
                $advance,
                ['notes' => 'Dikirim lewat bot Telegram', 'uploaded_by' => $user->id],
                [[
                    'name'        => Str::limit($draft->description, 100, ''),
                    'description' => $draft->description,
                    'quantity'    => 1,
                    'price'       => $draft->amount,
                ]],
                $stored ? [[
                    'original_name' => "struk-telegram.{$stored['ext']}",
                    'filepath'      => $stored['path'],
                    'filesize'      => $stored['size'],
                    'mime_type'     => $stored['mime'],
                ]] : []
            );
        } catch (InvalidArgumentException $e) {
            if ($stored) {
                Storage::disk('public')->delete($stored['path']);
            }

            $this->telegram->sendMessage($chatId, '⚠️ ' . e($e->getMessage()));

            return;
        } catch (Throwable $e) {
            if ($stored) {
                Storage::disk('public')->delete($stored['path']);
            }

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
                . 'Advance: <b>' . e(Str::limit((string) $advance->keterangan_advance, 80)) . "</b>\n"
                . 'Item: ' . e($draft->description) . ' — ' . $this->rupiah($draft->amount) . "\n"
                . 'Lampiran: ' . $this->attachmentLabel($draft) . "\n\n"
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
            $rows[] = [$this->button(Str::limit($label, 60), $draft, 'project', $id)];
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
            ->map(fn (Project $project, int $index) => ($index + 1) . '. ' . e($this->projectLabel($project))
                . (($pic = ProjectMatcher::pic($project)) ? "\n    PIC: " . e($pic) : ''))
            ->implode("\n");

        $rows = $projects
            ->map(function (Project $project, int $index) use ($draft) {
                $pic   = ProjectMatcher::pic($project);
                $label = ($index + 1) . '. ' . ($project->nama_project ?: $this->projectLabel($project))
                    . ($pic ? ' · ' . Str::before($pic, ' ') : '');

                return [$this->button(Str::limit($label, 60), $draft, 'project', $project->id)];
            })
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

        // Struk diunduh sebelum apa pun disimpan: lebih baik user mengirim ulang
        // fotonya daripada pengajuan masuk tanpa lampiran yang dikiranya ada.
        $path = null;
        if ($draft->attachment) {
            $folder = ($draft->type === 'advance' ? 'advance/attachment/' : 'expense/invoice/') . now()->format('Y/m/d');
            $path   = $this->storeAttachment($draft, $folder)['path'] ?? null;

            if ($path === null) {
                $this->telegram->sendMessage($chatId, 'Struk gagal diambil dari Telegram. Kirim ulang fotonya, lalu tekan <b>Kirim</b> lagi.');

                return;
            }
        }

        $attributes = [
            'user_id'         => $user->id,
            'submission_date' => $draft->date,
            'project_id'      => $draft->projectId,
            'category'        => $draft->category,
            'amount'          => $draft->amount,
            'attachment'      => $path,
        ];

        try {
            $record = DB::transaction(function () use ($draft, $attributes, $path) {
                if ($draft->type === 'advance') {
                    return Advance::create($attributes + ['keterangan_advance' => $draft->description, 'status' => Advance::STATUS_PENDING]);
                }

                $expense = Expense::create($attributes + ['keterangan_expense' => $draft->description, 'status' => ExpenseStatus::Pending->value]);

                // Tombol "Upload Invoice Pembelian" di ERP — yang juga mengisi item
                // pembelian — hilang begitu lampiran ada, jadi itemnya dibuat di sini.
                if ($path) {
                    $expense->itemExpenses()->create([
                        'name'        => Str::limit($draft->description, 100, ''),
                        'description' => $draft->description,
                        'quantity'    => 1,
                        'price'       => $draft->amount,
                    ]);
                }

                return $expense;
            });
        } catch (Throwable $e) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }

            throw $e;
        }

        Draft::forget($chatId);

        $link    = url('/admin/' . ($draft->type === 'advance' ? 'advances' : 'expenses') . "/{$record->id}");
        $buttons = str_starts_with($link, 'https://') ? [[['text' => 'Lihat di ERP', 'url' => $link]]] : [];

        $this->show(
            $chatId,
            $messageId,
            "✅ <b>{$type['label']} terkirim</b> dan menunggu persetujuan Finance.\n\n{$this->details($draft)}\n\nKetik /status untuk memantau.",
            $buttons
        );
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

    private function sendStatus(int|string $chatId, User $user): void
    {
        $expenses = Expense::where('user_id', $user->id)->latest('id')->limit(5)->get()
            ->map(fn (Expense $e) => ['type' => 'expense', 'at' => $e->created_at, 'amount' => $e->amount, 'status' => $e->status, 'description' => $e->keterangan_expense]);

        $advances = Advance::where('user_id', $user->id)->latest('id')->limit(5)->get()
            ->map(fn (Advance $a) => ['type' => 'advance', 'at' => $a->created_at, 'amount' => $a->amount, 'status' => $a->getStatusLabel(), 'description' => $a->keterangan_advance]);

        $items = $expenses->concat($advances)->sortByDesc('at')->take(5);
        $open  = $this->advances->forUser($user);

        if ($items->isEmpty()) {
            $this->telegram->sendMessage($chatId, 'Belum ada pengajuan.');

            return;
        }

        $lines = $items->map(function (array $item) {
            $type = self::TYPES[$item['type']];
            $date = $item['at'] ? Carbon::parse($item['at'])->locale('id')->translatedFormat('j M') . ' · ' : '';

            return "{$type['icon']} <b>{$type['label']}</b> · {$this->rupiah($item['amount'])} · <i>" . e($item['status']) . "</i>\n"
                . $date . e(Str::limit((string) $item['description'], 60));
        });

        $message = "<b>5 pengajuan terakhir</b>\n\n" . $lines->implode("\n\n");

        if ($open->isNotEmpty()) {
            $progress = $open->map(function (Advance $advance) {
                $realized = $this->advances->realized($advance);
                $percent  = (float) $advance->paid > 0 ? round($realized / (float) $advance->paid * 100) : 0;

                return '🧾 ' . e(Str::limit((string) $advance->keterangan_advance, 50)) . "\n"
                    . $this->rupiah($realized) . ' / ' . $this->rupiah($advance->paid) . " ({$percent}%)";
            });

            $message .= "\n\n<b>Advance yang perlu direalisasi</b>\n\n" . $progress->implode("\n\n")
                . "\n\nKirim struk + <i>realisasi …</i> untuk melaporkannya.";
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
        1. Kirim <b>foto struk/invoice</b> (boleh dilewati).
        2. Tulis keterangannya dalam satu pesan, misalnya:
        • <i>expense 150rb grab ke kantor klien nestory</i>
        • <i>advance 2jt beli properti shooting video profile</i>
        • <i>realisasi 350rb sewa lighting nestory</i> — melaporkan pemakaian advance yang sudah dibayarkan

        Bot mengenali kategori, project, dan advance-nya, lalu menanyakan yang kurang. Struk ikut tersimpan di ERP, jadi tidak perlu upload lagi. Tidak ada yang tersimpan sebelum kamu menekan <b>Kirim</b>.

        /expense – ajukan expense (reimburse)
        /advance – ajukan advance (uang muka)
        /realisasi – laporkan pemakaian advance
        /status – pengajuan terakhir & progres advance
        /batal – batalkan draft
        HTML;
    }

    // =================================================================
    // Helper
    // =================================================================

    private function show(int|string $chatId, ?int $messageId, string $html, array $buttons = []): void
    {
        // Saat menanggapi tombol, ubah pesan yang sama supaya chat tidak penuh.
        if ($messageId && $this->telegram->editMessage($chatId, $messageId, $html, $buttons)) {
            return;
        }

        $this->telegram->sendMessage($chatId, $html, $buttons);
    }

    private function button(string $text, Draft $draft, string $action, string|int $value = ''): array
    {
        return ['text' => $text, 'callback_data' => "{$draft->id}:{$action}:{$value}"];
    }

    private function details(Draft $draft): string
    {
        $project = Project::with('deal')->find($draft->projectId);

        return implode("\n", [
            'Tanggal: ' . Carbon::parse($draft->date)->locale('id')->translatedFormat('j M Y'),
            'Project: ' . ($project ? e($this->projectLabel($project)) . $this->picSuffix($project) : '-'),
            'Kategori: ' . e($draft->category),
            'Nominal: <b>' . $this->rupiah($draft->amount) . '</b>',
            'Keterangan: ' . e($draft->description),
            'Lampiran: ' . $this->attachmentLabel($draft),
        ]);
    }

    private function attachmentLabel(Draft $draft): string
    {
        return match ($draft->attachment['ext'] ?? null) {
            null    => 'belum ada',
            'pdf'   => '📎 PDF struk',
            default => '📎 foto struk',
        };
    }

    private function picSuffix(Project $project): string
    {
        $pic = ProjectMatcher::pic($project);

        return $pic ? ' · PIC ' . e($pic) : '';
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
            ->mapWithKeys(fn (Project $project) => [
                $project->id => $this->projectLabel($project) . (($pic = ProjectMatcher::pic($project)) ? " · {$pic}" : ''),
            ])
            ->all();
    }

    private function projectLabel(Project $project): string
    {
        $company = $project->nama_perusahaan ?: $project->deal?->nama_perusahaan;

        return implode(' | ', array_filter([$company, $project->nama_project])) ?: "Project #{$project->id}";
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
