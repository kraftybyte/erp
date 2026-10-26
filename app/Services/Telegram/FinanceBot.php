<?php

namespace App\Services\Telegram;

use App\Models\Advance;
use App\Models\Expense;
use App\Models\User;
use App\Services\FinanceExport;
use App\Services\FinanceOverview;
use App\Services\InvoicePayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Bot laporan keuangan: ringkasan advance, expense, dan invoice sepanjang
 * waktu. Bot terpisah dari bot pengajuan (token & webhook sendiri) dan hanya
 * melayani role Director, Finance, dan super_admin — diperiksa ulang di setiap
 * pesan maupun tombol. Satu-satunya yang mengubah data: mencatat pembayaran
 * invoice (/bayar, lihat InvoicePaymentFlow).
 */
class FinanceBot
{
    public const ROLES = ['Director', 'Finance', 'super_admin'];

    private const SECTIONS = ['ringkasan', 'expense', 'advance', 'invoice', 'piutang', 'pending'];

    private const LIST_LIMIT = 10;

    private TelegramClient $telegram;

    private InvoicePaymentFlow $payment;

    public function __construct(private FinanceOverview $overview, private FinanceExport $export, InvoicePayment $payments)
    {
        $this->telegram = TelegramClient::forFinance();
        $this->payment  = new InvoicePaymentFlow($this->telegram, $payments);
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

        $text = trim((string) ($message['text'] ?? ''));
        preg_match('/^\/(\w+)/u', $text, $match);
        $command = Str::lower($match[1] ?? 'ringkasan');

        if ($command === 'bayar') {
            $this->payment->start($chatId, trim(Str::after($text, $match[0])));

            return;
        }

        // Teks biasa di tengah pencatatan pembayaran: kata kunci atau nominal.
        if (! $match && $this->payment->awaitingText($chatId)) {
            $this->payment->handleText($chatId, $text);

            return;
        }

        // Perintah lain menutup pencatatan pembayaran yang belum selesai.
        $this->payment->forget($chatId);

        if (in_array($command, ['bantuan', 'help'], true)) {
            $this->telegram->sendMessage($chatId, $this->helpText());

            return;
        }

        if ($command === 'excel') {
            $this->sendExport($chatId);

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

        if (str_starts_with((string) ($callback['data'] ?? ''), 'pay:')) {
            $this->payment->handleCallback($callback, $user);

            return;
        }

        $section = Str::after((string) ($callback['data'] ?? ''), 'fin:');

        if ($section === 'excel') {
            $this->telegram->answerCallback($callback['id'], 'Menyiapkan file Excel…');
            $this->sendExport($chatId);

            return;
        }

        $this->telegram->answerCallback($callback['id']);

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
            'piutang'   => '⏰ Belum dibayar',
            'pending'   => '⏳ Pending',
        ];

        $button = fn (string $section) => [
            'text'          => ($section === $current ? '• ' : '') . $labels[$section],
            'callback_data' => "fin:{$section}",
        ];

        return [
            [$button('ringkasan'), $button('advance'), $button('expense')],
            [$button('invoice'), $button('piutang'), $button('pending')],
            [['text' => '💳 Catat pembayaran invoice', 'callback_data' => 'pay:list']],
            [['text' => '⬇️ Unduh Excel (semua data)', 'callback_data' => 'fin:excel']],
        ];
    }

    private function summarySection(): string
    {
        $advance = $this->overview->advances();
        $expense = $this->overview->expenses();
        $invoice = $this->overview->invoiceBreakdown();

        return implode("\n", [
            '📊 <b>Ringkasan keuangan</b>',
            '',
            '📄 <b>Invoice</b>',
            'Nilai pekerjaan: <b>' . $this->rupiah($invoice['contract_value']) . '</b>',
            'Sudah dibayar: ' . $this->rupiah($invoice['paid']),
            'Belum dibayar: <b>' . $this->rupiah($invoice['unpaid_billed']['amount'] + $invoice['unbilled']['amount']) . '</b>',
            '• sudah ditagih: ' . $this->rupiah($invoice['unpaid_billed']['amount'])
                . ($invoice['overdue']['count'] ? " ({$invoice['overdue']['count']} lewat jatuh tempo)" : ''),
            '• belum ditagih: ' . $this->rupiah($invoice['unbilled']['amount']),
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
        ]);
    }

