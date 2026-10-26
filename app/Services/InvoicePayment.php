<?php

namespace App\Services;

use App\Exceptions\InvoicePaymentException;
use App\Models\Invoice;
use App\Models\PaymentAccount;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mencatat pembayaran invoice penjualan. Satu-satunya jalur, dipakai tombol
 * Bayar di ERP maupun bot laporan keuangan di Telegram.
 */
class InvoicePayment
{
    /**
     * Invoice yang masih bisa dibayar: penjualan, belum lunas, tidak dibatalkan.
     */
    public static function isPayable(Invoice $invoice): bool
    {
        return $invoice->type === 'sale'
            && self::remaining($invoice) > 0
            && ! in_array($invoice->status, ['paid', 'cancelled'], true);
    }

    /**
     * Sisa tagihan. Untuk invoice DP dihitung dari uang mukanya, bukan harga penuh.
     */
    public static function remaining(Invoice $invoice): float
    {
        return max(round($invoice->amountDue() - (float) $invoice->paid, 2), 0);
    }

    /**
     * Catat $amount yang diterima di rekening $account.
     *
     * Baris invoice dikunci selama pencatatan: tanpa itu dua pembayaran yang
     * dicatat bersamaan (mis. dari ERP dan Telegram) bisa melebihi tagihan.
     *
     * @throws InvoicePaymentException
     */
    public function pay(Invoice $invoice, float $amount, PaymentAccount $account, User $by): Invoice
    {
        return DB::transaction(function () use ($invoice, $amount, $account, $by) {
            $invoice = Invoice::lockForUpdate()->find($invoice->getKey());

            if (! $invoice || ! self::isPayable($invoice)) {
                throw new InvoicePaymentException('Invoice ini sudah lunas, dibatalkan, atau bukan invoice penjualan.');
            }

            $amount    = round($amount, 2);
            $remaining = self::remaining($invoice);

            if ($amount <= 0) {
                throw new InvoicePaymentException('Nominal pembayaran harus lebih dari nol.');
            }

            if ($amount > $remaining) {
                throw new InvoicePaymentException('Jumlah pembayaran melebihi sisa tagihan (Rp ' . number_format($remaining, 0, ',', '.') . ').');
            }

            $due     = $invoice->amountDue();
            $newPaid = round((float) $invoice->paid + $amount, 2);
            $status  = $newPaid >= round($due, 2) ? 'paid' : 'partial';
            $dp      = $invoice->dpPercentage();

            $invoice->update([
                'paid'   => $newPaid,
                'status' => $status,
                // Keterangan yang terbaca manusia; PDF pelunasan membaca parent_invoice_id, bukan teks ini.
                'notes'  => $dp !== null && $status === 'paid'
                    ? "DP: {$dp}% - Rp " . number_format($newPaid, 0, ',', '.') . "\n"
                    : $invoice->notes,
            ]);

            $invoice->invoiceMetas()->create(['key' => 'payments', 'value' => $amount]);

            Transaction::create([
                'payment_account_id' => $account->id,
                'created_by'         => $by->id,
                'type'               => 'in',
                'category'           => 'invoice',
                'amount'             => $amount,
                'reference_type'     => 'invoice',
                'reference_id'       => $invoice->id,
                'transaction_date'   => now(),
                'description'        => 'Pembayaran Invoice ' . $invoice->uuid . ' dari ' . ($invoice->nama_perusahaan ?? '-'),
            ]);

            // Uang masuk: saldo dihitung ulang dari mutasi (initial + in - out).
            $account->syncBalanceFromTransactions();

            $invoice->invoiceLogs()->create([
                'log'  => 'Paid ' . number_format($amount, 2) . ' ' . ($invoice->currency?->iso ?? 'id') . " from {$account->name} By: {$by->name}",
                'type' => 'payment',
            ]);

            return $invoice;
        });
    }
}
