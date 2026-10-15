<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Product;
use App\Models\Task;
use App\Models\Leads;
use App\Models\Project;
use App\Models\Invoice;


class Deal extends Model
{
    use HasFactory;
    protected $table = 'deals';

    protected $fillable = [
        'lead_id',
        'nama_pic',
        'nama_perusahaan',
        'email',
        'telephone',
        'source',
        'alamat',
        'NPWP',
        'notes',
        'status',
        'product_id',
    ];

    public function product()
    {
        return $this->belongsToMany(Product::class, 'deal_product', 'deal_id', 'product_id');
    }

    public function lead()
    {
        return $this->belongsTo(Leads::class, 'lead_id');
    }

    public function project()
    {
        return $this->hasMany(Project::class);
    }

    public function tasks()
    {
        return $this->hasManyThrough(
            Task::class,
            Project::class,
            'deal_id',
            'project_id',
            'id',
            'id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Invoice & Quotation ditautkan lewat deal_id, bukan nama perusahaan.
    |--------------------------------------------------------------------------
    |
    | Sebelumnya keduanya dicocokkan lewat kolom nama_perusahaan. Tautan seperti
    | itu putus begitu nama perusahaan diubah, dan mencampur data begitu ada dua
    | Deal yang namanya sama. deal_id sudah di-backfill lewat migration dan kini
    | selalu diisi saat invoice/quotation dibuat.
    |--------------------------------------------------------------------------
    */

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'deal_id');
    }

    public function quotations()
    {
        return $this->hasMany(Quotation::class, 'deal_id');
    }

    public function contracts()
    {
        return $this->hasMany(Contract::class);
    }
}