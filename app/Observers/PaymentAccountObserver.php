<?php

namespace App\Observers;

use App\Models\PaymentAccount;
use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;

class PaymentAccountObserver
{
    /**
     * Handle the PaymentAccount "created" event.
     * initial_balance sudah otomatis diset saat created, tidak perlu buat transaksi
     */
    public function created(PaymentAccount $account): void
    {
        // Jangan buat transaksi untuk saldo awal
        // Saldo awal sudah tercatat di field initial_balance
        // Balance dihitung: initial_balance + total_in - total_out
    }

    /**
     * Handle the PaymentAccount "updated" event.
     * Hanya sync balance, tidak buat transaksi
     */
    public function updated(PaymentAccount $account): void
    {
        // Jangan buat transaksi saat initial_balance berubah
        // Sync balance dari transactions yang ada saja
        // Jika ingin catat perubahan saldo awal, buat manual
    }
}
