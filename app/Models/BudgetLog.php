<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetLog extends Model
{
    protected $table = 'budget_logs';

    protected $fillable = [
        'budget_id',
        'user_id',
        'change_amount',
        'action',
        'note',
    ];

    protected $casts = [
        'change_amount' => 'decimal:2',
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}