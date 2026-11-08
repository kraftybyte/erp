<?php

namespace App\Services\Telegram;

use App\Helpers\ClientLabel;
use App\Models\Advance;
use App\Models\Expense;
use App\Models\Realisasi;
use App\Models\User;
use App\Services\AutoApproval;
use App\Services\RealisasiService;
use App\Services\SubmissionDecision;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Telegram ke Director, Finance, dan super_admin: setiap pengajuan baru
 * (dengan tombol Setujui/Tolak) dan setiap batch realisasi yang masuk,
 * termasuk realisasi lanjutan (dengan tombol verifikasi). Dulu mereka hanya
 * mendapat notifikasi di ERP, sehingga antrean verifikasi menumpuk.
 *
 * Dikirim lewat bot laporan keuangan supaya tombolnya ditangani di sana
 * (hak akses dicek ulang). Bila penerima belum pernah membuka bot itu,
 * Telegram menolak, dan pesannya dikirim lewat bot pengajuan tanpa tombol aksi.
 * Dikirim setelah transaksi database selesai dan setelah respons web.
 */
class ManagementAlerts
{
    public function __construct(private RealisasiService $realisasi) {}

    public function newSubmission(Expense|Advance $record): void
    {
        $type = $record instanceof Expense ? 'expense' : 'advance';
        $id   = $record->getKey();

        $this->afterCommit(fn () => $this->sendSubmission($type, $id));
    }

    public function realisasi(Realisasi $batch): void
    {
        $id = $batch->getKey();

        $this->afterCommit(fn () => $this->sendRealisasi($id));
    }

    // =================================================================
    // Isi pesan
    // =================================================================

    private function sendSubmission(string $type, int $id): void
    {
        $record = ($type === 'expense' ? Expense::class : Advance::class)::with(['user', 'project.deal'])->find($id);

        if (! $record) {
            return;
        }

        $auto    = AutoApproval::wasApplied($record);
        $pending = $record->status === 'Pending';
        $label   = $type === 'expense' ? 'Expense' : 'Advance';
        $what    = $type === 'expense' ? $record->keterangan_expense : $record->keterangan_advance;
        $files   = $type === 'expense' ? ($record->attachment ? 1 : 0) : count($record->attachmentPaths());

        $lines = [
            ($auto ? '✅' : '🆕') . " <b>{$label} baru #{$record->id}</b> · " . $this->rupiah($record->amount),
            '👤 ' . e($this->name($record->user)) . ($record->category ? ' · 🏷 ' . e($record->category) : '') . ' · 🗓 ' . $this->day($record->submission_date ?? $record->created_at),
            '📁 ' . e(ClientLabel::project($record->project)),
            '🗒 ' . e(Text::short((string) $what, 200)),
            $files ? "📎 {$files} lampiran" : '📎 tanpa lampiran',
        ];

        $lines[] = match (true) {
            $auto    => "\nDisetujui otomatis (auto approve), tinggal " . ($type === 'expense' ? 'dibayarkan.' : 'dicairkan.'),
            $pending => "\nSetujui atau tolak langsung dari sini.",
            default  => "\nStatus: " . e((string) $record->status),
        };

        $actions = $pending ? [[
            ['text' => '✅ Setujui', 'callback_data' => "apv:ok:{$type}:{$record->id}"],
            ['text' => '❌ Tolak', 'callback_data' => "apv:no:{$type}:{$record->id}"],
        ]] : [];

        foreach ($this->recipients($record->user_id) as $user) {
            $this->deliver($user, implode("\n", $lines), $actions, url("/admin/{$type}s/{$record->id}"));
        }
    }

