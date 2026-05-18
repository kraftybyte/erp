<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MetaAdAccount extends Model
{
    protected $fillable = [
        'client_id', 'ad_account_id', 'account_name',
        'access_token', 'token_expires_at', 'is_active', 'last_synced_at',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'last_synced_at'   => 'datetime',
        'is_active'        => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'client_id');
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(MetaCampaignMetric::class);
    }

    public function isTokenExpired(): bool
    {
        if (!$this->token_expires_at) return false;
        return $this->token_expires_at->isPast();
    }
}