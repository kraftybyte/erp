<?php

namespace App\Models;

use TomatoPHP\FilamentInvoices\Models\InvoicesItem as BaseInvoicesItem;

/**
 * Extended InvoicesItem model with original_price support
 *
 * @property float|null $original_price
 */
class InvoicesItem extends BaseInvoicesItem
{
    protected $fillable = [
        'invoice_id',
        'type',
        'item_type',
        'item_id',
        'item',
        'description',
        'note',
        'qty',
        'price',
        'discount',
        'vat',
        'total',
        'returned_qty',
        'returned',
        'is_free',
        'is_returned',
        'options',
        'original_price',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'is_free' => 'bool',
        'is_returned' => 'bool',
        'options' => 'json',
        'original_price' => 'float',
    ];
}
