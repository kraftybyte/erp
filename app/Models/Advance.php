<?php

namespace App\Models;

use App\Services\AdvanceParallel;
use App\Services\AutoApproval;
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
        static::creating(function (Advance $advance) {
            AutoApproval::apply($advance);

            // Selama bayar paralel menyala, advance baru langsung memakai alurnya.
            if (AdvanceParallel::enabled()) {
                $advance->pay_parallel = true;
            }
        });
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
        'attachments',
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
        'pay_parallel',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'total_harga' => 'decimal:2',
        'paid' => 'decimal:2',
        'submission_date' => 'date',
        'deleted_at' => 'datetime',
        'paid_at' => 'datetime',
        'attachments' => 'array',
        'pay_parallel' => 'boolean',
    ];

    protected $dates = ['deleted_at'];

    /**
     * Semua lampiran pengajuan; data lama hanya punya kolom `attachment`.
     *
     * @return array<int, string>
     */
    public function attachmentPaths(): array
    {
        return $this->attachments ?: array_filter([$this->attachment]);
    }

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
        // Batch pertama: setelah dibayar, atau sejak Disetujui untuk bayar paralel.
        if ($this->status === self::STATUS_DIBAYARKAN) {
            return true;
        }

        if ($this->status === self::STATUS_DISETUJUI) {
            return AdvanceParallel::allows($this);
        }

        // Batch berikutnya: selama belum melebihi dasar realisasi, atau kelebihannya sudah disetujui.
        if ($this->status === self::STATUS_REALIZED_USER) {
            $totalRealisasi = $this->realizasis->sum(fn($r) => $r->items->sum('subtotal'));

            return $this->realisasiBasis() - $totalRealisasi >= 0 || $this->overrun_approved_by;
        }

        return false;
    }

    public function paysParallel(): bool
    {
        return (bool) $this->pay_parallel;
    }

    /**
     * Dana yang menjadi acuan realisasi: yang dicairkan, atau untuk bayar
     * paralel nominal yang disetujui (pembayarannya menyusul).
     */
    public function realisasiBasis(): float
    {
        return $this->paysParallel() ? max((float) $this->amount, (float) $this->paid) : (float) $this->paid;
    }

    /** Total realisasi; memakai relasi yang sudah dimuat bila ada. */
    public function realizedTotal(): float
    {
        return $this->relationLoaded('realizasis')
            ? (float) $this->realizasis->sum(fn ($batch) => $batch->items->sum('subtotal'))
            : app(\App\Services\RealisasiService::class)->getTotalRealisasi($this);
    }

    /**
     * "Cair Rp 5.000.000", atau untuk bayar paralel "Disetujui Rp 5.000.000 ·
     * dibayar Rp 2.000.000".
     */
    public function fundLabel(): string
    {
        $rupiah = fn ($amount) => 'Rp ' . number_format((float) $amount, 0, ',', '.');

        return $this->paysParallel()
            ? 'Disetujui ' . $rupiah($this->amount) . ' · dibayar ' . $rupiah($this->paid)
            : 'Cair ' . $rupiah($this->paid);
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
        $basis = $this->realisasiBasis();

        if ($basis <= 0) {
            return 0.0;
        }

        $totalRealisasi = $this->realizasis->flatMap->items->sum('subtotal');

        return round(min(($totalRealisasi / $basis) * 100, 100), 2);
    }
}