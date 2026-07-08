<?php

namespace App\Models;

use TomatoPHP\FilamentInvoices\Models\Invoice as BaseInvoice;

/**
 * Extended Invoice model with PaymentAccount support
 *
 * @property int|null $payment_account_id
 */
class Invoice extends BaseInvoice
{
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
}