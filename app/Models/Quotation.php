<?php

namespace App\Models;
use App\Settings\GeneralSettings;
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
        $settings = app(GeneralSettings::class);
        $prefix = ($settings->quotation_prefix ?? 'DG');
        $datePart = now()->format('ym'); // Format: YYMM

        // Get highest sequence number across ALL quotations (continue sequence)
        $allQuotations = self::where('uuid', 'like', '#%/%/%')->get();

        $highestSeq = 0;
        foreach ($allQuotations as $q) {
            $seq = intval(substr($q->uuid, -5));
            if ($seq > $highestSeq) {
                $highestSeq = $seq;
            }
        }

        $newNumber = str_pad($highestSeq + 1, 5, '0', STR_PAD_LEFT);

        return '#' . $prefix . '/013' . $datePart . '/' . $newNumber;
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function deal()
    {
        return $this->belongsTo(Deal::class, 'nama_perusahaan', 'nama_perusahaan');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

}
