<?php

/**
 * Mock ray() function to prevent errors when spatie/ray is not installed
 * This is a no-op function for production environments
 */
if (!function_exists('ray')) {
    function ray(...$args)
    {
        // No-op in production
        return null;
    }
}
