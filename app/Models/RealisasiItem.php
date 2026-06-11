<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RealisasiItem extends Model
{
    use SoftDeletes;

    protected $table = 'realisasi_items';

    protected $fillable = [
        'realisasi_id',
        'name',
        'description',
        'quantity',
        'price',
        'subtotal',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'price' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    // Relationships
    public function realizasi(): BelongsTo
    {
        return $this->belongsTo(Realisasi::class, 'realisasi_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(RealisasiFile::class, 'realisasi_item_id');
    }

    // Auto-calculate subtotal
    public function calculateSubtotal(): float
    {
        $this->subtotal = (float) $this->quantity * (float) $this->price;
        return $this->subtotal;
    }

    // Boot to auto-calculate subtotal
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function ($item) {
            $item->subtotal = (float) $item->quantity * (float) $item->price;
        });
    }
}