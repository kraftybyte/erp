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
    public const STATUS_REALIZING = 'Sedang di-Realisasi';
    public const STATUS_REALIZED_USER = 'Sedang di-Realisasi User';
    public const STATUS_READY_TO_CLOSE = 'Siap Diselesaikan';

    protected $fillable = [
        'user_id',
        'submission_date',
        'project_id',
        'keterangan_advance',
        'category',
        'amount',
        'attachment',
        'reference_number',
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
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'total_harga' => 'decimal:2',
        'paid' => 'decimal:2',
        'submission_date' => 'date',
        'deleted_at' => 'datetime',
        'paid_at' => 'datetime',
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
        // Can realize if status is "Dibayarkan" (first batch)
        // Or if status is "Sudah di-Realisasi User" and overrun is approved or not exceeded
        if ($this->status === self::STATUS_DIBAYARKAN) {
            return true;
        }

        if ($this->status === self::STATUS_REALIZED_USER) {
            $totalRealisasi = $this->realizasis->sum(fn($r) => $r->items->sum('subtotal'));
            $remaining = (float) $this->paid - $totalRealisasi;

            // Can add more if not overrun, or if overrun is approved
            return $remaining >= 0 || $this->overrun_approved_by;
        }

        return false;
    }

    public function isEditable(): bool
    {
        return $this->status !== self::STATUS_SELESAI;
    }

    /**
     * Warna badge mengikuti urutan alur advance, bukan ColorHelper::status()
     * yang generik: di sana status realisasi tidak dikenali dan jatuh ke abu-abu.
     *
     * abu  = belum bergerak, kuning = menunggu tindakan,
     * biru = sedang berjalan, hijau = tuntas, merah = berhenti.
     */
    public function getStatusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING        => 'gray',
            self::STATUS_DISETUJUI      => 'warning',
            self::STATUS_DIBAYARKAN     => 'info',
            self::STATUS_REALIZING      => 'info',
            self::STATUS_REALIZED_USER  => 'warning',
            self::STATUS_READY_TO_CLOSE => 'warning',
            self::STATUS_SELESAI        => 'success',
            self::STATUS_DITOLAK        => 'danger',
            default                     => 'gray',
        };
    }

    /**
     * Label status untuk ditampilkan ke pengguna.
     */
    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING        => 'Pending',
            self::STATUS_DISETUJUI      => 'Disetujui',
            self::STATUS_DIBAYARKAN     => 'Dibayarkan',
            self::STATUS_REALIZING      => 'Sedang di-Realisasi',
            self::STATUS_REALIZED_USER  => 'Menunggu Verifikasi Finance',
            self::STATUS_READY_TO_CLOSE => 'Siap Diselesaikan',
            self::STATUS_SELESAI        => 'Selesai',
            self::STATUS_DITOLAK        => 'Ditolak',
            default                     => (string) $this->status,
        };
    }

    /**
     * Berapa persen dana yang sudah dipertanggungjawabkan lewat realisasi.
     * Dibatasi 0–100 supaya overrun tidak membuat progress bar melebihi penuh.
     */
    public function getProgressPercentage(): float
    {
        $paid = (float) $this->paid;

        if ($paid <= 0) {
            return 0.0;
        }

        $totalRealisasi = $this->realizasis->flatMap->items->sum('subtotal');

        return round(min(($totalRealisasi / $paid) * 100, 100), 2);
    }
}