<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class TaskStage extends Model
{
    use HasFactory;

    protected $table = 'task_stages';

    protected $fillable = [
        'key',
        'name',
        'color',
        'background_color',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Boot method untuk observe changes
     */
    protected static function boot()
    {
        parent::boot();

        // Clear cache when stages change
        static::saved(function () {
            self::clearCache();
        });

        static::deleted(function () {
            self::clearCache();
        });
    }

    /**
     * Clear stages cache
     */
    public static function clearCache(): void
    {
        Cache::forget('task_stages_active');
        Cache::forget('task_stages_all');
    }

    /**
     * Get all active stages ordered by sort_order
     */
    public static function getActiveStages(): Collection
    {
        return Cache::rememberForever('task_stages_active', function () {
            return self::where('is_active', true)
                ->orderBy('sort_order')
                ->get();
        });
    }

    /**
     * Get all stages (including inactive)
     */
    public static function getAllStages(): Collection
    {
        return Cache::rememberForever('task_stages_all', function () {
            return self::orderBy('sort_order')->get();
        });
    }

    /**
     * Get stage by key
     */
    public static function getByKey(string $key): ?self
    {
        return self::where('key', $key)->first();
    }

    /**
     * Get stage options for select dropdown
     */
    public static function getOptions(): array
    {
        return self::getActiveStages()
            ->pluck('name', 'key')
            ->toArray();
    }

    /**
     * Get stage colors
     */
    public static function getColors(): array
    {
        return self::getActiveStages()
            ->mapWithKeys(fn ($stage) => [$stage->key => [
                'color' => $stage->color,
                'background' => $stage->background_color,
            ]])
            ->toArray();
    }

    /**
     * Check if a key exists
     */
    public static function keyExists(string $key): bool
    {
        return self::where('key', $key)->where('is_active', true)->exists();
    }

    /**
     * Get key by name (case insensitive)
     */
    public static function getKeyByName(string $name): ?string
    {
        $stage = self::where('name', $name)->first();
        return $stage?->key;
    }

    /**
     * Scope for active stages
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope ordered
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    /**
     * Reorder stages
     */
    public static function reorder(array $stageIds): void
    {
        foreach ($stageIds as $order => $id) {
            self::where('id', $id)->update(['sort_order' => $order]);
        }
        self::clearCache();
    }

    /**
     * Get tasks that belong to this stage
     */
    public function tasks()
    {
        return $this->hasMany(Task::class, 'stage_key', 'key');
    }
}
