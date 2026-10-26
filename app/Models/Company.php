<?php

namespace App\Models;

use App\Services\CompanyDirectory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Perusahaan klien. PIC-nya di CompanyContact; setiap Client (Deal) menunjuk
 * perusahaan dan PIC untuk order itu.
 */
class Company extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'alamat', 'npwp', 'notes'];

    protected static function booted(): void
    {
        static::saving(fn (Company $company) => $company->name_key = CompanyDirectory::key($company->name));

        // Nama di Client ikut berganti; quotation & invoice yang sudah terbit tetap.
        static::updated(function (Company $company) {
            if ($company->wasChanged('name')) {
                Deal::where('company_id', $company->id)->update(['nama_perusahaan' => $company->name]);
            }
        });
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CompanyContact::class)->orderBy('name');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'merged_into_id')->withTrashed();
    }
}
