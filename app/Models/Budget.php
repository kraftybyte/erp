<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;

/**
 * Budget model - proxy to PaymentAccount for backward compatibility
 *
 * @deprecated Use PaymentAccount instead
 */
class Budget extends PaymentAccount
{
    protected $table = 'payment_accounts';

    public $timestamps = true;

    /**
     * Override increment to sync amount and current_balance
     */
    public function increment($column, $amount = 1, array $extra = []): int
    {
        if ($column === 'amount') {
            // Update both amount and current_balance atomically
            DB::table($this->getTable())
                ->where('id', $this->id)
                ->update([
                    'amount' => DB::raw('amount + ' . $amount),
                    'current_balance' => DB::raw('current_balance + ' . $amount),
                    'updated_at' => now(),
                ]);

            // Refresh the model
            $this->refresh();

            return 1;
        }

        return parent::increment($column, $amount, $extra);
    }
}
