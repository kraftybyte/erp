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
        'payment_account_id',
        // Keep original fillable fields
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
    ];

    /**
     * Relationship with PaymentAccount
     */
    public function paymentAccount()
    {
        return $this->belongsTo(PaymentAccount::class);
    }
}