    private function expenseSection(): string
    {
        $e     = $this->overview->expenses();
        $lines = [
            '💰 <b>Expense</b>',
            '',
            'Total diajukan: ' . $this->amount($e['total']),
            '• Sudah dibayar: ' . $this->amount($e['paid']),
            '• Disetujui, belum dibayar: ' . $this->amount($e['approved']),
            '• Menunggu persetujuan: ' . $this->amount($e['pending']),
        ];

        if ($e['other']['count']) {
            $lines[] = '• Status lain: ' . $this->amount($e['other']);
        }

        $lines[] = 'Ditolak (tidak dihitung): ' . $this->amount($e['rejected']);

        $lines = [...$lines, '', '<b>Untuk apa saja</b> (per kategori)'];
        foreach ($this->overview->expenseByCategory() as $row) {
            $lines[] = '• ' . e($row['label']) . ': ' . $this->rupiah($row['amount']) . " ({$row['count']})";
        }

        return implode("\n", [...$lines, '', '<b>Per project</b>', ...$this->topList($this->overview->expenseByProject(), fn (array $row) => $this->rupiah($row['amount']) . " ({$row['count']})")]);
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
            'Ditolak (tidak dihitung): ' . $this->amount($a['rejected']),
            '',
            'Direalisasi: ' . $this->rupiah($a['realized']) . ' · dikembalikan: ' . $this->rupiah($a['returned']),
            'Belum dipertanggungjawabkan: ' . $this->amount($a['outstanding'], 'advance'),
        ];

        if ($a['overrun']['count']) {
            $lines[] = '⚠️ Melebihi dana: ' . $this->amount($a['overrun'], 'advance') . ' — menunggu persetujuan Finance';
        }

        $lines = [...$lines, '', '<b>Per project</b>'];

