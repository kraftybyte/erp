<?php

namespace App\Services\BankStatement;

use App\Exceptions\InvoicePaymentException;
use App\Exceptions\PayoutException;
use App\Models\Advance;
use App\Models\BankMutation;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PaymentAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InvoicePayment;
use App\Services\Payout;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mencatat mutasi yang sudah dipastikan Finance: pembayaran invoice lewat
 * InvoicePayment, expense/advance lewat Payout (jalur yang sama dengan ERP),
 * dengan tanggal transaksi = tanggal mutasi.
 *
 * Setiap mutasi dicatat dalam satu transaksi database bersama baris
 * bank_mutations-nya; sidik jari mutasi unik per rekening, jadi tombol yang
 * ditekan dua kali atau unggahan ulang tidak mencatat dua kali. Satu mutasi
 * yang gagal (mis. invoice baru saja dilunasi dari ERP) tidak membatalkan
 * yang lain.
 */
class MutationRecorder
{
    public const SOURCE = 'mutasi rekening';

    public function __construct(private InvoicePayment $payments, private Payout $payout) {}

    /**
     * @param  array<int, array{row: array, targets: array<int, array{type: string, id: int, amount: float}>}>  $items
     * @return array{recorded: array<int, array{row: array, targets: array}>, failed: array<int, array{row: array, reason: string}>}
     */
    public function record(PaymentAccount $account, User $by, array $items, string $upload): array
    {
        $recorded = [];
        $failed   = [];

        foreach ($items as $item) {
            try {
                $recorded[] = DB::transaction(fn () => $this->recordOne($account, $by, $item, $upload));
            } catch (UniqueConstraintViolationException) {
                $failed[] = ['row' => $item['row'], 'reason' => 'Mutasi ini sudah pernah dicatat.'];
            } catch (InvoicePaymentException|PayoutException $e) {
                $failed[] = ['row' => $item['row'], 'reason' => $e->getMessage()];
            } catch (ModelNotFoundException) {
                $failed[] = ['row' => $item['row'], 'reason' => 'Invoice/pengajuannya tidak ditemukan (mungkin baru dihapus).'];
            }
        }

        return ['recorded' => $recorded, 'failed' => $failed];
    }

    private function recordOne(PaymentAccount $account, User $by, array $item, string $upload): array
    {
        $row = $item['row'];

        // Dibuat dulu: sidik jari yang sudah ada langsung menghentikan mutasi ini.
        $mutation = BankMutation::create([
            'payment_account_id' => $account->id,
            'fingerprint'        => $row['key'],
            'mutation_date'      => $row['date'],
            'direction'          => $row['direction'],
            'amount'             => $row['amount'],
            'description'        => Str::limit($row['description'], 1000, ''),
            'balance'            => $row['balance'] ?? null,
            'targets'            => [],
            'upload'             => $upload,
            'recorded_by'        => $by->id,
        ]);

        $date      = Carbon::parse($row['date']);
        $reference = Str::limit('Mutasi ' . $date->format('d/m/Y') . ': ' . $row['description'], 250, '');
        $targets   = [];

        foreach ($item['targets'] as $target) {
            if (in_array($target['type'], ['bank_fee', 'bank_interest'], true)) {
                $targets[] = $target + ['transaction_id' => $this->bankCharge($account, $by, $mutation, $target, $row, $date)];

                continue;
            }

            match ($target['type']) {
                'invoice' => $this->payments->pay(Invoice::findOrFail($target['id']), (float) $target['amount'], $account, $by, $date, self::SOURCE),
                'expense' => $this->payout->expense(Expense::findOrFail($target['id']), (float) $target['amount'], $account, $by, ['reference' => $reference], $date),
                'advance' => $this->payout->advance(Advance::findOrFail($target['id']), (float) $target['amount'], $account, $by, ['reference_number' => $reference], $date),
            };

            $transaction = Transaction::where('payment_account_id', $account->id)
                ->where('reference_type', $target['type'])
                ->where('reference_id', $target['id'])
                ->latest('id')
                ->value('id');

            // Klien memotong PPh 23: sisanya lunas tanpa uang masuk.
            if ($target['type'] === 'invoice' && ($target['withholding'] ?? 0) > 0) {
                $this->payments->withhold(Invoice::findOrFail($target['id']), (float) $target['withholding'], $by, $target['withholding_label'] ?? 'PPh 23');
            }

            $targets[] = $target + ['transaction_id' => $transaction];
        }

        $mutation->update(['targets' => $targets]);

        return ['row' => $row, 'targets' => $targets];
    }

    /**
     * Biaya admin/pajak (keluar) atau bunga (masuk) bank sebagai transaksi
     * rekening, supaya saldo ERP sama dengan saldo bank.
     */
    private function bankCharge(PaymentAccount $account, User $by, BankMutation $mutation, array $target, array $row, Carbon $date): int
    {
        $interest    = $target['type'] === 'bank_interest';
        $transaction = Transaction::create([
            'payment_account_id' => $account->id,
            'created_by'         => $by->id,
            'type'               => $interest ? 'in' : 'out',
            'category'           => $target['type'],
            'amount'             => (float) $target['amount'],
            'reference_type'     => 'bank_mutation',
            'reference_id'       => $mutation->id,
            'transaction_date'   => $date->toDateString(),
            'description'        => ($interest ? 'Bunga bank: ' : 'Biaya bank: ') . Str::limit($row['description'], 200, ''),
        ]);

        $account->syncBalanceFromTransactions();

        return $transaction->id;
    }
}
