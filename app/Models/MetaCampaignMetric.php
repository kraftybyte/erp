<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaCampaignMetric extends Model
{
    protected $fillable = [
        'meta_ad_account_id', 'campaign_id', 'campaign_name',
        'status', 'objective', 'date', 'spend', 'impressions',
        'clicks', 'reach', 'conversions', 'ctr', 'cpc', 'cpm', 'roas',
    ];

    protected $casts = [
        'date'  => 'date',
        'spend' => 'decimal:2',
    ];

    public function adAccount(): BelongsTo
    {
        return $this->belongsTo(MetaAdAccount::class, 'meta_ad_account_id');
    }
}