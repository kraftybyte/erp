<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentAccount extends Model
{
    use HasFactory;

    protected $table = 'payment_accounts';

    protected $fillable = [
        'name',
        'bank_name',
        'account_number',
        'account_holder',
        'initial_balance',
        'current_balance',
        'amount',
        'is_active',
        'notes',
    ];

    protected $attributes = [
        'current_balance' => 0,
        'amount' => 0,
        'initial_balance' => 0,
        'is_active' => true,
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($account) {
            if (empty($account->current_balance)) {
                $account->current_balance = $account->initial_balance ?? 0;
            }
            $account->amount = $account->current_balance;
        });

        static::updating(function ($account) {
            if (isset($account->current_balance)) {
                $account->amount = $account->current_balance;
            }
        });
    }

    protected $casts = [
        'initial_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    // Relationships
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'payment_account_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'payment_account_id');
    }

    public function advances(): HasMany
    {
        return $this->hasMany(Advance::class, 'payment_account_id');
    }

    // Helpers
    public function getTotalIn(): float
    {
        return (float) $this->transactions()->where('type', 'in')->sum('amount');
    }

    public function getTotalOut(): float
    {
        return (float) $this->transactions()->where('type', 'out')->sum('amount');
    }

    public function getAvailableBalance(): float
    {
        // Calculate from transactions for accurate balance
        return $this->initial_balance + $this->getTotalIn() - $this->getTotalOut();
    }

    /**
     * Sync current_balance and amount from transactions
     */
    public function syncBalanceFromTransactions(): void
    {
        $calculatedBalance = $this->initial_balance + $this->getTotalIn() - $this->getTotalOut();
        $this->update([
            'current_balance' => $calculatedBalance,
            'amount' => $calculatedBalance,
        ]);
    }

    /**
     * Static method to sync all accounts
     */
    public static function syncAllBalances(): void
    {
        foreach (self::all() as $account) {
            $account->syncBalanceFromTransactions();
        }
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    public function isBank(): bool
    {
        return !empty($this->bank_name);
    }

    public function isCash(): bool
    {
        return empty($this->bank_name);
    }

    public function getDisplayName(): string
    {
        if ($this->isBank()) {
            return "{$this->bank_name} - {$this->account_number}";
        }
        return $this->name;
    }
}
