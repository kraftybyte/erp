<?php

namespace App\Services\Telegram;

use App\Models\Advance;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\User;
use App\Services\FinanceExport;
use App\Services\FinanceOverview;
use App\Services\InvoicePayment;
use App\Services\ReportPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Bot laporan keuangan: invoice masuk, expense, dan advance — seluruh waktu
 * atau per periode yang dipilih (📅 Periode / /periode). Bot terpisah dari bot
 * pengajuan (token & webhook sendiri) dan hanya melayani role Director,
 * Finance, dan super_admin — diperiksa ulang di setiap pesan maupun tombol.
 * Satu-satunya yang mengubah data: mencatat pembayaran invoice (/bayar, lihat
 * InvoicePaymentFlow).
 */
class FinanceBot
{
    public const ROLES = ['Director', 'Finance', 'super_admin'];

    private const SECTIONS = ['ringkasan', 'invoice', 'expense', 'advance', 'piutang', 'pending'];

    private const LIST_LIMIT = 10;

    // Berapa lama pilihan periode setiap pengguna diingat.
    private const PERIOD_DAYS = 90;

    private TelegramClient $telegram;

    private InvoicePaymentFlow $payment;

    // Overview untuk periode chat yang sedang dilayani; diisi render().
    private FinanceOverview $view;

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
        $args    = $match ? trim(Str::after($text, $match[0])) : '';

        if ($command === 'bayar') {
            $this->payment->start($chatId, $args);

            return;
        }

        if ($command === 'periode') {
            $this->payment->forget($chatId);

            $args !== ''
                ? $this->setPeriodFromText($chatId, $args, 'ringkasan')
                : $this->showPeriodPicker($chatId, null, 'ringkasan');

            return;
        }

        // Teks biasa: rentang tanggal yang diminta, atau isian pencatatan pembayaran.
        if (! $match) {
            if ($section = Cache::get($this->periodAwaitKey($chatId))) {
                $this->setPeriodFromText($chatId, $text, $section);

                return;
            }

            if ($this->payment->awaitingText($chatId)) {
                $this->payment->handleText($chatId, $text);

                return;
            }
        }

        // Perintah lain menutup isian yang belum selesai.
        $this->payment->forget($chatId);
        Cache::forget($this->periodAwaitKey($chatId));

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

