<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccountInsight extends Model
{
    protected $fillable = [
        'social_account_id',
        'date',
        'reach',
        'impressions',
        'follower_count',
        'likes',
        'comments',
        'shares',
        'video_views',
        'saves',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public static function updateOrCreate(array $attributes, array $values = []): static
    {
        $instance = static::firstOrNew($attributes);
        $instance->fill($values);
        $instance->save();
        return $instance;
    }
}