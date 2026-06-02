<?php

namespace App\Observers;

use App\Models\PaymentAccount;
use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;

class PaymentAccountObserver
{
    /**
     * Handle the PaymentAccount "created" event.
     */
    public function created(PaymentAccount $account): void
    {
        // Create initial deposit transaction if initial_balance > 0
        if ($account->initial_balance > 0) {
            Transaction::create([
                'payment_account_id' => $account->id,
                'user_id' => Auth::id(),
                'type' => 'in',
                'amount' => $account->initial_balance,
                'description' => 'Saldo Awal',
                'reference_type' => 'manual',
                'transaction_date' => now()->toDateString(),
                'category' => 'deposit',
            ]);
        }
    }

    /**
     * Handle the PaymentAccount "updated" event.
     */
    public function updated(PaymentAccount $account): void
    {
        $originalInitial = $account->getOriginal('initial_balance');
        $newInitial = $account->initial_balance;
        $change = $newInitial - $originalInitial;

        if ($change === 0) {
            return;
        }

        Transaction::create([
            'payment_account_id' => $account->id,
            'user_id' => Auth::id(),
            'type' => $change > 0 ? 'in' : 'out',
            'amount' => abs($change),
            'description' => "Perubahan saldo awal: {$account->name}",
            'reference_type' => 'manual',
            'transaction_date' => now()->toDateString(),
            'category' => $change > 0 ? 'deposit' : 'withdrawal',
        ]);
    }
}
