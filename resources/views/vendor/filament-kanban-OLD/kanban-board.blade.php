<x-filament-panels::page>
    <div x-data wire:ignore.self class="gap-4 pb-4 overflow-x-auto overflow-y-auto md:flex">
        @foreach($statuses as $status)
            @include(static::$statusView)
        @endforeach

        <div wire:ignore>
            @include(static::$scriptsView)
        </div>
    </div>

    @unless($disableEditModal)
        <x-filament-kanban::edit-record-modal/>
    @endunless
</x-filament-panels::page>
