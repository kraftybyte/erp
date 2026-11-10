<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Komponen gaji tetap seorang karyawan: pendapatan (gaji pokok, tunjangan) atau
 * potongan (BPJS, PPh 21). Disalin ke slip gaji setiap bulan.
 */
class PayrollComponent extends Model
{
    public const EARNING = 'earning';
    public const DEDUCTION = 'deduction';

    public const TYPES = [
        self::EARNING   => 'Pendapatan',
        self::DEDUCTION => 'Potongan',
    ];

    protected $fillable = ['user_id', 'name', 'type', 'amount', 'sort'];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
