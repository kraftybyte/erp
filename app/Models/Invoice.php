<?php

namespace App\Models;

use TomatoPHP\FilamentInvoices\Models\Invoice as BaseInvoice;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Extended Invoice model with PaymentAccount support and SoftDeletes
 *
 * @property int|null $payment_account_id
 */
class Invoice extends BaseInvoice
{
    use SoftDeletes;

    protected $fillable = [
        // === PaymentAccount integration ===
        'payment_account_id',

        // === Bank fields ===
        'bank_account',
        'bank_account_owner',
        'bank_iban',
        'bank_swift',
        'bank_address',
        'bank_branch',
        'bank_name',
        'bank_city',
        'bank_country',
        'is_bank_transfer',

        // === Core invoice fields ===
        'currency_id',
        'user_id',
        'uuid',
        'nama_perusahaan',
        'telephone',
        'alamat',
        'type',
        'description',
        'status',
        'subtotal',
        'grand_total',
        'total_discount',
        'total_vat',
        'vat_percentage',
        'paid',
        'date',
        'due_date',
        'invoice_id',
        'is_default',
        'total',
        'name',
        'for_type',
        'for_id',
        'from_type',
        'from_id',
        'type_payment',
        'po_number',
        'faktur',

        // === Missing fields from base model ===
        'is_activated',
        'is_offer',
        'send_email',
        'notes',
        'order_id',
        'category_id',
        'shipping',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'date' => 'datetime',
        'is_offer' => 'bool',
        'is_activated' => 'bool',
        'send_email' => 'bool',
        'is_bank_transfer' => 'bool',
    ];

    /**
     * Relationship with PaymentAccount
     */
    public function paymentAccount()
    {
        return $this->belongsTo(PaymentAccount::class);
    }

    /**
     * Generate UUID based on type and reset per month
     * Format: #DG/0132607/XXXXX or #PI/0132607/XXXXX
     */
    public static function generateUuid(string $type = 'sale'): string
    {
        $prefix = match ($type) {
            'estimate' => 'PI',
            'sale' => 'DG',
            default => 'DG',
        };

        // Format: 013YYMM (e.g., 0132607 for July 2026)
        $datePart = now()->format('ym');

        // Pattern to match: #PREFIX/013YYMM/XXXXX
        $pattern = '#' . $prefix . '/013' . $datePart . '/%';

        // Find highest sequence for this month (including soft-deleted)
        $highestSeq = self::withTrashed()
            ->where('uuid', 'like', $pattern)
            ->get()
            ->map(fn($inv) => intval(substr($inv->uuid, -5)))
            ->max() ?? 0;

        $newNumber = str_pad($highestSeq + 1, 5, '0', STR_PAD_LEFT);

        return '#' . $prefix . '/013' . $datePart . '/' . $newNumber;
    }
}