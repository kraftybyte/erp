<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SocialPost extends Model
{
    protected $fillable = [
        'social_account_id',
        'platform_post_id',
        'media_type',
        'caption',
        'permalink',
        'thumbnail_url',
        'media_url',
        'posted_at',
    ];

    protected $casts = [
        'posted_at' => 'datetime',
    ];

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function metrics(): HasOne
    {
        return $this->hasOne(SocialPostMetric::class);
    }

    public static function updateOrCreate(array $attributes, array $values = []): static
    {
        $instance = static::firstOrNew($attributes);
        $instance->fill($values);
        $instance->save();
        return $instance;
    }
}