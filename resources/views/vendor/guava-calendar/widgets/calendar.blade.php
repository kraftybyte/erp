@php
    $eventClickEnabled = $this->isEventClickEnabled() ? 'true' : 'false';
    $eventDragEnabled = $this->isEventDragEnabled() ? 'true' : 'false';
    $eventResizeEnabled = $this->isEventResizeEnabled() ? 'true' : 'false';
    $dateClickEnabled = $this->isDateClickEnabled() ? 'true' : 'false';
    $dateSelectEnabled = $this->isDateSelectEnabled() ? 'true' : 'false';

    $settings = app(\App\Settings\GeneralSettings::class);
    $legendCategories = $settings->event_categories ?? [];
@endphp

<x-filament-widgets::widget>
    <style>
        .ec { --ec-border-color: rgb(var(--gray-200)); }
        .ec .ec-event {
            border-radius: 8px;
            font-weight: 500;
            padding: 4px 6px;
            font-size: 12px;
            border: none;
            transition: 0.15s ease;
        }
        .ec .ec-event:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }
        .dark .ec .ec-event { color: #ffffff; }
        .ec .ec-toolbar { gap: 12px; }
        .ec .ec-toolbar .ec-button.ec-today {
            border-radius: 100px !important;
            border: 2px solid #e5e7eb !important;
            background: #f9fafb !important;
            color: #374151 !important;
        }
        .ec .ec-toolbar .ec-button.ec-prev,
        .ec .ec-toolbar .ec-button.ec-next {
            width: 42px !important;
            height: 42px !important;
            padding: 0 !important;
            border-radius: 9999px !important;
            border: 1px solid #e5e7eb !important;
            background: #f9fafb !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
        }
        .ec .ec-toolbar .ec-button.ec-prev { margin-right: 8px; }
        .ec .ec-toolbar .ec-button:hover {
            transform: translateY(-1px);
            transition: 0.15s ease;
        }
        .dark .ec .ec-toolbar .ec-button { background-color: rgba(255,255,255,0.05); }
    </style>

    <x-filament::section
        :header-actions="$this->getCachedHeaderActions()"
        :footer-actions="$this->getCachedFooterActions()"
    >
        <x-slot name="heading">{{ $this->getHeading() }}</x-slot>

        <div
            wire:ignore
            x-ignore
            ax-load
            ax-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('calendar-widget', 'guava/calendar') }}"
            x-data="calendarWidget({
                view: '{{ $this->getCalendarView() }}',
                locale: '{{ $this->getLocale() }}',
                firstDay: {{ $this->getFirstDay() }},
                eventClickEnabled: {{ $eventClickEnabled }},
                eventDragEnabled: {{ $eventDragEnabled }},
                eventResizeEnabled: {{ $eventResizeEnabled }},
                dateClickEnabled: {{ $dateClickEnabled }},
                dateSelectEnabled: {{ $dateSelectEnabled }},
                dayHeaderFormat: null,
                slotLabelFormat: null,
            })"
        >
            <div id="calendar"></div>

            @if(!empty($legendCategories))
            <div class="mt-6">
                <div class="text-sm font-semibold mb-3">Keterangan Warna Agenda</div>
                <div class="flex flex-wrap gap-4">
                    @foreach($legendCategories as $cat)
                        <div class="flex items-center gap-2 text-sm">
                            <div class="w-4 h-4 rounded-full" style="background-color: {{ $cat['color'] ?? '#888' }}"></div>
                            <span>{{ $cat['label'] ?? 'Agenda' }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
            @endif

            <x-guava-calendar::context-menu/>
        </div>
    </x-filament::section>

    <x-filament-actions::modals/>
</x-filament-widgets::widget>