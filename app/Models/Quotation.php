<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Quotation extends Model
{
    protected $fillable = [
        'nama_perusahaan',
        'telephone',
        'email',
        'status',
        'subtotal',
        'total_vat',
        'total_discount',
        'grand_total',
        'nama_pic',
        'date',
        'due_date',
        'uuid',
    ];

    public static function generateUuid(): string
    {
        // Prefix tetap #DG/
        $prefix = '#DG/';

        // Format bulan dan 2 digit terakhir tahun (MMYY)
        $datePart = now()->format('my') . '/';

        // Cari quotation terakhir dengan prefix dan tanggal yang sama
        $lastQuotation = self::where('uuid', 'like', $prefix . $datePart . '%')
            ->latest('id')
            ->first();

        // Ambil nomor terakhir dari UUID terakhir
        $lastNumber = $lastQuotation ? intval(substr($lastQuotation->uuid, -5)) : 0;

        // Tambah 1 untuk nomor baru
        $newNumber = str_pad($lastNumber + 1, 5, '0', STR_PAD_LEFT);

        return $prefix . $datePart . $newNumber;
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

}
