<?php

namespace App\Providers;

use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Illuminate\Support\ServiceProvider;

class FilamentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Filament::serving(function () {
            Filament::auth()->setGuard('web');
        });

        FilamentView::registerRenderHook(
            'panels::body.start',
            fn () => view('filament.global-transition')
        );
    }
}