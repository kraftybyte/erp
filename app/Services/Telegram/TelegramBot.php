<?php

namespace App\Services\Telegram;

use App\Enums\ExpenseStatus;
use App\Enums\FinanceCategory;
use App\Models\Advance;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

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

    private const TYPES = [
        'expense' => ['label' => 'Expense', 'icon' => '💰', 'hint' => 'reimburse', 'model' => Expense::class, 'path' => 'expenses'],
        'advance' => ['label' => 'Advance', 'icon' => '💸', 'hint' => 'uang muka', 'model' => Advance::class, 'path' => 'advances'],
    ];

    public function __construct(
        private TelegramClient $telegram,
        private SubmissionParser $parser,
        private TelegramLinker $linker,
        private ProjectMatcher $matcher,
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

        if (isset($message['photo']) || isset($message['document'])) {
            $this->telegram->sendMessage($chatId, 'Foto dan file belum bisa disimpan lewat bot. Lampirkan struk dari ERP setelah pengajuan disetujui.');
        }

        if ($text === '') {
            return;
        }

        match ($command) {
            'expense', 'advance' => $this->startDraft($chatId, $user, $argument, $command),
            'status'             => $this->sendStatus($chatId, $user),
            'batal'              => $this->cancel($chatId),
            null                 => $this->handleText($chatId, $user, $text),
            default              => $this->sendHelp($chatId),
        };
    }

    private function handleText(int|string $chatId, User $user, string $text): void
    {
        $draft = Draft::load($chatId);

        if ($draft?->awaiting) {
            $this->answerPrompt($chatId, $user, $draft, $text);

            return;
        }

        // Pesan baru tanpa draft yang sedang ditanyakan dianggap pengajuan baru.
        $this->startDraft($chatId, $user, $text, null);
    }

    private function startDraft(int|string $chatId, User $user, string $text, ?string $type): void
    {
        $draft = Draft::start($type);

        if ($text !== '') {
            $this->telegram->typing($chatId);
            $draft->fill($this->parser->parse($text, $this->projectChoices()));
            // Perintah /expense atau /advance lebih kuat dari tebakan AI.
            $draft->type = $type ?? $draft->type;
        }

        $this->proceed($chatId, $user, $draft);
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
                $draft->type = array_key_exists($value, self::TYPES) ? $value : $draft->type;
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
                match ($value) {
                    'amount'      => $draft->amount = null,
                    'description' => $draft->description = null,
                    'project'     => [$draft->projectId, $draft->projectCandidates] = [null, []],
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
            $this->show($chatId, $messageId, 'Ini pengajuan apa?', [
                [$this->button('💰 Expense — sudah bayar, minta ganti', $draft, 'type', 'expense')],
                [$this->button('💸 Advance — minta uang dulu', $draft, 'type', 'advance')],
                [$this->button('❌ Batal', $draft, 'cancel')],
            ]);

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

        if (! $draft->category) {
            $this->showCategories($chatId, $messageId, $draft);

            return;
        }

        $draft->awaiting = null;
        $draft->save($chatId);

        $other = $draft->type === 'expense' ? 'advance' : 'expense';

        $this->show(
            $chatId,
            $messageId,
            "📝 <b>Draft {$type['label']}</b>\n\n{$this->details($draft)}\n\nPeriksa lagi, lalu tekan <b>Kirim</b>.",
            [
                [$this->button('✅ Kirim', $draft, 'submit')],
                [$this->button('✏️ Nominal', $draft, 'edit', 'amount'), $this->button('✏️ Keterangan', $draft, 'edit', 'description')],
                [$this->button('📁 Project', $draft, 'edit', 'project'), $this->button('🏷 Kategori', $draft, 'edit', 'category')],
                [$this->button('🔁 Jadikan ' . self::TYPES[$other]['label'], $draft, 'type', $other), $this->button('❌ Batal', $draft, 'cancel')],
            ]
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

        $rows = $projects
            ->map(fn (Project $project) => [$this->button(Str::limit($this->projectLabel($project), 60), $draft, 'project', $project->id)])
            ->all();

        $rows[] = [$this->button('📋 Project lain', $draft, 'page', 0), $this->button('❌ Batal', $draft, 'cancel')];

        $draft->awaiting = 'project';
        $draft->save($chatId);

        $this->show($chatId, $messageId, 'Ada beberapa project yang cocok. Maksud kamu yang mana?', $rows);
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

        $rows = $projects
            ->map(fn (Project $project) => [$this->button(Str::limit($this->projectLabel($project), 60), $draft, 'project', $project->id)])
            ->all();

        $rows[] = [$this->button('📋 Lihat daftar', $draft, 'page', 0), $this->button('❌ Batal', $draft, 'cancel')];

        $this->telegram->sendMessage(
            $chatId,
            $projects->isEmpty()
                ? 'Project dengan kata <i>' . e($query) . '</i> tidak ditemukan. Coba kata lain, atau buka daftar.'
                : 'Pilih project:',
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

        $attributes = [
            'user_id'         => $user->id,
            'submission_date' => $draft->date,
            'project_id'      => $draft->projectId,
            'category'        => $draft->category,
            'amount'          => $draft->amount,
        ];

        $record = $draft->type === 'advance'
            ? Advance::create($attributes + ['keterangan_advance' => $draft->description, 'status' => Advance::STATUS_PENDING])
            : Expense::create($attributes + ['keterangan_expense' => $draft->description, 'status' => ExpenseStatus::Pending->value]);

        Draft::forget($chatId);

        $link    = url("/admin/{$type['path']}/{$record->id}");
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

        $this->telegram->sendMessage($chatId, "<b>5 pengajuan terakhir</b>\n\n" . $lines->implode("\n\n"));
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
        Tulis pengajuan dalam satu pesan, misalnya:
        • <i>expense 150rb grab ke kantor klien PT Maju</i>
        • <i>advance 2jt beli properti shooting video profile</i>

        Bot menyiapkan draft dan menanyakan yang kurang. Tidak ada yang tersimpan sebelum kamu menekan <b>Kirim</b>.

        /expense – ajukan expense (reimburse)
        /advance – ajukan advance (uang muka)
        /status – 5 pengajuan terakhir
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
            'Project: ' . e($project ? $this->projectLabel($project) : '-'),
            'Kategori: ' . e($draft->category),
            'Nominal: <b>' . $this->rupiah($draft->amount) . '</b>',
            'Keterangan: ' . e($draft->description),
        ]);
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
            ->mapWithKeys(fn (Project $project) => [$project->id => $this->projectLabel($project)])
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
