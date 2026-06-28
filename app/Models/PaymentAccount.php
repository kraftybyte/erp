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
        'is_active',
        'notes',
    ];

    protected $attributes = [
        'current_balance' => 0,
        'initial_balance' => 0,
        'is_active' => true,
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($account) {
            // Set initial balance to current_balance if empty
            if (empty($account->current_balance)) {
                $account->current_balance = $account->initial_balance ?? 0;
            }
        });
    }

    protected $casts = [
        'initial_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
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

    /**
     * Get the available balance - calculates from transactions
     */
    public function getAvailableBalance(): float
    {
        return (float) ($this->initial_balance ?? 0) + $this->getTotalIn() - $this->getTotalOut();
    }

    /**
     * Get the current balance - uses stored field or calculates from transactions
     */
    public function getBalance(): float
    {
        // Try stored current_balance first
        if ($this->current_balance !== null) {
            return (float) $this->current_balance;
        }
        // Fall back to calculated
        return $this->getAvailableBalance();
    }

    /**
     * Sync current_balance from transactions
     */
    public function syncBalanceFromTransactions(): void
    {
        $calculatedBalance = $this->getAvailableBalance();
        $this->updateQuietly([
            'current_balance' => $calculatedBalance,
        ]);
        $this->refresh();
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

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    public function scopeHasBalance($query)
    {
        return $query->where('current_balance', '>', 0);
    }

    // Check methods
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

    /**
     * Format balance for display
     */
    public function getFormattedBalance(): string
    {
        return 'Rp ' . number_format($this->getBalance(), 0, ',', '.');
    }
}
