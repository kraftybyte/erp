<?php

namespace App\Observers;

use App\Models\Budget;
use App\Models\BudgetLog;
use Illuminate\Support\Facades\Auth;

class BudgetObserver
{
    public function created(Budget $budget)
    {
        BudgetLog::create([
            'budget_id' => $budget->id,
            'user_id' => Auth::id(),
            'change_amount' => $budget->amount,
            'action' => 'Menambahkan Budget',
            'note' => 'Budget baru dibuat.',
        ]);
    }

    public function updated(Budget $budget)
    {
        $originalAmount = $budget->getOriginal('amount');
        $newAmount = $budget->amount;
        $change = $newAmount - $originalAmount;

        if ($change === 0) {
            return;
        }

        BudgetLog::create([
            'budget_id' => $budget->id,
            'user_id' => Auth::id(),
            'change_amount' => $change,
            'action' => $change > 0 ? 'Menambahkan Budget' : 'Mengurangi Budget',
            'note' => $budget->name . ' - perubahan nominal budget.',
        ]);
    }
}
