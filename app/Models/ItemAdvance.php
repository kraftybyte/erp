<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItemAdvance extends Model
{
    use HasFactory;

    protected $table = 'item_advance';

    protected $fillable = [
        'advance_id', 'name', 'description', 'quantity', 'price',
    ];

    public function advance()
    {
        return $this->belongsTo(Advance::class);
    }

    public function getSubtotalAttribute()
    {
        return $this->quantity * $this->price;
    }
}
