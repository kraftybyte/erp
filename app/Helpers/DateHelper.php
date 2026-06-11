<?php

namespace App\Helpers;

use Carbon\Carbon;

class DateHelper
{
    /**
     * Calculate business days between two dates (excluding Saturday & Sunday)
     *
     * @param Carbon|string $start
     * @param Carbon|string $end
     * @param bool $inclusive Include start and end date in calculation
     * @return int
     */
    public static function businessDaysBetween($start, $end, bool $inclusive = true): int
    {
        $start = Carbon::parse($start)->startOfDay();
        $end = Carbon::parse($end)->startOfDay();

        if ($start->gt($end)) {
            return 0;
        }

        $days = 0;
        $current = $start->copy();

        // If not inclusive, move to next day
        if (!$inclusive) {
            $current->addDay();
        }

        while ($current->lte($end)) {
            // Count only weekdays (Monday = 1, Sunday = 7)
            // We exclude Saturday (6) and Sunday (7)
            $dayOfWeek = $current->dayOfWeek;
            if ($dayOfWeek !== Carbon::SATURDAY && $dayOfWeek !== Carbon::SUNDAY) {
                $days++;
            }
            $current->addDay();
        }

        return $days;
    }

    /**
     * Get MySQL expression for business days calculation
     * Use this in raw queries for consistent calculation with PHP method
     *
     * @param string $startColumn
     * @param string $endColumn
     * @return string
     */
    public static function businessDaysSql(string $startColumn, string $endColumn): string
    {
        // MySQL DAYOFWEEK: Sunday=1, Monday=2, ..., Saturday=7
        // We need to exclude Saturday (7) and Sunday (1)
        //
        // Formula: total_days - weekends
        // Where weekends = full_weeks * 2 + weekend_days_in_partial_week
        //
        // Simpler approach using a loop-like calculation in SQL:
        // For each day from start to end, count if NOT Saturday and NOT Sunday

        return "
            CASE
                WHEN {$startColumn} IS NULL OR {$endColumn} IS NULL THEN NULL
                ELSE (
                    -- Count weekdays from start to end (inclusive)
                    DATEDIFF({$endColumn}, {$startColumn}) + 1
                    - FLOOR((DATEDIFF({$endColumn}, {$startColumn}) + DAYOFWEEK({$startColumn}) + 5) / 7) * 2
                    - LEAST(GREATEST(0, (DATEDIFF({$endColumn}, {$startColumn}) + DAYOFWEEK({$startColumn})) MOD 7 - 5), 2)
                )
            END
        ";
    }

    /**
     * Check if a date is a business day (weekday, not weekend)
     */
    public static function isBusinessDay($date): bool
    {
        $date = Carbon::parse($date);
        $dayOfWeek = $date->dayOfWeek;
        return $dayOfWeek !== Carbon::SATURDAY && $dayOfWeek !== Carbon::SUNDAY;
    }

    /**
     * Get next business day from a given date
     */
    public static function nextBusinessDay($date): Carbon
    {
        $date = Carbon::parse($date)->addDay();
        while ($date->dayOfWeek === Carbon::SATURDAY || $date->dayOfWeek === Carbon::SUNDAY) {
            $date->addDay();
        }
        return $date;
    }
}