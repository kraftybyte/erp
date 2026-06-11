<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Observers\AdvanceObserver;
use Spatie\Permission\Traits\HasRoles;


class Advance extends Model
{
    use HasRoles, SoftDeletes;

    protected static function boot(): void
    {
        parent::boot();
        static::observe(AdvanceObserver::class);
    }

    public const STATUS_PENDING = 'Pending';
    public const STATUS_DIBAYARKAN = 'Dibayarkan';
    public const STATUS_SELESAI = 'Selesai';
    public const STATUS_DITOLAK = 'Ditolak';
    public const STATUS_DISETUJUI = 'Disetujui';
    public const STATUS_REALIZED_USER = 'Sudah di-Realisasi User';

    protected $fillable = [
        'user_id',
        'submission_date',
        'project_id',
        'keterangan_advance',
        'amount',
        'attachment',
        'reference',
        'status',
        'rejection_reason',
        'attachment_pay',
        'total_harga',
        'payment_account_id',
        'paid',
        'paid_by',
        'realisasi_notes',
        'realisasi_at',
        'realisasi_by',
        'overrun_approved_by',
        'overrun_approved_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'total_harga' => 'decimal:2',
        'paid' => 'decimal:2',
        'submission_date' => 'date',
        'deleted_at' => 'datetime',
    ];

    protected $dates = ['deleted_at'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function itemAdvance()
    {
        return $this->hasMany(ItemAdvance::class);
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class, 'payment_account_id');
    }

    public function realizasis(): HasMany
    {
        return $this->hasMany(Realisasi::class, 'advance_id');
    }

    public function canRealisasi(): bool
    {
        return $this->status === self::STATUS_DIBAYARKAN;
    }

    public function isEditable(): bool
    {
        return $this->status !== self::STATUS_SELESAI;
    }
}