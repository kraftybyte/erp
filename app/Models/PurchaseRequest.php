<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_name',
        'quantity',
        'description',
        'reference_link',
        'price',
        'status',
        'reason',
        'requested_by',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'status' => 'string',
    ];

    /*
    | Nilai yang tersimpan mengikuti kolom enum('pending','approved','rejected').
    | Dulu kode menulis "Disetujui"/"Ditolak", yang ditolak database sehingga
    | Approve/Reject selalu error. Labelnya tetap berbahasa Indonesia.
    */
    public const STATUS_LABELS = [
        'pending'  => 'Pending',
        'approved' => 'Disetujui',
        'rejected' => 'Ditolak',
    ];

    public static function statusLabel(?string $status): string
    {
        return self::STATUS_LABELS[strtolower((string) $status)] ?? (string) $status;
    }

    /**
     * Get the user who requested the purchase.
     */
    public function user()
        {
            return $this->belongsTo(User::class, 'requested_by');
        }

}