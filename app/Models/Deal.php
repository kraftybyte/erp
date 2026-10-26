<?php

namespace App\Models;

use App\Services\CompanyDirectory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'company_id',
        'contact_id',
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

    protected static function booted(): void
    {
        // Tautkan ke Perusahaan & PIC, dan selaraskan teks nama_perusahaan/nama_pic.
        static::saving(fn (Deal $deal) => app(CompanyDirectory::class)->syncDeal($deal));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * PIC untuk order ini; perusahaan yang sama bisa punya PIC berbeda per order.
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(CompanyContact::class, 'contact_id');
    }

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