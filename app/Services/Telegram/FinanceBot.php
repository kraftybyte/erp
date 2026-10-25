<?php

namespace App\Services\Telegram;

use App\Models\Advance;
use App\Models\Expense;
use App\Models\User;
use App\Services\FinanceOverview;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Bot laporan keuangan: ringkasan advance, expense, dan invoice sepanjang
 * waktu. Bot terpisah dari bot pengajuan (token & webhook sendiri), hanya
 * membaca data, dan hanya melayani role Director, Finance, dan super_admin —
 * diperiksa ulang di setiap pesan maupun tombol.
 */
class FinanceBot
{
    public const ROLES = ['Director', 'Finance', 'super_admin'];

    private const SECTIONS = ['ringkasan', 'expense', 'advance', 'invoice', 'piutang', 'pending'];

    private const LIST_LIMIT = 10;

    private TelegramClient $telegram;

    public function __construct(private FinanceOverview $overview)
    {
        $this->telegram = TelegramClient::forFinance();
    }

    public function handle(array $update): void
    {
        // Telegram mengirim ulang update bila webhook lambat atau gagal.
        if (isset($update['update_id']) && ! Cache::add("telegram:finance-update:{$update['update_id']}", true, now()->addDay())) {
            return;
        }

        $chat = data_get($update, 'message.chat') ?? data_get($update, 'callback_query.message.chat');

        // Angka keuangan tidak boleh tampil di grup.
        if (! isset($chat['id']) || ($chat['type'] ?? null) !== 'private') {
            return;
        }

        $user = $this->userFor($chat['id']);

        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query'], $user);
        } elseif (isset($update['message'])) {
            $this->handleMessage($update['message'], $user);
        }
    }

    private function handleMessage(array $message, ?User $user): void
    {
        $chatId = $message['chat']['id'];

        if ($refusal = $this->refusal($chatId, $user)) {
            $this->telegram->sendMessage($chatId, $refusal);

            return;
        }

        preg_match('/^\/(\w+)/u', trim((string) ($message['text'] ?? '')), $match);
        $command = Str::lower($match[1] ?? 'ringkasan');

        if (in_array($command, ['bantuan', 'help'], true)) {
            $this->telegram->sendMessage($chatId, $this->helpText());

            return;
        }

        // /start dan teks bebas: tampilkan ringkasan.
        $section = in_array($command, self::SECTIONS, true) ? $command : 'ringkasan';

        $this->telegram->sendMessage($chatId, $this->render($section), $this->buttons($section));
    }

    private function handleCallback(array $callback, ?User $user): void
    {
        $chatId    = $callback['message']['chat']['id'];
        $messageId = $callback['message']['message_id'];

        if ($refusal = $this->refusal($chatId, $user)) {
            $this->telegram->answerCallback($callback['id'], 'Tidak punya akses.');
            $this->telegram->removeButtons($chatId, $messageId);

            return;
        }

        $this->telegram->answerCallback($callback['id']);

        $section = Str::after((string) ($callback['data'] ?? ''), 'fin:');
        $section = in_array($section, self::SECTIONS, true) ? $section : 'ringkasan';

        if (! $this->telegram->editMessage($chatId, $messageId, $this->render($section), $this->buttons($section))) {
            $this->telegram->sendMessage($chatId, $this->render($section), $this->buttons($section));
        }
    }

    /**
     * Pesan penolakan, atau null bila boleh melihat laporan.
     */
    private function refusal(int|string $chatId, ?User $user): ?string
    {
        if (! $user) {
            return "Akun Telegram ini belum terhubung ke ERP.\n\nBuka ERP → <b>Profil</b> → <b>Hubungkan Telegram</b>, lalu tekan <b>Start</b> di bot pengajuan. Setelah itu bot ini otomatis mengenali akunmu.";
        }

        if (! $user->hasRole(self::ROLES)) {
            return 'Bot ini khusus untuk Finance dan Director.';
        }

        return null;
    }

    private function userFor(int|string $chatId): ?User
    {
        // ID chat pribadi sama dengan ID akun Telegram, jadi sama di semua bot:
        // akun yang dihubungkan lewat bot pengajuan langsung dikenali di sini.
        return User::where('telegram_chat_id', (string) $chatId)->where('is_active', true)->first();
    }

    // =================================================================
    // Tampilan
    // =================================================================

    private function render(string $section): string
    {
        $body = match ($section) {
            'expense' => $this->expenseSection(),
            'advance' => $this->advanceSection(),
            'invoice' => $this->invoiceSection(),
            'piutang' => $this->receivablesSection(),
            'pending' => $this->pendingSection(),
            default   => $this->summarySection(),
        };

        return $body . "\n\n<i>Seluruh waktu · per " . now()->locale('id')->translatedFormat('j M Y, H.i') . '</i>';
    }

    private function buttons(string $current): array
    {
        $labels = [
            'ringkasan' => '📊 Ringkasan',
            'advance'   => '💸 Advance',
            'expense'   => '💰 Expense',
            'invoice'   => '📄 Invoice',
            'piutang'   => '⏰ Piutang',
            'pending'   => '⏳ Pending',
        ];

        $button = fn (string $section) => [
            'text'          => ($section === $current ? '• ' : '') . $labels[$section],
            'callback_data' => "fin:{$section}",
        ];

        return [
            [$button('ringkasan'), $button('advance'), $button('expense')],
            [$button('invoice'), $button('piutang'), $button('pending')],
        ];
    }

    private function summarySection(): string
    {
        $advance = $this->overview->advances();
        $expense = $this->overview->expenses();
        $invoice = $this->overview->invoices();

        return implode("\n", [
            '📊 <b>Ringkasan keuangan</b>',
            '',
            '💸 <b>Advance</b>',
            'Dicairkan: ' . $this->amount($advance['disbursed']),
            'Direalisasi: ' . $this->rupiah($advance['realized']),
            'Belum dipertanggungjawabkan: ' . $this->amount($advance['outstanding'], 'advance'),
            'Menunggu proses: ' . $this->amount($this->combine($advance['pending'], $advance['approved'])),
            '',
            '💰 <b>Expense</b>',
            'Sudah dibayar: ' . $this->amount($expense['paid']),
            'Menunggu proses: ' . $this->amount($this->combine($expense['pending'], $expense['approved'])),
            '',
            '📄 <b>Invoice</b>',
            'Ditagihkan: ' . $this->amount($invoice['billed'], 'invoice'),
            'Sudah dibayar: ' . $this->rupiah($invoice['paid']['amount']),
            'Piutang: ' . $this->amount($invoice['outstanding'], 'invoice')
                . ($invoice['overdue']['count'] ? " · <b>{$invoice['overdue']['count']} lewat jatuh tempo</b>" : ''),
        ]);
    }

    private function expenseSection(): string
    {
        $e = $this->overview->expenses();

        return implode("\n", array_filter([
            '💰 <b>Expense</b>',
            '',
            'Total diajukan: ' . $this->amount($e['total']),
            '• Sudah dibayar: ' . $this->amount($e['paid']),
            '• Disetujui, belum dibayar: ' . $this->amount($e['approved']),
            '• Menunggu persetujuan: ' . $this->amount($e['pending']),
            $e['other']['count'] ? '• Status lain: ' . $this->amount($e['other']) : null,
            '',
            'Ditolak (tidak dihitung): ' . $this->amount($e['rejected']),
        ], fn ($line) => $line !== null));
    }

    private function advanceSection(): string
    {
        $a     = $this->overview->advances();
        $lines = [
            '💸 <b>Advance</b>',
            '',
            'Total diajukan: ' . $this->amount($a['total']),
            '• Sudah dicairkan: ' . $this->amount($a['disbursed']),
            '• Disetujui, belum dicairkan: ' . $this->amount($a['approved']),
            '• Menunggu persetujuan: ' . $this->amount($a['pending']),
        ];

        if ($a['other']['count']) {
            $lines[] = '• Status lain: ' . $this->amount($a['other']);
        }

        $lines = [
            ...$lines,
            '',
            '<b>Pertanggungjawaban</b>',
            'Direalisasi: ' . $this->rupiah($a['realized']),
            'Sisa dikembalikan: ' . $this->rupiah($a['returned']),
            'Belum dipertanggungjawabkan: ' . $this->amount($a['outstanding'], 'advance'),
        ];

        if ($a['overrun']['count']) {
            $lines[] = '⚠️ Melebihi dana: ' . $this->amount($a['overrun'], 'advance') . ' — menunggu persetujuan Finance';
        }

        $lines[] = '';
        $lines[] = 'Ditolak (tidak dihitung): ' . $this->amount($a['rejected']);

        $open = $this->overview->openAdvances()->where('outstanding', '>', 0)->take(self::LIST_LIMIT);

        if ($open->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '<b>Belum dipertanggungjawabkan terbesar</b>';

            foreach ($open as $row) {
                $lines[] = '• ' . e($this->name($row['advance']->user)) . ' — ' . $this->rupiah($row['outstanding'])
                    . "\n   " . e(Str::limit((string) $row['advance']->keterangan_advance, 50))
                    . ' · cair ' . $this->rupiah($row['disbursed']);
            }
        }

        return implode("\n", $lines);
    }

    private function invoiceSection(): string
    {
        $i = $this->overview->invoices();

        return implode("\n", [
            '📄 <b>Invoice</b>',
            '',
            'Ditagihkan: ' . $this->amount($i['billed'], 'invoice'),
            'Sudah dibayar: ' . $this->rupiah($i['paid']['amount']) . " ({$i['paid']['count']} lunas)",
            'Piutang: ' . $this->amount($i['outstanding'], 'invoice'),
            '• Lewat jatuh tempo: ' . $this->amount($i['overdue'], 'invoice'),
            '',
            'Draft (belum ditagihkan): ' . $this->amount($i['draft'], 'invoice'),
            "Dibatalkan: {$i['cancelled']['count']} invoice",
            '',
            '<i>Invoice DP dihitung sebesar DP-nya; sisanya lewat invoice pelunasan. Proforma tidak dihitung.</i>',
        ]);
    }

    private function receivablesSection(): string
    {
        $rows = $this->overview->receivables();

        if ($rows->isEmpty()) {
            return "⏰ <b>Piutang</b>\n\nTidak ada invoice yang belum lunas. 🎉";
        }

        $lines = ['⏰ <b>Piutang</b> — ' . $rows->count() . ' invoice · ' . $this->rupiah($rows->sum('outstanding')), ''];

        foreach ($rows->take(self::LIST_LIMIT) as $row) {
            $invoice = $row['invoice'];
            $dueDate = $invoice->due_date ? Carbon::parse($invoice->due_date)->locale('id')->translatedFormat('j M Y') : 'tanpa jatuh tempo';

            $lines[] = ($row['overdue_days'] > 0 ? '🔴' : '🟡') . ' <b>' . e((string) $invoice->nama_perusahaan) . '</b> — ' . $this->rupiah($row['outstanding'])
                . "\n   " . e((string) $invoice->uuid) . ' · jatuh tempo ' . $dueDate
                . ($row['overdue_days'] > 0 ? " (lewat {$row['overdue_days']} hari)" : '');
        }

        if ($rows->count() > self::LIST_LIMIT) {
            $lines[] = '';
            $lines[] = '…dan ' . ($rows->count() - self::LIST_LIMIT) . ' invoice lain. Lihat lengkapnya di ERP.';
        }

        return implode("\n", $lines);
    }

    private function pendingSection(): string
    {
        $pending = $this->overview->pending();
        $lines   = ['⏳ <b>Menunggu proses</b>'];

        foreach (['advance' => '💸 Advance', 'expense' => '💰 Expense'] as $type => $label) {
            /** @var \Illuminate\Support\Collection<int, Advance|Expense> $items */
            $items = $pending[$type];

            $lines[] = '';
            $lines[] = "<b>{$label}</b> — {$items->count()} pengajuan · " . $this->rupiah($items->sum('amount'));

            foreach ($items->take(self::LIST_LIMIT) as $item) {
                $description = $type === 'advance' ? $item->keterangan_advance : $item->keterangan_expense;
                $waiting     = $item->status === 'Disetujui' ? 'perlu dibayar' : 'perlu persetujuan';

                $lines[] = '• ' . e($this->name($item->user)) . ' — ' . $this->rupiah($item->amount) . " · <i>{$waiting}</i>"
                    . "\n   " . e(Str::limit((string) $description, 50))
                    . ' · ' . $item->created_at?->locale('id')->diffForHumans();
            }
        }

        return implode("\n", $lines);
    }

    private function helpText(): string
    {
        return <<<'HTML'
        📊 <b>Bot laporan keuangan</b> (hanya membaca, tidak mengubah data)

        /ringkasan – advance, expense, dan invoice sekaligus
        /advance – dicairkan, realisasi, belum dipertanggungjawabkan
        /expense – dibayar, menunggu, ditolak
        /invoice – ditagihkan, dibayar, piutang
        /piutang – invoice yang belum lunas
        /pending – pengajuan yang menunggu persetujuan atau pembayaran
        HTML;
    }

    // =================================================================
    // Format
    // =================================================================

    private function amount(array $bucket, string $unit = 'pengajuan'): string
    {
        return '<b>' . $this->rupiah($bucket['amount']) . "</b> ({$bucket['count']} {$unit})";
    }

    private function combine(array ...$buckets): array
    {
        return [
            'count'  => array_sum(array_column($buckets, 'count')),
            'amount' => array_sum(array_column($buckets, 'amount')),
        ];
    }

    private function rupiah(int|float|string|null $amount): string
    {
        return 'Rp ' . number_format((float) $amount, 0, ',', '.');
    }

    private function name(?User $user): string
    {
        return $user ? (trim("{$user->firstname} {$user->lastname}") ?: (string) $user->username) : '-';
    }
}
