<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AnnouncementWidget;
use App\Filament\Widgets\Calendar;
use App\Filament\Widgets\TaskTodayWidget;
use App\Filament\Widgets\TaskWidget;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-home';
    protected static ?string $title = 'Dashboard';
    protected static ?int $navigationSort = -2;

    public function getColumns(): int|string|array
    {
        return [
            'default' => 1,
            'md'      => 2,
            'lg'      => 2,
            'xl'      => 2,
        ];
    }

    public function getHeaderWidgets(): array
    {
        return [
            AnnouncementWidget::class,
        ];
    }

    public function getFooterWidgets(): array
    {
        return [
            Calendar::class,
        ];
    }

    public function getWidgets(): array
    {
        return [
            TaskTodayWidget::class,
            TaskWidget::class,
        ];
    }
}
