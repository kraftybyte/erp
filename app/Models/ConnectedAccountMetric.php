<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectedAccountMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'connected_account_id',
        'date',
        'followers',
        'following',
        'media_count',
        'reach',
        'impressions',
        'likes',
        'comments',
        'saves',
        'shares',
        'engagement',
        'profile_visits',
        'website_clicks',
        'engagement_rate',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class, 'connected_account_id');
    }

    public function getTotalEngagementAttribute(): int
    {
        return ($this->likes ?? 0)
            + ($this->comments ?? 0)
            + ($this->saves ?? 0)
            + ($this->shares ?? 0);
    }

    public function getCalculatedEngagementRateAttribute(): float
    {
        if (!$this->followers || $this->followers === 0) {
            return 0.0;
        }

        return round(($this->total_engagement / $this->followers) * 100, 4);
    }

    public function scopeForPeriod($query, string $startDate, string $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('date', now()->toDateString());
    }

    public function scopeLast7Days($query)
    {
        return $query->whereBetween('date', [now()->subDays(6)->toDateString(), now()->toDateString()]);
    }

    public function scopeLast30Days($query)
    {
        return $query->whereBetween('date', [now()->subDays(29)->toDateString(), now()->toDateString()]);
    }
}