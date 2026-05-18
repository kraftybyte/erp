<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Crypt;

class ConnectedAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'account_id',
        'username',
        'profile_picture',
        'access_token',
        'token_expires_at',
        'account_type',
        'name',
        'is_active',
        'last_synced_at',
        'metadata',
    ];

    protected $hidden = [
        'access_token',
    ];

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    // Encrypt token on save
    public function setAccessTokenAttribute(string $value): void
    {
        $this->attributes['access_token'] = Crypt::encryptString($value);
    }

    // Decrypt token on access
    public function getAccessTokenDecryptedAttribute(): ?string
    {
        if (empty($this->attributes['access_token'])) {
            return null;
        }

        try {
            return Crypt::decryptString($this->attributes['access_token']);
        } catch (\Exception $e) {
            return $this->attributes['access_token'];
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(ConnectedAccountMetric::class);
    }

    public function latestMetrics(): HasOne
    {
        return $this->hasOne(ConnectedAccountMetric::class)->latestOfMany('date');
    }

    public function metricsForPeriod(string $startDate, string $endDate): HasMany
    {
        return $this->hasMany(ConnectedAccountMetric::class)
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date');
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at && $this->token_expires_at->isPast();
    }

    public function needsSync(): bool
    {
        if (!$this->last_synced_at) {
            return true;
        }

        return $this->last_synced_at->diffInHours(now()) >= 6;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOfProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    public function scopeInstagram($query)
    {
        return $query->where('provider', 'instagram');
    }

    public function getProviderIconAttribute(): string
    {
        return match ($this->provider) {
            'instagram' => 'heroicon-o-camera',
            'facebook' => 'heroicon-o-globe-alt',
            'tiktok' => 'heroicon-o-play',
            'twitter' => 'heroicon-o-at-symbol',
            default => 'heroicon-o-user',
        };
    }

    public function getProviderColorAttribute(): string
    {
        return match ($this->provider) {
            'instagram' => 'danger',
            'facebook' => 'info',
            'tiktok' => 'dark',
            'twitter' => 'primary',
            default => 'secondary',
        };
    }
}