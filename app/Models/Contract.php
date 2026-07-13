<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contract extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'deal_id',
        'contract_number',
        'type',
        'content',
        'effective_date',
        'expiration_date',
        'file_path',
        'duration_months',
        'total_value',
        'down_payment_percent',
        'max_revisions',
        'platform',
        'content_count',
        'scope_of_work',
        'client_name_full',
        'client_address',
        'client_phone',
        'client_email',
        'client_signatory_name',
        'client_signatory_title',
        'agency_signatory_name',
        'agency_signatory_title',
        // Payment type
        'payment_type',
        'installment_months',
        // Meta/Additional
        'project_description',
        'additional_terms',
        'force_majeure_extended',
        'termination_notice_days',
        'penalty_clause',
        // Lampiran customization
        'lampiran_title',
        'lampiran_description',
        'table_a_title',
        'table_b_title',
        'table_b_description',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'expiration_date' => 'date',
        'total_value' => 'decimal:2',
        'down_payment_percent' => 'decimal:2',
        'installment_months' => 'integer',
        'content_count' => 'integer',
        'max_revisions' => 'integer',
        'termination_notice_days' => 'integer',
    ];

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ContractItem::class)->orderBy('sort_order');
    }

    public function feeItems(): HasMany
    {
        return $this->hasMany(ContractItem::class)->where('type', 'fee')->orderBy('sort_order');
    }

    public function thirdPartyItems(): HasMany
    {
        return $this->hasMany(ContractItem::class)->where('type', 'third_party')->orderBy('sort_order');
    }

    public function sowItems(): HasMany
    {
        return $this->hasMany(ContractItem::class)->where('type', 'sow')->orderBy('sort_order');
    }

    /**
     * SOW Categories - Parent level items with children loaded
     */
    public function sowCategories(): HasMany
    {
        return $this->hasMany(ContractItem::class)
            ->where('type', 'sow')
            ->whereNull('parent_id')
            ->with('children')
            ->orderBy('sort_order');
    }

    /**
     * Generate contract number
     */
    public static function generateContractNumber(): string
    {
        $month = now()->format('m');
        $year = now()->format('y');

        // Get last contract number
        $lastContract = static::whereNotNull('contract_number')
            ->orderBy('id', 'desc')
            ->first();

        $nextNum = 1;
        if ($lastContract && preg_match('/(\d+)\/SPK\//', $lastContract->contract_number ?? '', $matches)) {
            $nextNum = ((int)$matches[1]) + 1;
        }

        return str_pad($nextNum, 3, '0', STR_PAD_LEFT) . '/SPK/' . $month . $year;
    }
}