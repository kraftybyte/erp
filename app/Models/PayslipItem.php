<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris slip gaji: salinan komponen tetap (from_component), atau penyesuaian
 * bulan itu (lembur, bonus, potongan sekali).
 */
class PayslipItem extends Model
{
    protected $fillable = ['payslip_id', 'name', 'type', 'amount', 'sort', 'from_component'];

    protected $casts = [
        'amount'         => 'decimal:2',
        'from_component' => 'boolean',
    ];

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}
