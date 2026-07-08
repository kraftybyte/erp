<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractItem extends Model
{
    protected $fillable = [
        'contract_id',
        'item',
        'description',
        'amount',
        'due_date',
        'notes',
        'type', // 'fee', 'third_party', 'sow'
        'quantity',
        'unit',
        'sort_order',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'quantity' => 'integer',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function scopeFee($query)
    {
        return $query->where('type', 'fee');
    }

    public function scopeThirdParty($query)
    {
        return $query->where('type', 'third_party');
    }

    public function scopeSow($query)
    {
        return $query->where('type', 'sow');
    }
}
