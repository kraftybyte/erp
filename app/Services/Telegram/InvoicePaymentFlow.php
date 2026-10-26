<?php

namespace App\Services\Telegram;

use App\Exceptions\InvoicePaymentException;
use App\Helpers\AmountSearch;
use App\Models\Invoice;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Services\InvoicePayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Mencatat pembayaran invoice lewat bot laporan keuangan:
 * pilih invoice → rekening penerima → nominal → konfirmasi.
 *
 * Hak akses (Director, Finance, super_admin) diperiksa FinanceBot di setiap
 * pesan dan tombol. Pencatatannya lewat InvoicePayment, jalur yang sama
 * dengan tombol Bayar di ERP.
 */
class InvoicePaymentFlow
{
    private const LIST_LIMIT = 8;

    private const TTL_MINUTES = 30;

    public function __construct(private TelegramClient $telegram, private InvoicePayment $payments) {}

    /**
     * Ada langkah yang sedang menunggu ketikan (pencarian invoice atau nominal).
     */
    public function awaitingText(int|string $chatId): bool
    {
        return isset($this->state($chatId)['awaiting']);
    }

    public function forget(int|string $chatId): void
    {
        Cache::forget($this->key($chatId));
    }

    /**
     * /bayar, atau /bayar <nomor invoice / nama klien>.
     */
    public function start(int|string $chatId, string $query = ''): void
    {
        $this->save($chatId, ['awaiting' => 'search']);
        $this->showList($chatId, $query);
    }

    public function handleText(int|string $chatId, string $text): void
    {
        $state = $this->state($chatId);

        ($state['awaiting'] ?? null) === 'amount'
            ? $this->receiveAmount($chatId, $text)
            : $this->showList($chatId, $text);
    }

    public function handleCallback(array $callback, User $user): void
    {
        $chatId    = $callback['message']['chat']['id'];
        $messageId = $callback['message']['message_id'];

        [$action, $arg] = array_pad(explode(':', Str::after((string) $callback['data'], 'pay:'), 2), 2, null);

        $this->telegram->answerCallback($callback['id'], $action === 'ok' ? 'Menyimpan…' : null);

        match ($action) {
            'list'  => $this->start($chatId),
            'inv'   => $this->chooseInvoice($chatId, (int) $arg),
            'acc'   => $this->chooseAccount($chatId, (int) $arg),
            'full'  => $this->setAmount($chatId, null),
            'ok'    => $this->confirm($chatId, $messageId, $user),
            default => $this->cancel($chatId, $messageId),
        };
    }

    // =================================================================
    // Langkah
    // =================================================================

    private function showList(int|string $chatId, string $query): void
    {
        $query    = trim($query);
        $invoices = $this->payable($query);
        $for      = $query !== '' ? ' untuk "' . e($query) . '"' : '';

        if ($invoices->isEmpty()) {
            $this->telegram->sendMessage(
                $chatId,
                "💳 Tidak ada invoice yang belum lunas{$for}.\n\nKetik nomor invoice atau nama klien lain, atau /piutang untuk melihat daftar lengkap.",
                [[['text' => '❌ Batal', 'callback_data' => 'pay:cancel']]]
            );

            return;
        }

        $lines = [
            '💳 <b>Catat pembayaran invoice</b>',
            '',
            "{$invoices->count()} invoice belum lunas{$for}. Pilih invoice yang dibayar, atau ketik nomor invoice / nama klien untuk mencari.",
        ];

        if ($invoices->count() > self::LIST_LIMIT) {
            $lines[] = '';
            $lines[] = '<i>Ditampilkan ' . self::LIST_LIMIT . ' dengan jatuh tempo terlama.</i>';
        }

        $buttons = $invoices->take(self::LIST_LIMIT)
            ->map(fn (Invoice $invoice) => [[
                'text'          => ($this->isOverdue($invoice) ? '🔴 ' : '🟡 ') . Str::limit((string) $invoice->nama_perusahaan, 26)
                    . ' · ' . $this->rupiah(InvoicePayment::remaining($invoice)),
                'callback_data' => "pay:inv:{$invoice->id}",
            ]])
            ->push([['text' => '❌ Batal', 'callback_data' => 'pay:cancel']])
            ->all();

        $this->telegram->sendMessage($chatId, implode("\n", $lines), $buttons);
    }

    private function chooseInvoice(int|string $chatId, int $invoiceId): void
    {
        $invoice = Invoice::find($invoiceId);

        if (! $invoice || ! InvoicePayment::isPayable($invoice)) {
            $this->telegram->sendMessage($chatId, 'Invoice ini sudah lunas atau tidak bisa dibayar lagi.', [[['text' => '💳 Pilih invoice lain', 'callback_data' => 'pay:list']]]);

            return;
        }

        $accounts = PaymentAccount::active()->orderBy('name')->get();

        if ($accounts->isEmpty()) {
            $this->forget($chatId);
            $this->telegram->sendMessage($chatId, 'Belum ada rekening aktif di ERP. Tambahkan dulu di menu Rekening, lalu coba lagi.');

            return;
        }

        // Mengetik di langkah ini berarti mencari invoice lain.
        $this->save($chatId, ['invoice_id' => $invoice->id, 'awaiting' => 'search']);

        $buttons = $accounts
            ->map(fn (PaymentAccount $account) => [
                'text'          => Str::limit($account->name . ($account->bank_name ? " ({$account->bank_name})" : ''), 30),
                'callback_data' => "pay:acc:{$account->id}",
            ])
            ->chunk(2)
            ->map(fn (Collection $row) => $row->values()->all())
            ->push([['text' => '❌ Batal', 'callback_data' => 'pay:cancel']])
            ->values()
            ->all();

        $this->telegram->sendMessage($chatId, $this->describe($invoice) . "\n\nUang diterima di rekening mana?", $buttons);
    }

