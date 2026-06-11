<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Realisasi extends Model
{
    use SoftDeletes;

    protected $table = 'realisasi_batches';

    protected $fillable = [
        'advance_id',
        'batch_number',
        'uploaded_by',
        'notes',
    ];

    protected $casts = [
        'batch_number' => 'integer',
    ];

    // Relationships
    public function advance(): BelongsTo
    {
        return $this->belongsTo(Advance::class, 'advance_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RealisasiItem::class, 'realisasi_id');
    }

    // Helpers
    public function getTotalAmount(): float
    {
        return (float) $this->items->sum('subtotal');
    }

    public function getFileCount(): int
    {
        return $this->items->sum(fn($item) => $item->files->count());
    }

    public function getFiles(): array
    {
        return $this->items->flatMap(fn($item) => $item->files)->all();
    }

    // Get next batch number for this advance
    public static function getNextBatchNumber(int $advanceId): int
    {
        return (self::where('advance_id', $advanceId)->max('batch_number') ?? 0) + 1;
    }
}