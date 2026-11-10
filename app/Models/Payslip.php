<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Slip gaji satu karyawan untuk satu bulan.
 *
 * draft: dibuat dari komponen tetap, masih bisa diubah Finance;
 * final: dikunci dan dikirim ke karyawan;
 * paid:  sudah dibayar, tercatat sebagai uang keluar kategori Gaji.
 */
class Payslip extends Model
{
    public const DRAFT = 'draft';
    public const FINAL = 'final';
    public const PAID = 'paid';

    public const STATUSES = [
        self::DRAFT => 'Draft',
        self::FINAL => 'Final',
        self::PAID  => 'Dibayar',
    ];

    protected $fillable = [
        'user_id', 'period', 'status',
        'employee_name', 'employee_nik', 'position', 'division', 'npwp',
        'earnings', 'deductions', 'net', 'notes',
        'finalized_at', 'finalized_by', 'sent_at',
        'paid_at', 'paid_by', 'payment_account_id',
    ];

    protected $casts = [
        'period'       => 'date',
        'paid_at'      => 'date',
        'finalized_at' => 'datetime',
        'sent_at'      => 'datetime',
        'earnings'     => 'decimal:2',
        'deductions'   => 'decimal:2',
        'net'          => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayslipItem::class)->orderBy('sort')->orderBy('id');
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    /** "November 2026" */
    public function periodLabel(): string
    {
        return Carbon::parse($this->period)->locale('id')->translatedFormat('F Y');
    }

    /**
     * Hitung ulang total dari baris slip: pendapatan − potongan = take home pay.
     */
    public function recalculate(): void
    {
        $items = $this->items()->get();

        $earnings   = round((float) $items->where('type', PayrollComponent::EARNING)->sum('amount'), 2);
        $deductions = round((float) $items->where('type', PayrollComponent::DEDUCTION)->sum('amount'), 2);

        $this->forceFill(['earnings' => $earnings, 'deductions' => $deductions, 'net' => round($earnings - $deductions, 2)])->save();
    }

    public function fileName(): string
    {
        return 'Slip Gaji ' . Carbon::parse($this->period)->format('Y-m') . ' ' . preg_replace('/[^\pL\pN ]+/u', '', $this->employee_name) . '.pdf';
    }
}