    private function chooseAccount(int|string $chatId, int $accountId): void
    {
        if (! $invoice = $this->currentInvoice($chatId)) {
            return;
        }

        $account = PaymentAccount::active()->find($accountId);

        if (! $account) {
            $this->telegram->sendMessage($chatId, 'Rekening itu tidak aktif lagi. Pilih invoice-nya ulang.', [[['text' => '💳 Pilih invoice', 'callback_data' => 'pay:list']]]);

            return;
        }

        $this->save($chatId, ['invoice_id' => $invoice->id, 'account_id' => $account->id, 'awaiting' => 'amount']);

        $remaining = InvoicePayment::remaining($invoice);

        $this->telegram->sendMessage(
            $chatId,
            'Sisa tagihan <b>' . $this->rupiah($remaining) . "</b>.\n\nKetik nominal yang diterima (mis. <code>25jt</code> atau <code>25.000.000</code>) bila dibayar sebagian, atau pilih:",
            [
                [['text' => '✅ Lunas — ' . $this->rupiah($remaining), 'callback_data' => 'pay:full']],
                [['text' => '❌ Batal', 'callback_data' => 'pay:cancel']],
            ]
        );
    }

    private function receiveAmount(int|string $chatId, string $text): void
    {
        $amount = AmountParser::parse($text);

        if ($amount === null) {
            $this->telegram->sendMessage($chatId, 'Nominal tidak terbaca. Ketik angkanya, mis. <code>25jt</code> atau <code>25.000.000</code>.');

            return;
        }

        $this->setAmount($chatId, (float) $amount);
    }

    /**
     * @param  float|null  $amount  null = lunasi seluruh sisa tagihan
     */
    private function setAmount(int|string $chatId, ?float $amount): void
    {
        $state = $this->state($chatId);

        if (! ($invoice = $this->currentInvoice($chatId)) || ! ($account = PaymentAccount::find($state['account_id'] ?? 0))) {
            return;
        }

        $remaining = InvoicePayment::remaining($invoice);
        $amount ??= $remaining;

        if (round($amount, 2) > $remaining) {
            $this->telegram->sendMessage($chatId, 'Nominal ' . $this->rupiah($amount) . ' melebihi sisa tagihan ' . $this->rupiah($remaining) . '. Ketik nominal lain.');

            return;
        }

        // Mengetik nominal lain di langkah konfirmasi memperbaruinya.
        $this->save($chatId, ['invoice_id' => $invoice->id, 'account_id' => $account->id, 'amount' => $amount, 'awaiting' => 'amount']);

        $after = $amount >= $remaining
            ? '<b>Lunas</b>'
            : 'Dibayar sebagian, sisa ' . $this->rupiah($remaining - $amount);

        $this->telegram->sendMessage($chatId, implode("\n", [
            '💳 <b>Konfirmasi pembayaran</b>',
            '',
            $this->describe($invoice),
            '',
            'Diterima: <b>' . $this->rupiah($amount) . '</b> di ' . e($account->name),
            "Setelah ini: {$after}",
        ]), [[
            ['text' => '✅ Simpan', 'callback_data' => 'pay:ok'],
            ['text' => '❌ Batal', 'callback_data' => 'pay:cancel'],
        ]]);
    }

    private function confirm(int|string $chatId, int $messageId, User $user): void
    {
        // Ketukan ganda atau dua update bersamaan: hanya satu yang mencatat.
        Cache::lock("telegram:finance-pay-lock:{$chatId}", 30)->block(10, function () use ($chatId, $messageId, $user) {
            $state   = Cache::pull($this->key($chatId), []);
            $invoice = Invoice::find($state['invoice_id'] ?? 0);
            $account = PaymentAccount::find($state['account_id'] ?? 0);

            if (! $invoice || ! $account || ! isset($state['amount'])) {
                $this->reply($chatId, $messageId, 'Sesi ini sudah selesai atau kedaluwarsa. Ketik /bayar untuk mulai lagi.');

                return;
            }

            try {
                $invoice = $this->payments->pay($invoice, (float) $state['amount'], $account, $user);
            } catch (InvoicePaymentException $e) {
                $this->reply($chatId, $messageId, '⚠️ Tidak tercatat: ' . e($e->getMessage()), [[['text' => '💳 Pilih invoice', 'callback_data' => 'pay:list']]]);

                return;
            }

            $this->reply($chatId, $messageId, implode("\n", [
                '✅ <b>Pembayaran tercatat</b>',
                '',
                e((string) $invoice->uuid) . ' · ' . e((string) $invoice->nama_perusahaan),
                $this->rupiah($state['amount']) . ' diterima di ' . e($account->name),
                'Status invoice: ' . ($invoice->status === 'paid' ? '<b>Lunas</b>' : 'dibayar sebagian, sisa ' . $this->rupiah(InvoicePayment::remaining($invoice))),
                'Saldo ' . e($account->name) . ': ' . $this->rupiah($account->fresh()->current_balance),
            ]), [[
                ['text' => '💳 Catat pembayaran lain', 'callback_data' => 'pay:list'],
                ['text' => '⏰ Belum dibayar', 'callback_data' => 'fin:piutang'],
            ]]);
        });
    }

