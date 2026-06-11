<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasFactory;

    protected $table = 'transactions';

    const TYPE_IN = 'in';
    const TYPE_OUT = 'out';

    const REFERENCE_EXPENSE = 'expense';
    const REFERENCE_ADVANCE = 'advance';
    const REFERENCE_INVOICE = 'invoice';
    const REFERENCE_MANUAL = 'manual';
    const REFERENCE_DEPOSIT = 'deposit';

    protected $fillable = [
        'payment_account_id',
        'created_by',
        'type',
        'amount',
        'description',
        'reference_type',
        'reference_id',
        'transaction_date',
        'category',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'date',
    ];

    // Relationships
    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class, 'payment_account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'reference_id');
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(Advance::class, 'reference_id');
    }

    // Scopes
    public function scopeIn($query)
    {
        return $query->where('type', self::TYPE_IN);
    }

    public function scopeOut($query)
    {
        return $query->where('type', self::TYPE_OUT);
    }

    public function scopeForReference($query, string $type, ?int $id = null)
    {
        $query->where('reference_type', $type);
        if ($id) {
            $query->where('reference_id', $id);
        }
        return $query;
    }

    public function scopeByAccount($query, int $accountId)
    {
        return $query->where('payment_account_id', $accountId);
    }

    public function scopeBetweenDates($query, $start, $end)
    {
        return $query->whereBetween('transaction_date', [$start, $end]);
    }

    // Helpers
    public function isIn(): bool
    {
        return $this->type === self::TYPE_IN;
    }

    public function isOut(): bool
    {
        return $this->type === self::TYPE_OUT;
    }

    public function getSignedAmount(): float
    {
        return $this->isIn() ? (float) $this->amount : -(float) $this->amount;
    }

    public function getReferenceLabel(): string
    {
        if (!$this->reference_type) {
            return 'Manual';
        }

        return match ($this->reference_type) {
            self::REFERENCE_EXPENSE => 'Expense #' . $this->reference_id,
            self::REFERENCE_ADVANCE => 'Advance #' . $this->reference_id,
            self::REFERENCE_INVOICE => 'Invoice ' . ($this->getInvoiceUuid() ?? '#' . $this->reference_id),
            self::REFERENCE_DEPOSIT => 'Deposit',
            default => ucfirst($this->reference_type),
        };
    }

    /**
     * Get invoice UUID for display
     */
    public function getInvoiceUuid(): ?string
    {
        if ($this->reference_type !== self::REFERENCE_INVOICE || !$this->reference_id) {
            return null;
        }

        try {
            $invoice = \TomatoPHP\FilamentInvoices\Models\Invoice::find($this->reference_id);
            return $invoice?->uuid;
        } catch (\Exception $e) {
            return null;
        }
    }

    // Static helpers
    public static function getTypes(): array
    {
        return [
            self::TYPE_IN => 'Masuk (In)',
            self::TYPE_OUT => 'Keluar (Out)',
        ];
    }

    public static function getReferenceTypes(): array
    {
        return [
            self::REFERENCE_EXPENSE => 'Expense',
            self::REFERENCE_ADVANCE => 'Advance',
            self::REFERENCE_INVOICE => 'Invoice',
            self::REFERENCE_MANUAL => 'Manual',
            self::REFERENCE_DEPOSIT => 'Deposit/Top-up',
        ];
    }

    public static function getCategories(): array
    {
        return [
            'invoice' => 'Pemasukan Invoice',
            'deposit' => 'Setoran',
            'operational' => 'Operasional',
            'payroll' => 'Gaji & Honor',
            'marketing' => 'Marketing',
            'office' => 'Kantor',
            'it' => 'IT & Teknologi',
            'utility' => 'Utilitas',
            'withdrawal' => 'Penarikan',
            'transfer' => 'Transfer',
            'payment' => 'Pembayaran',
            'expense' => 'Biaya Operasional',
            'income' => 'Pendapatan',
            'other' => 'Lainnya',
        ];
    }
}
