<?php

namespace App\Models;

use App\Filament\Resources\ProductResource\Pages\ListProducts;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;



class Leads extends Model
{
    use HasFactory;

    protected $table = 'leads';
    
    protected $fillable = [
        'nama_pic',
        'nama_perusahaan',
        'email',
        'telephone',
        'source',
        'status',
        'is_converted',
        'product_id',
    ];

    public function product()
    {
        return $this->belongsToMany(Product::class, 'lead_product', 'lead_id', 'product_id');
    }

    public function deal()
    {
        return $this->hasOne(Deal::class); // A Lead can be converted to one Deal
    }
}
