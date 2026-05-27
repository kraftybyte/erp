<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Observers\ExpenseObserver;

class Expense extends Model
{
    use HasFactory;
    

    protected static function boot(): void
    {
        parent::boot();
        static::observe(ExpenseObserver::class);
    }

    protected $fillable = [
        'user_id', 'submission_date', 'project_id', 'keterangan_expense',
        'amount', 'attachment', 'reference',
        'rejection_reason', 'attachment_pay',
    ];

    public function itemExpenses()
    {
        return $this->hasMany(ItemExpense::class);
    }
    
    // Relasi ke model User
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function paidBy()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
