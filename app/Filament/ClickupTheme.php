<?php

namespace App\Filament;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Filament\Themes\Theme;
use Illuminate\Contracts\View\View;

class ClickupTheme extends Theme
{
    protected static ?string $name = 'clickup';

    protected static ?string $viewNamespace = 'clickup';

    public static function get(): static
    {
        return app(static::class);
    }

    public static function getView(string $view): View
    {
        return view(static::getViewNamespace() . '::' . $view);
    }

    public static function getViewNamespace(): string
    {
        return 'clickup';
    }

    public static function getFavicon(): string
    {
        return '/icons/icon-512x512.svg';
    }

    public static function getPrimaryColor(): array
    {
        return [
            50 => '#f5f3ff',
            100 => '#ede9fe',
            200 => '#ddd6fe',
            300 => '#c4b5fd',
            400 => '#a78bfa',
            500 => '#8b5cf6',
            600 => '#7c3aed',
            700 => '#6d28d9',
            800 => '#5b21b6',
            900 => '#4c1d95',
        ];
    }
}

/**
 * Color helper for ClickUp theme
 */
if (!function_exists('clickup_color')) {
    function clickup_color(string $name): array
    {
        $colors = [
            'primary' => [
                50 => '#f5f3ff',
                100 => '#ede9fe',
                200 => '#ddd6fe',
                300 => '#c4b5fd',
                400 => '#a78bfa',
                500 => '#8b5cf6',
                600 => '#7c3aed',
                700 => '#6d28d9',
                800 => '#5b21b6',
                900 => '#4c1d95',
            ],
            'success' => [
                50 => '#ecfdf5',
                100 => '#d1fae5',
                200 => '#a7f3d0',
                300 => '#6ee7b7',
                400 => '#34d399',
                500 => '#10b981',
                600 => '#059669',
                700 => '#047857',
                800 => '#065f46',
                900 => '#064e3b',
            ],
            'warning' => [
                50 => '#fffbeb',
                100 => '#fef3c7',
                200 => '#fde68a',
                300 => '#fcd34d',
                400 => '#fbbf24',
                500 => '#f59e0b',
                600 => '#d97706',
                700 => '#b45309',
                800 => '#92400e',
                900 => '#78350f',
            ],
            'danger' => [
                50 => '#fef2f2',
                100 => '#fee2e2',
                200 => '#fecaca',
                300 => '#fca5a5',
                400 => '#f87171',
                500 => '#ef4444',
                600 => '#dc2626',
                700 => '#b91c1c',
                800 => '#991b1b',
                900 => '#7f1d1d',
            ],
            'muted' => [
                50 => '#fafafa',
                100 => '#f4f4f5',
                200 => '#e4e4e7',
                300 => '#d4d4d8',
                400 => '#a1a1aa',
                500 => '#71717a',
                600 => '#52525b',
                700 => '#3f3f46',
                800 => '#27272a',
                900 => '#18181b',
            ],
        ];

        return $colors[$name] ?? $colors['primary'];
    }
}