    private function sendRealisasi(int $batchId): void
    {
        $batch = Realisasi::with(['items', 'advance.user', 'advance.project.deal'])->find($batchId);

        if (! $batch || ! $batch->advance) {
            return;
        }

        $advance    = $batch->advance;
        $evaluation = $this->realisasi->evaluateStatus($advance);
        $paid       = (float) $advance->paid;
        $percent    = $paid > 0 ? round($evaluation['total_realisasi'] / $paid * 100) : 0;

        $lines = [
            "🧾 <b>Realisasi masuk · Advance #{$advance->id}</b>" . ($batch->batch_number > 1 ? " (batch ke-{$batch->batch_number})" : ''),
            '👤 ' . e($this->name($advance->user)) . ' · 📁 ' . e(Text::short(ClientLabel::project($advance->project), 60)),
            '🗒 ' . e(Text::short((string) $advance->keterangan_advance, 120)),
            '',
            'Batch ini: ' . $batch->items->count() . ' item · <b>' . $this->rupiah($batch->items->sum('subtotal')) . '</b>',
            'Terealisasi ' . $this->rupiah($evaluation['total_realisasi']) . ' dari ' . $this->rupiah($paid) . " ({$percent}%)",
            match ($evaluation['status']) {
                'exact_match' => '✅ Pas dengan dana yang cair: siap diselesaikan.',
                'over_run'    => '⚠️ Melebihi ' . $this->rupiah($evaluation['overrun']) . ($advance->overrun_approved_by ? ' (sudah disetujui).' : ': perlu persetujuan kelebihan.'),
                default       => '⏳ Sisa ' . $this->rupiah($evaluation['remaining']) . '.',
            },
        ];

        $actions = [[['text' => '🔎 Detail & verifikasi', 'callback_data' => "rlz:open:{$advance->id}"]]];

        if ($evaluation['status'] === 'over_run' && ! $advance->overrun_approved_by) {
            $actions[] = [['text' => '✅ Setujui kelebihan', 'callback_data' => "rlz:over:{$advance->id}"]];
        } elseif ($this->realisasi->isReadyToClose($advance)) {
            $actions[] = [['text' => '🏁 Selesaikan advance', 'callback_data' => "rlz:fin:{$advance->id}"]];
        }

        foreach ($this->recipients($batch->uploaded_by) as $user) {
            $this->deliver($user, implode("\n", $lines), $actions, url("/admin/advances/{$advance->id}/realisasi"));
        }
    }

    // =================================================================
    // Pengiriman
    // =================================================================

    /**
     * @return Collection<int, User>
     */
    private function recipients(?int $except): Collection
    {
        return User::role(SubmissionDecision::ROLES)
            ->where('is_active', true)
            ->whereNotNull('telegram_chat_id')
            ->when($except, fn ($query) => $query->whereKeyNot($except))
            ->get();
    }

    private function deliver(User $user, string $html, array $actions, string $url): void
    {
        $link    = str_starts_with($url, 'https://') ? [['text' => 'Lihat di ERP', 'url' => $url]] : null;
        $finance = TelegramClient::forFinance();

        if ($finance->isConfigured() && $finance->sendMessage($user->telegram_chat_id, $html, array_values(array_filter([...$actions, $link])))) {
            return;
        }

        $pengajuan = app(TelegramClient::class);

        if ($pengajuan->isConfigured()) {
            $pengajuan->sendMessage(
                $user->telegram_chat_id,
                $html . ($actions ? "\n\n<i>Buka bot laporan keuangan untuk menyetujui/verifikasi langsung dari Telegram.</i>" : ''),
                $link ? [$link] : []
            );
        }
    }

    private function afterCommit(Closure $send): void
    {
        DB::afterCommit(function () use ($send) {
            // Di request web, kirim setelah respons selesai supaya pengguna tidak menunggu Telegram.
            app()->runningInConsole() ? $send() : app()->terminating($send);
        });
    }

    private function name(?User $user): string
    {
        return $user ? (trim("{$user->firstname} {$user->lastname}") ?: (string) $user->username) : '-';
    }

    private function day(mixed $date): string
    {
        return $date ? Carbon::parse($date)->locale('id')->translatedFormat('j M Y') : '-';
    }

    private function rupiah(float|string|null $amount): string
    {
        return 'Rp ' . number_format((float) $amount, 0, ',', '.');
    }
}
