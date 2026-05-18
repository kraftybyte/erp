<?php

use App\Filament\ClickupTheme;
use Filament\Support\Facades\FilamentView;
use Illuminate\Support\Facades\Blade;

// Register ClickUp Theme
FilamentView::registerTheme(function () {
    return asset('css/clickup.css');
});

// Register components
Blade::component('x-clickup.sidebar', 'clickup.sidebar');
Blade::component('x-clickup.command-palette', 'clickup.command-palette');
Blade::component('x-clickup.theme-toggle', 'clickup.theme-toggle');

// Register routes
add_action('admin.routing', function () {
    // Custom admin routes if needed
});