<?php

namespace App\Models;

use App\Services\CompanyDirectory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PIC / kontak sebuah perusahaan.
 */
class CompanyContact extends Model
{
    use SoftDeletes;

    protected $fillable = ['company_id', 'name', 'jabatan', 'telephone', 'email'];

    protected static function booted(): void
    {
        static::saving(fn (CompanyContact $contact) => $contact->name_key = CompanyDirectory::key($contact->name));

        // Nama PIC di Client ikut berganti; quotation & invoice yang sudah terbit tetap.
        static::updated(function (CompanyContact $contact) {
            if ($contact->wasChanged('name')) {
                Deal::where('contact_id', $contact->id)->update(['nama_pic' => $contact->name]);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'contact_id');
    }
}
