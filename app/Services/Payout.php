<?php

namespace App\Services;

use App\Exceptions\PayoutException;
use App\Helpers\Money;
use App\Helpers\NotifHelper;
use App\Models\Advance;
use App\Models\Expense;
use App\Models\PaymentAccount;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Membayarkan expense atau advance yang sudah Disetujui: transaksi keluar di
 * rekening, saldo disinkronkan, status menjadi Dibayarkan. Satu jalur untuk
 * tombol "Bayarkan Pengajuan" di ERP dan mutasi rekening di bot keuangan.
 *
 * Baris pengajuan dan rekening dikunci, lalu statusnya dibaca ulang: dua
 * orang yang menekan tombol bersamaan tidak membayar dua kali.
 *
 * Saldo rekening sengaja TIDAK dijadikan penghalang: pembayaran boleh melebihi
 * saldo tercatat (budget longgar). Saldo tetap disinkronkan setelah transaksi,
 * sehingga nilainya bisa minus dan selisihnya terlihat apa adanya.
 */
class Payout
{
    /**
     * @param  array{reference?: ?string, attachment_pay?: ?string}  $extra
     *
     * @throws PayoutException
     */
    public function expense(Expense $expense, float $amount, PaymentAccount $account, ?User $by, array $extra = [], ?Carbon $paidAt = null): Expense
    {
        return DB::transaction(function () use ($expense, $amount, $account, $by, $extra, $paidAt) {
            $expense = $this->lockPayable(Expense::class, $expense->getKey());
            $account = $this->lockAccount($account);
            $amount  = $this->checkAmount($amount, (float) $expense->amount);

            Transaction::create([
                'payment_account_id' => $account->id,
                'created_by'         => $by?->id,
                'type'               => 'out',
                'amount'             => $amount,
                'description'        => "Expense: {$expense->keterangan_expense}",
                'reference_type'     => 'expense',
                'reference_id'       => $expense->id,
                'transaction_date'   => ($paidAt ?? now())->toDateString(),
                'category'           => 'operational',
            ]);

            $account->syncBalanceFromTransactions();

            $expense->update([
                'status'             => 'Dibayarkan',
                'paid_by'            => $by?->id,
                'paid'               => $amount,
                'payment_account_id' => $account->id,
            ] + array_intersect_key($extra, array_flip(['reference', 'attachment_pay'])));

            return $expense;
        });
    }

    /**
     * Bayar advance. Alur biasa: sekali dari Disetujui, status menjadi
     * Dibayarkan. Bayar paralel (AdvanceParallel): boleh berkali-kali selama
     * advance belum selesai, sampai yang disetujui (atau total realisasi bila
     * kelebihannya disetujui); kolom paid menjumlahkan semua pembayaran dan
     * status realisasinya tidak berubah.
     *
     * @param  array{reference_number?: ?string, attachment_pay?: ?string}  $extra
     *
     * @throws PayoutException
     */
    public function advance(Advance $advance, float $amount, PaymentAccount $account, ?User $by, array $extra = [], ?Carbon $paidAt = null): Advance
    {
        $paidAt ??= now();
        $topUp  = false;

        $advance = DB::transaction(function () use ($advance, $amount, $account, $by, $extra, $paidAt, &$topUp) {
            $advance  = $this->lockAdvance($advance->getKey());
            $account  = $this->lockAccount($account);
            $topUp    = $advance->status !== Advance::STATUS_DISETUJUI;
            $parallel = $topUp || $advance->paysParallel();
            $amount   = $parallel ? $this->checkParallelAmount($amount, $advance) : $this->checkAmount($amount, (float) $advance->amount);

            Transaction::create([
                'payment_account_id' => $account->id,
                'created_by'         => $by?->id,
                'type'               => 'out',
                'amount'             => $amount,
                'description'        => "Advance: {$advance->keterangan_advance}",
                'reference_type'     => 'advance',
                'reference_id'       => $advance->id,
                'transaction_date'   => $paidAt->toDateString(),
                'category'           => 'operational',
            ]);

            $account->syncBalanceFromTransactions();

            $advance->update([
                'status'             => $topUp ? $advance->status : Advance::STATUS_DIBAYARKAN,
                'paid_by'            => $by?->id,
                'paid'               => $parallel ? round((float) $advance->paid + $amount, 2) : $amount,
                'payment_account_id' => $account->id,
                // Tanggal pembayaran pertama; pembayaran berikutnya ada di transaksinya.
                'paid_at'            => $parallel && $advance->paid_at ? $advance->paid_at : $paidAt,
                'pay_parallel'       => $parallel,
            ] + array_intersect_key($extra, array_flip(['reference_number', 'attachment_pay'])));

            return $advance;
        });

        // Status tidak berubah, jadi observer tidak memberi tahu pengaju: kabari di sini.
        if ($topUp && $advance->user) {
            $realized = $advance->realizedTotal();

            NotifHelper::send(
                $advance->user,
                'Pembayaran Advance 💰',
                'Advance #' . $advance->id . ' dibayar lagi ' . Money::rupiah($amount) . ' oleh ' . ($by ? trim("{$by->firstname} {$by->lastname}") : 'Finance')
                    . '. Total dibayar ' . Money::rupiah($advance->paid) . ', realisasi ' . Money::rupiah($realized) . '.',
                'heroicon-o-banknotes',
                'success',
                '/admin/advances',
                telegram: true
            );
        }

        return $advance;
    }

