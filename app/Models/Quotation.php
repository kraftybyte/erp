<?php

namespace App\Models;
use App\Settings\GeneralSettings;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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

    /**
     * Nomor quotation: #DG/013YYMM/XXXXX
     *
     * Urutannya sengaja berlanjut lintas bulan (tidak reset seperti invoice),
     * jadi pencarian nomor tertinggi memang melihat seluruh quotation. Bedanya
     * sekarang nilai itu dihitung di database, bukan dengan menarik semua baris
     * ke memory; nomor yang sudah terpakai juga dilewati agar dua permintaan
     * bersamaan tidak menghasilkan nomor kembar.
     */
    public static function generateUuid(): string
    {
        $settings = app(GeneralSettings::class);
        $prefix = ($settings->quotation_prefix ?? 'DG');
        $awalan = '#' . $prefix . '/013' . now()->format('ym') . '/';

        $urutanTertinggi = (int) static::query()
            ->where('uuid', 'like', '#%/%/%')
            ->max(DB::raw('CAST(RIGHT(uuid, 5) AS UNSIGNED)'));

        for ($i = $urutanTertinggi + 1; $i <= $urutanTertinggi + 100; $i++) {
            $kandidat = $awalan . str_pad((string) $i, 5, '0', STR_PAD_LEFT);

            if (! static::where('uuid', $kandidat)->exists()) {
                return $kandidat;
            }
        }

        return $awalan . str_pad((string) ($urutanTertinggi + 1), 5, '0', STR_PAD_LEFT);
    }
        protected static function booted(): void
    {
        static::deleting(function (Quotation $quotation) {
            $quotation->items()->delete();
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    /**
     * Ditautkan lewat deal_id, bukan nama perusahaan: pencocokan nama putus
     * begitu nama Deal diubah dan tercampur bila ada dua Deal bernama sama.
     */
    public function deal()
    {
        return $this->belongsTo(Deal::class, 'deal_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

}
