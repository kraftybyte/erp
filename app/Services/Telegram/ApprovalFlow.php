<?php

namespace App\Services\Telegram;

use App\Exceptions\DecisionException;
use App\Helpers\ClientLabel;
use App\Models\Advance;
use App\Models\Expense;
use App\Models\User;
use App\Services\RealisasiService;
use App\Services\SubmissionDecision;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Keputusan dari bot laporan keuangan:
 * - Setujui / Tolak pengajuan (tombol di notifikasi, atau /persetujuan);
 *   menolak menanyakan alasannya dulu.
 * - Verifikasi realisasi advance (/verifikasi atau tombol di notifikasi):
 *   setujui kelebihan, catat pengembalian sisa, selesaikan advance.
 *
 * Semuanya lewat SubmissionDecision dan RealisasiService, jalur yang sama
 * dengan ERP. Hak akses dicek FinanceBot di setiap pesan dan tombol.
 */
class ApprovalFlow
{
    private const PAGE = 8;

    private const TTL_MINUTES = 30;

    public function __construct(private TelegramClient $telegram, private SubmissionDecision $decisions, private RealisasiService $realisasi) {}

    public function awaitingText(int|string $chatId): bool
    {
        return Cache::has($this->key($chatId));
    }

    public function forget(int|string $chatId): void
    {
        Cache::forget($this->key($chatId));
    }

    /**
     * Alasan penolakan yang diketik setelah menekan ❌ Tolak.
     */
    public function handleText(int|string $chatId, User $user, string $text): void
    {
        $state = Cache::get($this->key($chatId));

        try {
            $record = $this->decisions->reject($state['type'], $state['id'], $user, $text);
        } catch (DecisionException $e) {
            if (str_contains($e->getMessage(), 'minimal')) {
                $this->telegram->sendMessage($chatId, e($e->getMessage()) . ' Ketik alasannya lagi.');

                return;
            }

            $this->forget($chatId);
            $this->telegram->sendMessage($chatId, '⚠️ ' . e($e->getMessage()));

            return;
        }

        $this->forget($chatId);
        $done = '❌ ' . $this->title($record) . " ditolak oleh {$this->name($user)}.\nAlasan: " . e($record->rejection_reason);

        if ($state['message_id'] ?? null) {
            $this->telegram->editMessage($chatId, $state['message_id'], $done);
        }

        $this->telegram->sendMessage($chatId, $done . "\n\nPengaju sudah diberi tahu.");
    }

    public function handleCallback(array $callback, User $user): void
    {
        $chatId    = $callback['message']['chat']['id'];
        $messageId = $callback['message']['message_id'];
        [$group, $action, $a, $b] = array_pad(explode(':', (string) $callback['data']), 4, null);

        $this->telegram->answerCallback($callback['id']);

        // Tindakan RealisasiService memeriksa pengguna yang sedang masuk.
        Auth::setUser($user);

        match ("{$group}:{$action}") {
            'apv:ok'    => $this->approve($chatId, $messageId, $user, (string) $a, (int) $b),
            'apv:no'    => $this->askReason($chatId, $messageId, (string) $a, (int) $b),
            'apv:list'  => $this->pendingList($chatId, (int) $a, $messageId),
            'apv:x'     => $this->cancelReason($chatId, $messageId),
            'rlz:list'  => $this->verificationList($chatId, (int) $a, $messageId),
            'rlz:open'  => $this->showAdvance($chatId, (int) $a, $messageId),
            'rlz:over'  => $this->act($chatId, $messageId, (int) $a, 'over'),
            'rlz:fin'   => $this->act($chatId, $messageId, (int) $a, 'finish'),
            'rlz:ret'   => $this->confirmReturn($chatId, $messageId, (int) $a),
            'rlz:retok' => $this->act($chatId, $messageId, (int) $a, 'return'),
            default     => null,
        };
    }

    // =================================================================
    // Persetujuan
    // =================================================================