        $this->telegram->sendMessage($chatId, $this->render($section, $chatId), $this->buttons($section, $chatId));
    }

    private function handleCallback(array $callback, ?User $user): void
    {
        $chatId    = $callback['message']['chat']['id'];
        $messageId = $callback['message']['message_id'];
        $data      = (string) ($callback['data'] ?? '');

        if ($this->refusal($chatId, $user) !== null) {
            $this->telegram->answerCallback($callback['id'], 'Tidak punya akses.');
            $this->telegram->removeButtons($chatId, $messageId);

            return;
        }

        if (str_starts_with($data, 'pay:')) {
            $this->payment->handleCallback($callback, $user);

            return;
        }

        if ($data === 'fin:excel') {
            $this->telegram->answerCallback($callback['id'], 'Menyiapkan file Excel…');
            $this->sendExport($chatId);

            return;
        }

        $this->telegram->answerCallback($callback['id']);

        // per:<preset>:<bagian> — periode dipilih dari tombol.
        if (str_starts_with($data, 'per:')) {
            [, $preset, $section] = array_pad(explode(':', $data, 3), 3, 'ringkasan');

            if ($period = ReportPeriod::preset($preset)) {
                $this->setPeriod($chatId, $period);
            }

            Cache::forget($this->periodAwaitKey($chatId));
            $this->show($chatId, $messageId, $this->section($section));

            return;
        }

        // fin:periode:<bagian> — buka pilihan periode.
        if (str_starts_with($data, 'fin:periode')) {
            $this->showPeriodPicker($chatId, $messageId, $this->section(Str::after($data, 'fin:periode:')));

            return;
        }

        $this->show($chatId, $messageId, $this->section(Str::after($data, 'fin:')));
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
    // Periode
    // =================================================================

    private function periodFor(int|string $chatId): ReportPeriod
    {
        return ReportPeriod::fromArray(Cache::get("telegram:finance-period:{$chatId}"));
    }

    private function setPeriod(int|string $chatId, ReportPeriod $period): void
    {
        Cache::put("telegram:finance-period:{$chatId}", $period->toArray(), now()->addDays(self::PERIOD_DAYS));
    }

    private function periodAwaitKey(int|string $chatId): string
    {
        return "telegram:finance-period-await:{$chatId}";
    }

    /**
     * Pilihan periode. Selama ditampilkan, rentang tanggal juga bisa langsung diketik.
     */
    private function showPeriodPicker(int|string $chatId, ?int $messageId, string $section): void
    {
        Cache::put($this->periodAwaitKey($chatId), $section, now()->addMinutes(30));

        $preset = fn (string $key) => ['text' => ReportPeriod::PRESETS[$key], 'callback_data' => "per:{$key}:{$section}"];

        $text = implode("\n", [
            '📅 <b>Pilih periode</b>',
            'Sekarang: <b>' . e($this->periodFor($chatId)->label()) . '</b>',
            '',
            'Pilih di bawah, atau ketik rentang tanggalnya, mis.:',
            '<code>1/10/2026 - 31/10/2026</code>',
            '<code>1 okt - 15 okt</code>',
            '<code>oktober 2026</code>',
        ]);

        $buttons = [
            [$preset('bulan-ini'), $preset('bulan-lalu')],
            [$preset('tahun-ini'), $preset('tahun-lalu')],
            [$preset('semua')],
            [['text' => '⬅️ Kembali', 'callback_data' => "fin:{$section}"]],
        ];

        if ($messageId === null || ! $this->telegram->editMessage($chatId, $messageId, $text, $buttons)) {
            $this->telegram->sendMessage($chatId, $text, $buttons);
        }
    }

    private function setPeriodFromText(int|string $chatId, string $text, string $section): void
    {
        $period = ReportPeriod::parse($text);

        if (! $period) {
            $this->telegram->sendMessage($chatId, "Tanggal tidak terbaca. Contoh: <code>1/10/2026 - 31/10/2026</code>, <code>1 okt - 15 okt</code>, atau <code>oktober 2026</code>.");

            return;
        }

        $this->setPeriod($chatId, $period);
        Cache::forget($this->periodAwaitKey($chatId));

        $section = $this->section($section);
        $this->telegram->sendMessage($chatId, $this->render($section, $chatId), $this->buttons($section, $chatId));
    }

    // =================================================================
    // Tampilan
    // =================================================================

    private function section(string $section): string
    {
        return in_array($section, self::SECTIONS, true) ? $section : 'ringkasan';
    }

    private function show(int|string $chatId, int $messageId, string $section): void
    {
        $text    = $this->render($section, $chatId);
        $buttons = $this->buttons($section, $chatId);

        if (! $this->telegram->editMessage($chatId, $messageId, $text, $buttons)) {
            $this->telegram->sendMessage($chatId, $text, $buttons);
        }
    }

    private function render(string $section, int|string $chatId): string
    {
        $this->view = $this->overview->forPeriod($this->periodFor($chatId));

        $body = match ($section) {
            'invoice' => $this->invoiceSection(),
            'expense' => $this->expenseSection(),
            'advance' => $this->advanceSection(),
            'piutang' => $this->receivablesSection(),
            'pending' => $this->pendingSection(),
            default   => $this->summarySection(),
        };

        return $body . "\n\n<i>per " . now()->locale('id')->translatedFormat('j M Y, H.i') . '</i>';
    }

    private function buttons(string $current, int|string $chatId): array
    {
        $labels = [
            'ringkasan' => '📊 Ringkasan',
            'invoice'   => '📄 Invoice',
            'expense'   => '💰 Expense',
            'advance'   => '💸 Advance',
            'piutang'   => '⏰ Belum dibayar',
            'pending'   => '⏳ Pending',
        ];

        $button = fn (string $section) => [
            'text'          => ($section === $current ? '• ' : '') . $labels[$section],
            'callback_data' => "fin:{$section}",
        ];

        $period = $this->periodFor($chatId)->label();

        return [
            [['text' => "📅 Periode: {$period}", 'callback_data' => "fin:periode:{$current}"]],
            [$button('ringkasan'), $button('invoice')],
            [$button('expense'), $button('advance')],
            [$button('piutang'), $button('pending')],
            [['text' => '💳 Catat pembayaran invoice', 'callback_data' => 'pay:list']],
            [['text' => "⬇️ Unduh Excel ({$period})", 'callback_data' => 'fin:excel']],
        ];
    }

    private function heading(string $title): array
    {
        return [$title, '📅 <b>' . e($this->view->period()->label()) . '</b>', ''];
    }

    private function summarySection(): string
    {
        $e = $this->view->expenses();
        $a = $this->view->advances();

        $advanceUnpaid = $this->combine($a['pending'], $a['approved']);

        return implode("\n", [
            ...$this->heading('📊 <b>Ringkasan keuangan</b>'),
            ...$this->invoiceTotals(),
            '',
            "💰 <b>Expense</b> — {$e['total']['count']} pengajuan",
            'Total: <b>' . $this->rupiah($e['total']['amount']) . '</b>',
            '',
            "💸 <b>Advance</b> — {$a['total']['count']} pengajuan",
            'Total: <b>' . $this->rupiah($a['total']['amount']) . '</b>',
            'Belum dibayarkan: <b>' . $this->rupiah($advanceUnpaid['amount']) . "</b> ({$advanceUnpaid['count']})",
            'Belum dipertanggungjawabkan: ' . $this->rupiah($a['outstanding']['amount']) . " ({$a['outstanding']['count']})",
            '',
            '<i>Langsung dari tabel invoice, expense, dan advance. Invoice menurut tanggal invoice (proforma & yang dibatalkan tidak dihitung); expense & advance menurut tanggal pengajuan (yang ditolak tidak dihitung).</i>',
        ]);
    }

    /**
     * Invoice masuk pada periode: total per jenis dan yang belum dibayarkan.
     *
     * @return array<int, string>
     */
    private function invoiceTotals(): array
    {
        $t = $this->view->invoiceTotals();

        $line = fn (string $label, array $kind) => "• {$label}: <b>" . $this->rupiah($kind['amount']) . "</b> ({$kind['count']})";

        return array_values(array_filter([
            "📄 <b>Invoice masuk</b> — {$t['total']['count']} invoice",
            $line('Invoice DP', $t['dp']),
            $line('Invoice full payment', $t['full']),
            // Pelunasan hanya ditampilkan bila ada, agar ringkasannya tetap singkat.
            $t['final']['count'] ? $line('Invoice pelunasan', $t['final']) : null,
            'Total: <b>' . $this->rupiah($t['total']['amount']) . '</b>',
            'Belum dibayarkan: <b>' . $this->rupiah($t['unpaid']['amount']) . "</b> ({$t['unpaid']['count']} invoice)",
        ]));
    }

    private function invoiceSection(): string
    {
        $invoices = $this->view->invoiceRows();
        $lines    = [...$this->heading('📄 <b>Invoice</b>'), ...$this->invoiceTotals()];

        if ($invoices->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '<b>Daftar</b> (terbaru dulu)';

            foreach ($invoices->take(self::LIST_LIMIT) as $invoice) {
                $date = $invoice->date ? Carbon::parse($invoice->date)->locale('id')->translatedFormat('j M') : 'tanpa tanggal';

                $lines[] = "• {$date} · <b>" . e((string) $invoice->nama_perusahaan) . '</b> — '
                    . $this->kindLabel($invoice) . ' ' . $this->rupiah($invoice->grand_total);
            }

            if ($invoices->count() > self::LIST_LIMIT) {
                $lines[] = '…dan ' . ($invoices->count() - self::LIST_LIMIT) . ' invoice lain (lihat file Excel).';
            }
        }

        $lines[] = '';
        $lines[] = '<i>Nilai = kolom Total di invoice. Belum dibayarkan = Total − Paid pada invoice yang belum berstatus paid. Proforma dan yang dibatalkan tidak dihitung.</i>';

        return implode("\n", $lines);
    }

    private function expenseSection(): string
    {
        $e = $this->view->expenses();

        $lines = [
            ...$this->heading('💰 <b>Expense</b>'),
            'Total: <b>' . $this->rupiah($e['total']['amount']) . "</b> ({$e['total']['count']} pengajuan)",
        ];

        if ($e['rejected']['count']) {
            $lines[] = '<i>Ditolak, tidak dihitung: ' . $this->rupiah($e['rejected']['amount']) . " ({$e['rejected']['count']})</i>";
        }

        $lines = [...$lines, '', '<b>Per kategori</b>', ...$this->topList(
            $this->view->expenseByCategory(),
            fn (array $row) => $this->rupiah($row['amount']) . " ({$row['count']})"
        )];

        return implode("\n", [...$lines, '', '<b>Per project</b>', ...$this->topList(
            $this->view->expenseByProject(),
            fn (array $row) => $this->rupiah($row['amount']) . " ({$row['count']})"
        )]);
    }

    private function advanceSection(): string
    {
        $a      = $this->view->advances();
        $unpaid = $this->combine($a['pending'], $a['approved']);

        $lines = [
            ...$this->heading('💸 <b>Advance</b>'),
            'Total: <b>' . $this->rupiah($a['total']['amount']) . "</b> ({$a['total']['count']} pengajuan)",
            'Belum dibayarkan: <b>' . $this->rupiah($unpaid['amount']) . "</b> ({$unpaid['count']})",
            'Belum dipertanggungjawabkan: <b>' . $this->rupiah($a['outstanding']['amount']) . "</b> ({$a['outstanding']['count']})",
        ];

        if ($a['overrun']['count']) {
            $lines[] = '⚠️ Melebihi dana: ' . $this->amount($a['overrun'], 'advance') . ' — menunggu persetujuan Finance';
        }

        if ($a['rejected']['count']) {
            $lines[] = '<i>Ditolak, tidak dihitung: ' . $this->rupiah($a['rejected']['amount']) . " ({$a['rejected']['count']})</i>";
        }

        return implode("\n", [...$lines, '', '<b>Per project</b>', ...$this->topList(
            $this->view->advanceByProject(),
            fn (array $row) => $this->rupiah($row['amount']) . " ({$row['count']})"
                . "\n   cair " . $this->rupiah($row['disbursed']) . ' · realisasi ' . $this->rupiah($row['realized'])
                . ($row['outstanding'] > 0 ? ' · <b>sisa ' . $this->rupiah($row['outstanding']) . '</b>' : '')
        )]);
    }

    private function receivablesSection(): string
    {
        $unpaid = $this->view->unpaidInvoices();
        $today  = Carbon::today();
        $lines  = $this->heading('⏰ <b>Belum dibayar</b>');

        if ($unpaid->isEmpty()) {
            return implode("\n", [...$lines, 'Semua invoice pada periode ini sudah dibayar. 🎉']);
        }

        $lines[] = $unpaid->count() . ' invoice · <b>' . $this->rupiah($unpaid->sum(fn ($i) => FinanceOverview::invoiceUnpaid($i))) . '</b>';
        $lines[] = '';

        foreach ($unpaid->take(self::LIST_LIMIT) as $invoice) {
            $due     = $invoice->due_date ? Carbon::parse($invoice->due_date) : null;
            $overdue = $due && $due->lt($today) ? (int) $due->diffInDays($today) : 0;

            $lines[] = ($overdue > 0 ? '🔴' : '🟡') . ' <b>' . e((string) $invoice->nama_perusahaan) . '</b> — sisa ' . $this->rupiah(FinanceOverview::invoiceUnpaid($invoice))
                . "\n   " . $this->kindLabel($invoice) . ' · total ' . $this->rupiah($invoice->grand_total) . ' · dibayar ' . $this->rupiah($invoice->paid)
                . "\n   " . e((string) $invoice->uuid) . ' · jatuh tempo ' . ($due ? $due->locale('id')->translatedFormat('j M Y') : '-')
                . ($overdue > 0 ? " (lewat {$overdue} hari)" : '');
        }

        if ($unpaid->count() > self::LIST_LIMIT) {
            $lines[] = '…dan ' . ($unpaid->count() - self::LIST_LIMIT) . ' invoice lain (lihat file Excel).';
        }

        $lines[] = '';
        $lines[] = '<i>Ada pembayaran masuk? Ketik /bayar untuk mencatatnya.</i>';

        return implode("\n", $lines);
    }

    private function kindLabel(Invoice $invoice): string
    {
        return match (FinanceOverview::invoiceKind($invoice)) {
            'dp'    => 'DP ' . $invoice->dpPercentage() . '%',
            'final' => 'Pelunasan',
            default => 'Full payment',
        };
    }

    private function pendingSection(): string
    {
        $pending = $this->overview->pending();
        // Antrean saat ini, tidak mengikuti periode.
        $lines   = ['⏳ <b>Menunggu proses</b>', '<i>Semua yang masih menunggu, tidak mengikuti periode.</i>'];

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

        $period = $this->periodFor($chatId);
        $path   = $this->export->toFile($period);
        $suffix = $period->isAll() ? 'semua-waktu' : implode('_', $period->toArray());

        try {
            $sent = $this->telegram->sendDocument(
                $chatId,
                $path,
                "laporan-keuangan-{$suffix}.xlsx",
                '📎 Laporan keuangan · ' . $period->label() . '. Sheet: Ringkasan, Invoice, Expense, Advance, Per Project.'
            );
        } finally {
            @unlink($path);
        }

        if (! $sent) {
            $this->telegram->sendMessage($chatId, 'File Excel gagal dikirim. Coba lagi sebentar.');
        }
    }

    private function helpText(): string
    {
        return <<<'HTML'
        📊 <b>Bot laporan keuangan</b>

        /ringkasan – invoice masuk (DP, full payment), expense, advance
        /invoice – invoice masuk per jenis dan daftarnya
        /expense – per kategori dan per project
        /advance – per project: dicairkan, realisasi, sisa
        /piutang – invoice yang belum dibayar
        /pending – pengajuan yang menunggu persetujuan atau pembayaran
        /periode – pilih periode, mis. <code>/periode 1/10/2026 - 31/10/2026</code>
        /excel – unduh data periode ini dalam satu file Excel
        /bayar – catat pembayaran invoice yang masuk (bisa /bayar nama klien)

        Periode berlaku untuk semua laporan dan Excel sampai diganti. Selain /bayar, bot ini hanya membaca data.
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
