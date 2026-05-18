<?php
// app/Models/SocialAccount.php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SocialAccount extends Model
{
    use SoftDeletes;

    protected $table = 'social_accounts';

    protected $fillable = [
        'client_id',
        'platform',
        'platform_account_id',
        'username',
        'page_name',
        'profile_picture_url',
        'followers_count',
        'following_count',
        'media_count',
        'fan_count',
        'access_token',
        'is_active',
        'last_synced_at',
        'sync_status',
        'last_sync_error',
        'last_sync_started_at',
        'sync_metadata',
        'token_expires_at',
    ];

    // 🔐 PENTING: Enkripsi token otomatis
    protected $casts = [
        'access_token'         => 'encrypted',
        'is_active'            => 'boolean',
        'last_synced_at'       => 'datetime',
        'last_sync_started_at' => 'datetime',
        'last_sync_attempt'    => 'datetime',
        'token_expires_at'     => 'datetime',
        'sync_metadata'        => 'array',
    ];

    // Sembunyikan token dari API response
    protected $hidden = [
        'access_token',
        'sync_metadata',
    ];

    /*
    |--------------------------------------------------------------------------
    | SCOPES (Query Filters)
    |--------------------------------------------------------------------------
    */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePlatform(Builder $query, string $platform): Builder
    {
        return $query->where('platform', $platform);
    }

    public function scopeNeedsSync(Builder $query, int $minutes = 60): Builder
    {
        return $query->active()
            ->where(function ($q) use ($minutes) {
                $q->whereNull('last_synced_at')
                  ->orWhere('last_synced_at', '<', now()->subMinutes($minutes));
            });
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('sync_status', 'failed');
    }

    public function scopeSyncable(Builder $query): Builder
    {
        return $query->active()
            ->where('sync_status', '!=', 'syncing')
            ->where(function ($q) {
                $q->whereNull('last_sync_started_at')
                  ->orWhere('last_sync_started_at', '<', now()->subMinutes(5));
            });
    }

    /*
    |--------------------------------------------------------------------------
    | RELATIONSHIPS
    |--------------------------------------------------------------------------
    */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'client_id');
    }

    public function insights(): HasMany
    {
        return $this->hasMany(SocialAccountInsight::class)->orderBy('date', 'desc');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(SocialPost::class)->orderBy('posted_at', 'desc');
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SocialSyncLog::class)->orderBy('created_at', 'desc');
    }

    /*
    |--------------------------------------------------------------------------
    | ACCESSORS & HELPERS
    |--------------------------------------------------------------------------
    */
    public function getDisplayNameAttribute(): string
    {
        return $this->username ?? $this->page_name ?? "Account #{$this->id}";
    }

    public function getPlatformLabelAttribute(): string
    {
        return match($this->platform) {
            'instagram' => 'Instagram',
            'facebook'  => 'Facebook Page',
            default     => ucfirst($this->platform),
        };
    }

    public function hasValidToken(): bool
    {
        if (!filled($this->access_token) || !$this->is_active) {
            return false;
        }
        
        if ($this->token_expires_at && $this->token_expires_at->isPast()) {
            return false;
        }
        
        return true;
    }

    public function getSyncStatusBadgeConfigAttribute(): array
    {
        return match($this->sync_status) {
            'pending'   => ['color' => 'gray', 'icon' => 'heroicon-o-clock', 'label' => 'Pending'],
            'syncing'   => ['color' => 'warning', 'icon' => 'heroicon-o-arrow-path', 'label' => 'Syncing'],
            'completed' => ['color' => 'success', 'icon' => 'heroicon-o-check-circle', 'label' => 'Completed'],
            'failed'    => ['color' => 'danger', 'icon' => 'heroicon-o-exclamation-triangle', 'label' => 'Failed'],
            default     => ['color' => 'gray', 'icon' => 'heroicon-o-question-mark-circle', 'label' => 'Unknown'],
        };
    }

    public function isInstagram(): bool
    {
        return $this->platform === 'instagram';
    }

    public function isFacebook(): bool
    {
        return $this->platform === 'facebook';
    }

    public function isMeta(): bool
    {
        return in_array($this->platform, ['instagram', 'facebook']);
    }

    protected function platform(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => strtolower($value),
        );
    }
}