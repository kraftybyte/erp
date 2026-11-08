<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mutasi rekening yang dicatat ke ERP lewat bot keuangan, beserta invoice /
 * expense / advance yang dibayarnya. Lihat App\Services\BankStatement.
 */
class BankMutation extends Model
{
    protected $fillable = [
        'payment_account_id',
        'fingerprint',
        'mutation_date',
        'direction',
        'amount',
        'description',
        'balance',
        'targets',
        'upload',
        'recorded_by',
    ];

    protected $casts = [
        'mutation_date' => 'date',
        'amount'        => 'decimal:2',
        'balance'       => 'decimal:2',
        'targets'       => 'array',
    ];

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