    /**
     * /persetujuan: pengajuan yang menunggu keputusan, terlama di depan.
     */
    public function pendingList(int|string $chatId, int $page = 0, ?int $messageId = null): void
    {
        $pending = $this->decisions->pending();

        if ($pending->isEmpty()) {
            $this->show($chatId, $messageId, '✅ Tidak ada pengajuan yang menunggu persetujuan.');

            return;
        }

        $pages = (int) ceil($pending->count() / self::PAGE);
        $page  = min(max($page, 0), $pages - 1);
        $shown = $pending->slice($page * self::PAGE, self::PAGE);
        $lines = ["📝 <b>Menunggu persetujuan</b> · {$pending->count()} pengajuan" . ($pages > 1 ? ' · halaman ' . ($page + 1) . " dari {$pages}" : ''), ''];
        $rows  = [];

        foreach ($shown as $record) {
            $type    = $record instanceof Expense ? 'expense' : 'advance';
            $what    = $record instanceof Expense ? $record->keterangan_expense : $record->keterangan_advance;
            $lines[] = '• <b>' . $this->title($record) . '</b> · ' . e($this->name($record->user)) . ' · ' . $this->age($record->created_at)
                . "\n   " . e(Text::short((string) $what, 70));
            $rows[]  = [
                ['text' => '✅ ' . ucfirst($type) . " #{$record->id}", 'callback_data' => "apv:ok:{$type}:{$record->id}"],
                ['text' => '❌ Tolak', 'callback_data' => "apv:no:{$type}:{$record->id}"],
            ];
        }

        $rows[] = array_values(array_filter([
            $page > 0 ? ['text' => '⬅️ Sebelumnya', 'callback_data' => 'apv:list:' . ($page - 1)] : null,
            $page < $pages - 1 ? ['text' => 'Berikutnya ➡️', 'callback_data' => 'apv:list:' . ($page + 1)] : null,
        ])) ?: null;

        $this->show($chatId, $messageId, implode("\n", $lines), array_values(array_filter($rows)));
    }

    private function approve(int|string $chatId, int $messageId, User $user, string $type, int $id): void
    {
        try {
            $record = $this->decisions->approve($type, $id, $user);
        } catch (DecisionException $e) {
            $this->telegram->removeButtons($chatId, $messageId);
            $this->telegram->sendMessage($chatId, '⚠️ ' . e($e->getMessage()));

            return;
        }

        $this->telegram->editMessage($chatId, $messageId, '✅ ' . $this->title($record) . ' · ' . e($this->name($record->user))
            . " disetujui oleh {$this->name($user)} (" . now()->locale('id')->translatedFormat('j M H:i') . ").\nPengaju sudah diberi tahu; tinggal " . ($type === 'expense' ? 'dibayarkan.' : 'dicairkan.'));
    }

    private function askReason(int|string $chatId, int $messageId, string $type, int $id): void
    {
        $record = ($type === 'expense' ? Expense::class : Advance::class)::find($id);

        if (! $record || $record->status !== 'Pending') {
            $this->telegram->removeButtons($chatId, $messageId);
            $this->telegram->sendMessage($chatId, '⚠️ Pengajuan ini sudah diproses' . ($record ? ": {$record->status}." : '.'));

            return;
        }

        Cache::put($this->key($chatId), ['type' => $type, 'id' => $id, 'message_id' => $messageId], now()->addMinutes(self::TTL_MINUTES));

        $this->telegram->sendMessage(
            $chatId,
            '❌ Kenapa <b>' . $this->title($record) . '</b> dari ' . e($this->name($record->user)) . " ditolak?\nKetik alasannya; pengaju akan menerimanya.",
            [[['text' => 'Batal', 'callback_data' => 'apv:x']]]
        );
    }

    private function cancelReason(int|string $chatId, int $messageId): void
    {
        $this->forget($chatId);
        $this->telegram->editMessage($chatId, $messageId, 'Penolakan dibatalkan. Pengajuannya tetap menunggu persetujuan.');
    }

    // =================================================================
    // Verifikasi realisasi
    // =================================================================

