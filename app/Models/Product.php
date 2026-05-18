<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'nama_layanan',
        'detail_layanan',
        'harga_layanan',
        'pajak',
        'total_harga',
    ];

    protected $casts = [
        'pajak' => 'array', // Cast pajak sebagai array
    ];


    public function setPajakAttribute($value)
    {
        $this->attributes['pajak'] = json_encode($value);
    }

    public function setTotalHargaAttribute()
    {
        $pajakValues = array_map('floatval', $this->pajak ?? []);
        $totalPajak = array_sum($pajakValues) / 100;
        $this->attributes['total_harga'] = $this->harga_layanan + ($this->harga_layanan * $totalPajak);
    }
    public function leads()
    {
        return $this->belongsToMany(Leads::class, 'lead_product', 'product_id', 'lead_id');
    }
    public function project()
    {
        return $this->hasMany(project::class);
    }
    public function deals()
    {
        return $this->belongsToMany(Deal::class, 'deal_product', 'product_id', 'deal_id');
    }
}
