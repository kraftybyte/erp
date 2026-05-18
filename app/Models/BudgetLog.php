<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BudgetLog extends Model
{
    use HasFactory;

    protected $fillable = ['budget_id', 'user_id', 'change_amount', 'action', 'note', 'expense_id', 'advance_id', 'invoice_id'];

    public function budget()
    {
        return $this->belongsTo(Budget::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }
    public function advance()
    {
        return $this->belongsTo(Advance::class);
    }
    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
