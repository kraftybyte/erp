<?php

namespace App\Enums;

use App\Models\TaskStage;
use Mokhosh\FilamentKanban\Concerns\IsKanbanStatus;
use Illuminate\Support\Collection;

/**
 * TaskStages Enum - Database-backed enum for Kanban board stages
 *
 * This enum wraps the TaskStage database model for backward compatibility
 * with the Mokhosh FilamentKanban package while allowing dynamic configuration.
 *
 * Usage:
 *   - For static enum cases (backward compat): TaskStages::cases()
 *   - For dynamic stages from DB: TaskStage::getActiveStages()
 *   - For options array: TaskStage::getOptions()
 *
 * Admin can configure stages at: /admin/task-stages
 */
enum TaskStages: string
{
    use IsKanbanStatus;

    // Static cases for backward compatibility
    // These match the default database values
    case Log = 'log';
    case Process = 'process';
    case Review = 'review';
    case Done = 'done';
    case Hold = 'hold';

    /**
     * Get all enum cases as array
     */
    public static function casesArray(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }

    /**
     * Get enum options for Filament Select (value => label)
     * Uses database values if available, falls back to enum
     */
    public static function getOptions(): array
    {
        // Try to get from database first
        $dbOptions = TaskStage::getOptions();

        if (!empty($dbOptions)) {
            return $dbOptions;
        }

        // Fallback to enum
        return array_combine(
            self::casesArray(),
            array_map(fn ($case) => ucfirst($case->value), self::casesArray())
        );
    }

    /**
     * Get all stages (from DB or enum)
     */
    public static function getStages(): Collection
    {
        $dbStages = TaskStage::getActiveStages();

        if ($dbStages->isNotEmpty()) {
            return $dbStages;
        }

        // Fallback: convert enum to collection format
        return collect(self::cases())->map(function ($case, $index) {
            return (object) [
                'key' => $case->value,
                'name' => ucfirst($case->value),
                'sort_order' => $index,
            ];
        });
    }

    /**
     * Get stage colors from database
     */
    public static function getColors(): array
    {
        return TaskStage::getColors();
    }

    /**
     * Get color for a specific stage key
     */
    public static function getColor(string $key): string
    {
        $colors = self::getColors();
        return $colors[$key]['color'] ?? '#94a3b8';
    }

    /**
     * Get background color for a specific stage key
     */
    public static function getBackgroundColor(string $key): string
    {
        $colors = self::getColors();
        return $colors[$key]['background'] ?? '#f8fafc';
    }

    /**
     * Check if a key exists in active stages
     */
    public static function keyExists(string $key): bool
    {
        if (TaskStage::keyExists($key)) {
            return true;
        }

        // Check enum
        return in_array($key, self::casesArray());
    }

    /**
     * Get label for display
     */
    public function label(): string
    {
        // Try database first
        $stage = TaskStage::getByKey($this->value);
        if ($stage) {
            return $stage->name;
        }

        // Fallback to ucfirst
        return ucfirst($this->value);
    }

    /**
     * Get color for this enum case
     */
    public function color(): string
    {
        return self::getColor($this->value);
    }

    /**
     * Get background color for this enum case
     */
    public function backgroundColor(): string
    {
        return self::getBackgroundColor($this->value);
    }
}