    private function cancel(int|string $chatId, int $messageId): void
    {
        $this->forget($chatId);
        $this->reply($chatId, $messageId, 'Pencatatan pembayaran dibatalkan.');
    }

    // =================================================================
    // Bantuan
    // =================================================================

    /**
     * Invoice penjualan yang belum lunas, jatuh tempo terlama dulu. $query
     * mencari nomor invoice, klien, PIC, atau nominal (sisa / tagihan).
     *
     * @return Collection<int, Invoice>
     */
    private function payable(string $query): Collection
    {
        $digits = $query !== '' ? AmountSearch::digits($query) : null;

        return Invoice::query()
            ->where('type', 'sale')
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['paid', 'cancelled'])
            ->when($query !== '' && $digits === null, fn ($q) => $q->where(fn ($q) => $q
                ->where('uuid', 'like', "%{$query}%")
                ->orWhere('nama_perusahaan', 'like', "%{$query}%")
                ->orWhere('nama_pic', 'like', "%{$query}%")))
            ->orderByRaw('due_date IS NULL, due_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (Invoice $invoice) => InvoicePayment::isPayable($invoice))
            // Angka bisa berarti nominal atau bagian nomor invoice ("00005").
            ->filter(fn (Invoice $invoice) => $digits === null
                || str_contains((string) $invoice->uuid, $query)
                || collect([InvoicePayment::remaining($invoice), $invoice->amountDue(), $invoice->grand_total])
                    ->contains(fn ($amount) => str_contains((string) round((float) $amount), $digits)))
            ->values();
    }

    private function describe(Invoice $invoice): string
    {
        $kind = match (true) {
            $invoice->dpPercentage() !== null          => 'DP ' . $invoice->dpPercentage() . '%',
            $invoice->type_payment === 'final_payment' => 'Pelunasan',
            default                                    => 'Bayar penuh',
        };

        $pic = filled($invoice->nama_pic) && $invoice->nama_pic !== '-' ? ' — ' . e($invoice->nama_pic) : '';
        $due = $invoice->due_date ? ' · jatuh tempo ' . Carbon::parse($invoice->due_date)->locale('id')->translatedFormat('j M Y') : '';

        return implode("\n", [
            '📄 <b>' . e((string) $invoice->uuid) . '</b>',
            e((string) $invoice->nama_perusahaan) . $pic,
            "{$kind} · ditagih " . $this->rupiah($invoice->amountDue()) . ' · dibayar ' . $this->rupiah($invoice->paid),
            'Sisa: <b>' . $this->rupiah(InvoicePayment::remaining($invoice)) . '</b>' . $due . ($this->isOverdue($invoice) ? ' 🔴' : ''),
        ]);
    }

    /**
     * Invoice yang sedang dipilih, masih bisa dibayar. Bila tidak, pengguna
     * diberi tahu dan sesinya ditutup.
     */
    private function currentInvoice(int|string $chatId): ?Invoice
    {
        $invoice = Invoice::find($this->state($chatId)['invoice_id'] ?? 0);

        if ($invoice && InvoicePayment::isPayable($invoice)) {
            return $invoice;
        }

        $this->forget($chatId);
        $this->telegram->sendMessage($chatId, 'Sesi pembayaran sudah selesai atau invoice-nya sudah lunas. Ketik /bayar untuk mulai lagi.');

        return null;
    }

    private function isOverdue(Invoice $invoice): bool
    {
        return $invoice->due_date && Carbon::parse($invoice->due_date)->lt(Carbon::today());
    }

    private function reply(int|string $chatId, int $messageId, string $html, array $buttons = []): void
    {
        if (! $this->telegram->editMessage($chatId, $messageId, $html, $buttons)) {
            $this->telegram->sendMessage($chatId, $html, $buttons);
        }
    }

    private function state(int|string $chatId): array
    {
        return Cache::get($this->key($chatId), []);
    }

    private function save(int|string $chatId, array $state): void
    {
        Cache::put($this->key($chatId), $state, now()->addMinutes(self::TTL_MINUTES));
    }

    private function key(int|string $chatId): string
    {
        return "telegram:finance-pay:{$chatId}";
    }

    private function rupiah(int|float|string|null $amount): string
    {
        return 'Rp ' . number_format((float) $amount, 0, ',', '.');
    }
}
