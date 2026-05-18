<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_name',
        'quantity',
        'description',
        'reference_link',
        'price',
        'status',
        'reason',
        'requested_by',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'status' => 'string',
    ];

    /**
     * Get the user who requested the purchase.
     */
    public function user()
        {
            return $this->belongsTo(User::class, 'requested_by');
        }

}