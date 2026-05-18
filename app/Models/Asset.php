<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'nama_barang',
        'tanggal_pembelian',
        'deskripsi',
        'pembelian_dari',
        'kondisi',
        'maintenance_date',
        'penanggung_jawab_id',
        'bon_invoice',
        'harga_barang',
    ];

    // Definisi relasi ke User (Penanggung Jawab)
    public function user()
        {
            return $this->belongsTo(User::class, 'penanggung_jawab_id');
        }
}