    /**
     * /verifikasi: advance yang realisasinya menunggu diverifikasi Finance.
     */
    public function verificationList(int|string $chatId, int $page = 0, ?int $messageId = null): void
    {
        $advances = Advance::with('user')->where('status', Advance::STATUS_REALIZED_USER)->orderBy('updated_at')->get();

        if ($advances->isEmpty()) {
            $this->show($chatId, $messageId, '✅ Tidak ada realisasi yang menunggu verifikasi.');

            return;
        }

        $pages = (int) ceil($advances->count() / self::PAGE);
        $page  = min(max($page, 0), $pages - 1);
        $lines = ["🧾 <b>Menunggu verifikasi</b> · {$advances->count()} advance" . ($pages > 1 ? ' · halaman ' . ($page + 1) . " dari {$pages}" : ''), ''];
        $rows  = [];

        foreach ($advances->slice($page * self::PAGE, self::PAGE) as $advance) {
            $evaluation = $this->realisasi->evaluateStatus($advance);
            $icon       = $this->statusIcon($advance, $evaluation);
            $percent    = (float) $advance->paid > 0 ? round($evaluation['total_realisasi'] / (float) $advance->paid * 100) : 0;

            $lines[] = "{$icon} <b>#{$advance->id}</b> · " . e($this->name($advance->user)) . " · {$percent}% · " . e(Text::short((string) $advance->keterangan_advance, 50));
            $rows[]  = [['text' => "{$icon} #{$advance->id} · " . Text::short($this->name($advance->user), 14) . " · {$percent}%", 'callback_data' => "rlz:open:{$advance->id}"]];
        }

        $lines[] = '';
        $lines[] = '✅ siap diselesaikan · ⚠️ kelebihan perlu disetujui · ⏳ masih ada sisa';

        $rows[] = array_values(array_filter([
            $page > 0 ? ['text' => '⬅️ Sebelumnya', 'callback_data' => 'rlz:list:' . ($page - 1)] : null,
            $page < $pages - 1 ? ['text' => 'Berikutnya ➡️', 'callback_data' => 'rlz:list:' . ($page + 1)] : null,
        ])) ?: null;

        $this->show($chatId, $messageId, implode("\n", $lines), array_values(array_filter($rows)));
    }

    private function showAdvance(int|string $chatId, int $id, ?int $messageId): void
    {
        $advance = Advance::with(['user', 'project.deal', 'paymentAccount', 'realizasis.items'])->find($id);

        if (! $advance) {
            $this->show($chatId, $messageId, 'Advance tidak ditemukan.');

            return;
        }

        $evaluation = $this->realisasi->evaluateStatus($advance);
        $lines      = [
            "🧾 <b>Advance #{$advance->id}</b> · " . e((string) $advance->getStatusLabel()),
            '👤 ' . e($this->name($advance->user)) . ' · 📁 ' . e(Text::short(ClientLabel::project($advance->project), 60)),
            '🗒 ' . e(Text::short((string) $advance->keterangan_advance, 150)),
            '',
            'Cair ' . $this->rupiah($advance->paid) . ($advance->paymentAccount ? ' dari ' . e($advance->paymentAccount->name) : '') . ' · terealisasi <b>' . $this->rupiah($evaluation['total_realisasi']) . '</b>',
            $this->statusIcon($advance, $evaluation) . ' ' . e($evaluation['message']) . ($advance->overrun_approved_by ? ' (kelebihan sudah disetujui)' : ''),
            '',
            '<b>Batch realisasi</b>',
        ];

        foreach ($advance->realizasis->sortBy('batch_number')->take(10) as $batch) {
            $lines[] = "• #{$batch->batch_number} · " . Carbon::parse($batch->created_at)->locale('id')->translatedFormat('j M')
                . ' · ' . $batch->items->count() . ' item · ' . $this->rupiah($batch->items->sum('subtotal'))
                . ($batch->items->first() ? ' · ' . e(Text::short((string) $batch->items->first()->name, 30)) : '');
        }

        if ($advance->realizasis->isEmpty()) {
            $lines[] = 'Belum ada.';
        }

        $rows = [];

        if ($advance->status !== Advance::STATUS_SELESAI) {
            if ($evaluation['status'] === 'over_run' && ! $advance->overrun_approved_by) {
                $rows[] = [['text' => '✅ Setujui kelebihan ' . $this->rupiah($evaluation['overrun']), 'callback_data' => "rlz:over:{$advance->id}"]];
            }

            if ($this->realisasi->isReadyToClose($advance)) {
                $rows[] = [['text' => '🏁 Selesaikan advance', 'callback_data' => "rlz:fin:{$advance->id}"]];
            }

            if ($evaluation['status'] === 'under_run' && $advance->realizasis->isNotEmpty()) {
                $rows[] = [['text' => '💰 Sisa sudah dikembalikan → selesaikan', 'callback_data' => "rlz:ret:{$advance->id}"]];
            }
        }

        $rows[] = [['text' => '📋 Daftar verifikasi', 'callback_data' => 'rlz:list:0']];
        $link   = url("/admin/advances/{$advance->id}/realisasi");

        if (str_starts_with($link, 'https://')) {
            $rows[] = [['text' => 'Lihat di ERP', 'url' => $link]];
        }

        $this->show($chatId, $messageId, implode("\n", $lines), $rows);
    }

