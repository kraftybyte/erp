<?php

namespace App\Filament\Widgets;

use App\Models\Announcement;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

class AnnouncementWidget extends Widget
{
    protected static string $view = 'filament.widgets.announcement-widget';
    protected int|string|array $columnSpan = 'full';
    protected static ?int $sort = 1;


    protected function getViewData(): array
    {
        return [
            'announcements' => Announcement::where('start_date', '<=', now())
            ->where('end_date', '>=', now())
            ->orderBy('start_date', 'desc')
            ->latest()
            ->get(),
        ];
    }
}