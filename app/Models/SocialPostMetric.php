<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialPostMetric extends Model
{
    protected $fillable = [
        'social_post_id',
        'likes',
        'comments',
        'reach',
        'impressions',
        'saved',
        'video_views',
        'shares',
        'reactions',
        'clicks',
        'profile_visits',
        'negative_feedback',
        'engagement_rate',
        'synced_at',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }

    public static function updateOrCreate(array $attributes, array $values = []): static
    {
        $instance = static::firstOrNew($attributes);
        $instance->fill($values);
        $instance->save();
        return $instance;
    }
}