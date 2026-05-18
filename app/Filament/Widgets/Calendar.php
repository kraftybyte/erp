<?php

namespace App\Filament\Widgets;

use Illuminate\Support\Collection;
use Guava\Calendar\Widgets\CalendarWidget;
use App\Models\Event as AppEvent;
use App\Settings\GeneralSettings;

class Calendar extends CalendarWidget
{
    protected int | string | array $columnSpan = 'full';
    protected ?string $locale = 'id';
    protected static ?int $sort = 2;
    protected int $height = 450;

    public function getCalendarView(): string
    {
        $userAgent = request()->header('User-Agent', '');
        $isMobile = (bool) preg_match('/Mobile|Android|iPhone|iPad|webOS|BlackBerry/i', $userAgent);
        return $isMobile ? 'listMonth' : 'dayGridMonth';
    }

    public function getSchema(?string $model = null): ?array
    {
        return [
            \Filament\Forms\Components\TextInput::make('title'),
        ];
    }

    public function getEvents(array $fetchInfo = []): Collection | array
    {
        $settings = app(GeneralSettings::class);
        $categoryLabels = $settings->getEventCategoryLabels();

        return AppEvent::query()
            ->whereBetween('start_time', [
                $fetchInfo['start'],
                $fetchInfo['end'],
            ])
            ->get()
            ->map(function ($event) use ($categoryLabels) {
                $start = $event->start_time;
                $end   = $event->end_time;
                $isMultiDay = $start->toDateString() !== $end?->toDateString();

                return [
                    'id' => $event->id,
                    'title' => $event->title,
                    'start' => $isMultiDay
                        ? $start->startOfDay()->toDateString()
                        : $start->toIso8601String(),
                    'end' => $isMultiDay
                        ? $end->copy()->addDay()->startOfDay()->toDateString()
                        : $end?->toIso8601String(),
                    'allDay' => $isMultiDay,
                    'backgroundColor' => $event->color,
                    'borderColor'     => $event->color,
                    'extendedProps' => [
                        'agenda_label' => $categoryLabels[$event->color] ?? 'Agenda',
                        'start_time_display' => $start->format('H:i'),
                        'description' => $event->description ?? '',
                    ],
                ];
            });
    }
}