    /**
     * Advance yang boleh dibayar: Disetujui, atau bayar paralel yang sedang
     * direalisasi (pembayaran bertahap).
     */
    private function lockAdvance(int|string $id): Advance
    {
        $advance = Advance::lockForUpdate()->find($id);

        if (! $advance) {
            throw new PayoutException('Pengajuan tidak ditemukan.');
        }

        if ($advance->status === Advance::STATUS_DISETUJUI
            || (AdvanceParallel::allows($advance) && in_array($advance->status, AdvanceParallel::REALIZING, true))) {
            return $advance;
        }

        throw new PayoutException($advance->status === Advance::STATUS_DIBAYARKAN
            ? 'Pengajuan ini sudah dibayarkan.'
            : "Pengajuan berstatus \"{$advance->status}\" dan tidak bisa dibayarkan.");
    }

    private function checkParallelAmount(float $amount, Advance $advance): float
    {
        $amount  = round($amount, 2);
        $payable = AdvanceParallel::payable($advance);

        if ($amount <= 0) {
            throw new PayoutException('Nominal yang dibayarkan harus lebih dari nol.');
        }

        if ($payable <= 0) {
            throw new PayoutException('Advance ini sudah dibayar penuh sebesar yang disetujui. Bila realisasinya lebih, setujui kelebihannya dulu.');
        }

        if ($amount > $payable + 0.01) {
            throw new PayoutException('Nominal melebihi yang masih bisa dibayarkan (Rp ' . number_format($payable, 0, ',', '.') . ').');
        }

        return $amount;
    }

    /**
     * @template T of Expense|Advance
     *
     * @param  class-string<T>  $model
     * @return T
     */
    private function lockPayable(string $model, int|string $id): Expense|Advance
    {
        $record = $model::lockForUpdate()->find($id);

        if (! $record) {
            throw new PayoutException('Pengajuan tidak ditemukan.');
        }

        if ($record->status !== 'Disetujui') {
            throw new PayoutException($record->status === 'Dibayarkan'
                ? 'Pengajuan ini sudah dibayarkan.'
                : "Pengajuan berstatus \"{$record->status}\" dan tidak bisa dibayarkan.");
        }

        return $record;
    }

    private function lockAccount(PaymentAccount $account): PaymentAccount
    {
        return PaymentAccount::lockForUpdate()->find($account->getKey())
            ?? throw new PayoutException('Rekening tidak ditemukan.');
    }

    private function checkAmount(float $amount, float $requested): float
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new PayoutException('Nominal yang dibayarkan harus lebih dari nol.');
        }

        if ($amount > round($requested, 2)) {
            throw new PayoutException('Nominal yang dibayarkan tidak boleh lebih dari jumlah yang diajukan (Rp ' . number_format($requested, 0, ',', '.') . ').');
        }

        return $amount;
    }
}
