<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationItem extends Model
{
    protected $fillable = [
        'quotation_id',
        'item',
        'qty',
        'price',
        'discount',
        'vat',
        'total',
        'Description',
        'vat_percentage',
    ];

    protected $attributes = [
        'discount' => 0,
    ];
    
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }
}
