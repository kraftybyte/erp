<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItemExpense extends Model
{
    use HasFactory;

    protected $fillable = [
        'expense_id', 'name', 'description', 'quantity', 'price',
    ];

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function getSubtotalAttribute()
    {
        return $this->quantity * $this->price;
    }
}
