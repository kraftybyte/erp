<x-filament-panels::page>

    {{-- AI HEADER WIDGET --}}
    <x-filament-widgets::widgets
        :widgets="$this->getHeaderWidgets()"
        :columns="1"
    />

    {{-- MAIN DASHBOARD --}}
    <x-filament-widgets::widgets
        :widgets="$this->getFooterWidgets()"
        :columns="[
            'default' => 1,
            'md' => 2,
            'xl' => 4,
        ]"
    />

</x-filament-panels::page>
