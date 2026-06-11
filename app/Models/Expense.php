<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Observers\ExpenseObserver;

class Expense extends Model
{
    use HasFactory, SoftDeletes;

    protected static function boot(): void
    {
        parent::boot();
        static::observe(ExpenseObserver::class);
    }

    protected $fillable = [
        'user_id', 'submission_date', 'project_id', 'keterangan_expense',
        'amount', 'attachment', 'reference', 'status',
        'rejection_reason', 'attachment_pay',
        'payment_account_id', 'paid', 'paid_by', 'approved_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid' => 'decimal:2',
        'submission_date' => 'date',
        'deleted_at' => 'datetime',
    ];

    protected $dates = ['deleted_at'];

    // Relationships
    public function itemExpenses()
    {
        return $this->hasMany(ItemExpense::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class, 'payment_account_id');
    }
}
