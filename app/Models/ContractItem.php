<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractItem extends Model
{
    protected $fillable = [
        'contract_id',
        'parent_id',
        'item',
        'description',
        'amount',
        'due_date',
        'notes',
        'type', // 'fee', 'third_party', 'sow'
        'quantity',
        'unit',
        'sort_order',
        'category', // untuk grouping SOW items
        'is_category_header', // true kalau ini header kategori
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'quantity' => 'integer',
        'is_category_header' => 'boolean',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function children(): HasMany
    {
        return $this->hasMany(ContractItem::class, 'parent_id')->orderBy('sort_order');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ContractItem::class, 'parent_id');
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

    public function scopeCategoryHeaders($query)
    {
        return $query->where('type', 'sow')->where('is_category_header', true);
    }

    public function scopeRootSow($query)
    {
        return $query->where('type', 'sow')->whereNull('parent_id')->orderBy('sort_order');
    }
}
