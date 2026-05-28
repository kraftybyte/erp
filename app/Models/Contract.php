<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contract extends Model
{
    protected $fillable = [
        'deal_id',
        'type',
        'content',
        'effective_date',
        'expiration_date',
        'file_path',
        'duration_months',
        'total_value',
        'down_payment_percent',
        'max_revisions',
        'platform',
        'content_count',
        'scope_of_work',
        'client_name_full',
        'client_address',
        'client_phone',
        'client_email',
        'client_signatory_name',
        'client_signatory_title',
        'agency_signatory_name',
        'agency_signatory_title',
        // Payment type
        'payment_type',
        'installment_months',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'expiration_date' => 'date',
        'total_value' => 'decimal:2',
        'down_payment_percent' => 'decimal:2',
    ];

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }
}