        return implode("\n", [...$lines, ...$this->topList(
            $this->overview->advanceByProject(),
            fn (array $row) => $this->rupiah($row['amount']) . " ({$row['count']})"
                . "\n   cair " . $this->rupiah($row['disbursed']) . ' · realisasi ' . $this->rupiah($row['realized'])
                . ($row['outstanding'] > 0 ? ' · <b>sisa ' . $this->rupiah($row['outstanding']) . '</b>' : '')
        )]);
    }

    private function invoiceSection(): string
    {
        $i     = $this->overview->invoiceBreakdown();
        $all   = $this->overview->invoices();
        $kinds = ['dp' => 'DP', 'final' => 'Pelunasan', 'full' => 'Bayar penuh'];

        $lines = [
            '📄 <b>Invoice</b> (penjualan)',
            '',
            'Nilai pekerjaan: <b>' . $this->rupiah($i['contract_value']) . '</b>',
            'Sudah dibayar: ' . $this->rupiah($i['paid']),
            'Belum dibayar: <b>' . $this->rupiah($i['unpaid_billed']['amount'] + $i['unbilled']['amount']) . '</b>',
            '• sudah ditagih: ' . $this->amount($i['unpaid_billed'], 'invoice'),
            '   lewat jatuh tempo: ' . $this->amount($i['overdue'], 'invoice'),
            '• belum ditagih (DP tanpa pelunasan): ' . $this->amount($i['unbilled'], 'project'),
            '',
            '<b>Per jenis</b>',
        ];

        foreach ($kinds as $kind => $label) {
            $k = $i['kinds'][$kind];

            if ($k['count']) {
                $lines[] = "<b>{$label}</b> ({$k['count']}): ditagih " . $this->rupiah($k['billed'])
                    . ' · dibayar ' . $this->rupiah($k['paid'])
                    . ($k['unpaid'] > 0 ? ' · sisa ' . $this->rupiah($k['unpaid']) : ' · lunas');
            }
        }

        $lines[] = '';
        $lines[] = "Draft: {$all['draft']['count']} · Dibatalkan: {$all['cancelled']['count']} (tidak dihitung)";

        if ($i['unlinked_finals']) {
            $lines[] = "⚠️ {$i['unlinked_finals']} invoice pelunasan tidak bisa dipasangkan ke invoice DP-nya, jadi nilai pekerjaan bisa kurang akurat. Isi invoice DP-nya di ERP.";
        }

        $lines[] = '';
        $lines[] = '<i>Invoice DP ditagih sebesar DP-nya; sisanya lewat invoice pelunasan. Proforma tidak dihitung.</i>';

        return implode("\n", $lines);
    }

    private function receivablesSection(): string
    {
        $unpaid   = $this->overview->receivables();
        $unbilled = $this->overview->unbilled();
        $kinds    = ['dp' => 'DP', 'final' => 'Pelunasan', 'full' => 'Bayar penuh'];

        if ($unpaid->isEmpty() && $unbilled->isEmpty()) {
            return "⏰ <b>Belum dibayar</b>\n\nSemua invoice sudah lunas dan tertagih. 🎉";
        }

        $lines = ['⏰ <b>Belum dibayar</b>', ''];

        if ($unpaid->isNotEmpty()) {
            $lines[] = '<b>Sudah ditagih</b> — ' . $unpaid->count() . ' invoice · ' . $this->rupiah($unpaid->sum('outstanding'));

            foreach ($unpaid->take(self::LIST_LIMIT) as $row) {
                $invoice = $row['invoice'];
                $kind    = $kinds[$row['kind']] . ($row['kind'] === 'dp' ? ' ' . $invoice->dpPercentage() . '%' : '');
                $dueDate = $invoice->due_date ? Carbon::parse($invoice->due_date)->locale('id')->translatedFormat('j M Y') : 'tanpa jatuh tempo';

                $lines[] = ($row['overdue_days'] > 0 ? '🔴' : '🟡') . ' <b>' . e((string) $invoice->nama_perusahaan) . '</b> — sisa ' . $this->rupiah($row['outstanding'])
                    . "\n   {$kind} · ditagih " . $this->rupiah($row['due']) . ' · dibayar ' . $this->rupiah($row['paid'])
                    . "\n   " . e((string) $invoice->uuid) . ' · jatuh tempo ' . $dueDate
                    . ($row['overdue_days'] > 0 ? " (lewat {$row['overdue_days']} hari)" : '');
            }

            if ($unpaid->count() > self::LIST_LIMIT) {
                $lines[] = '…dan ' . ($unpaid->count() - self::LIST_LIMIT) . ' invoice lain (lihat file Excel).';
            }
        }

        if ($unbilled->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '<b>Belum ditagih</b> (DP sudah, pelunasan belum dibuat) — ' . $this->rupiah($unbilled->sum('unbilled'));

            foreach ($unbilled->take(self::LIST_LIMIT) as $row) {
                $invoice = $row['invoice'];

                $lines[] = '📝 <b>' . e((string) $invoice->nama_perusahaan) . '</b> — ' . $this->rupiah($row['unbilled'])
                    . "\n   nilai " . $this->rupiah($invoice->grand_total) . ' · DP ' . $invoice->dpPercentage() . '% ' . $this->rupiah($row['due'])
                    . ' · ' . e((string) $invoice->uuid);
            }

            if ($unbilled->count() > self::LIST_LIMIT) {
                $lines[] = '…dan ' . ($unbilled->count() - self::LIST_LIMIT) . ' lainnya (lihat file Excel).';
            }
        }

        $lines[] = '';
        $lines[] = '<i>Ada pembayaran masuk? Ketik /bayar untuk mencatatnya.</i>';

        return implode("\n", $lines);
    }

    /**
     * Daftar teratas untuk chat; selengkapnya di file Excel.
     *
     * @return array<int, string>
     */
    private function topList(\Illuminate\Support\Collection $rows, \Closure $describe): array
    {
        if ($rows->isEmpty()) {
            return ['-'];
        }

        $lines = $rows->take(self::LIST_LIMIT)
            ->map(fn (array $row) => '• ' . e($row['label']) . ': ' . $describe($row))
            ->all();

        if ($rows->count() > self::LIST_LIMIT) {
            $lines[] = '…dan ' . ($rows->count() - self::LIST_LIMIT) . ' lainnya (lihat file Excel).';
        }

        return $lines;
    }

    private function sendExport(int|string $chatId): void
    {
        $this->telegram->typing($chatId);

        $path = $this->export->toFile();

        try {
            $sent = $this->telegram->sendDocument(
                $chatId,
                $path,
                'laporan-keuangan-' . now()->format('Y-m-d') . '.xlsx',
                '📎 Seluruh data advance, expense, dan invoice. Sheet: Ringkasan, Invoice, Expense, Advance, Per Project.'
            );
        } finally {
            @unlink($path);
        }

        if (! $sent) {
            $this->telegram->sendMessage($chatId, 'File Excel gagal dikirim. Coba lagi sebentar.');
        }
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
        📊 <b>Bot laporan keuangan</b>

        /ringkasan – invoice, advance, dan expense sekaligus
        /invoice – nilai pekerjaan, DP, pelunasan, dibayar, belum dibayar
        /piutang – daftar yang belum dibayar & belum ditagih
        /advance – per project: dicairkan, realisasi, sisa
        /expense – per kategori dan per project
        /pending – pengajuan yang menunggu persetujuan atau pembayaran
        /excel – unduh seluruh data dalam satu file Excel
        /bayar – catat pembayaran invoice yang masuk (bisa /bayar nama klien)

        Selain /bayar, bot ini hanya membaca data.
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