    private function confirmReturn(int|string $chatId, int $messageId, int $id): void
    {
        $advance = Advance::with('paymentAccount')->find($id);

        if (! $advance) {
            return;
        }

        $remaining = $this->realisasi->getRemainingAmount($advance);

        $this->show($chatId, $messageId, "💰 <b>Catat pengembalian sisa advance #{$advance->id}</b>\n\n"
            . 'Sisa ' . $this->rupiah($remaining) . ' dari ' . e($this->name($advance->user)) . ' dicatat sebagai uang masuk ke rekening '
            . e($advance->paymentAccount?->name ?? '-') . ", lalu advance diselesaikan.\n\nPastikan uangnya sudah benar-benar masuk.", [
                [['text' => '✅ Ya, sudah masuk', 'callback_data' => "rlz:retok:{$advance->id}"]],
                [['text' => '◀️ Kembali', 'callback_data' => "rlz:open:{$advance->id}"]],
            ]);
    }

    private function act(int|string $chatId, int $messageId, int $id, string $action): void
    {
        $advance = Advance::find($id);

        if (! $advance) {
            return;
        }

        try {
            match ($action) {
                'over'   => $this->realisasi->approveOverrun($advance),
                'finish' => $this->realisasi->finishAdvance($advance),
                'return' => $this->realisasi->processReturn($advance),
            };
        } catch (InvalidArgumentException $e) {
            $this->telegram->sendMessage($chatId, '⚠️ ' . e($e->getMessage()));

            return;
        }

        $this->telegram->sendMessage($chatId, match ($action) {
            'over'   => "✅ Kelebihan advance #{$id} disetujui. Realisasi berikutnya tidak tertahan lagi, dan advance bisa diselesaikan.",
            'finish' => "🏁 Advance #{$id} selesai. Pengaju sudah diberi tahu.",
            'return' => "💰 Pengembalian sisa advance #{$id} dicatat dan advance selesai.",
        });

        $this->showAdvance($chatId, $id, $messageId);
    }

    // =================================================================
    // Alat bantu
    // =================================================================

    private function statusIcon(Advance $advance, array $evaluation): string
    {
        return match (true) {
            $this->realisasi->isReadyToClose($advance) => '✅',
            $evaluation['status'] === 'over_run'      => '⚠️',
            default                                   => '⏳',
        };
    }

    private function title(Expense|Advance $record): string
    {
        return ($record instanceof Expense ? 'Expense' : 'Advance') . " #{$record->id} · " . $this->rupiah($record->amount);
    }

    private function age(mixed $date): string
    {
        $days = (int) Carbon::parse($date)->startOfDay()->diffInDays(today());

        return $days === 0 ? 'hari ini' : "{$days} hari lalu";
    }

    private function name(?User $user): string
    {
        return $user ? (trim("{$user->firstname} {$user->lastname}") ?: (string) $user->username) : '-';
    }

    private function rupiah(float|string|null $amount): string
    {
        return 'Rp ' . number_format((float) $amount, 0, ',', '.');
    }

    private function show(int|string $chatId, ?int $messageId, string $html, array $buttons = []): void
    {
        if ($messageId && $this->telegram->editMessage($chatId, $messageId, $html, $buttons)) {
            return;
        }

        $this->telegram->sendMessage($chatId, $html, $buttons);
    }

    private function key(int|string $chatId): string
    {
        return "telegram:finance-approval:{$chatId}";
    }
}
