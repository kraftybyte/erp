<x-filament-panels::form wire:submit.prevent="editModalFormSubmitted">
    <x-filament::modal id="kanban--edit-record-modal" :slideOver="$this->getEditModalSlideOver()" :width="$this->getEditModalWidth()">
        <x-slot name="header">
            <x-filament::modal.heading>
                Detail Task
            </x-filament::modal.heading>
        </x-slot>

        {{ $this->form }}

        <x-slot name="footer">

            <x-filament::button color="primary" x-on:click="isOpen = false">
                Kembali
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</x-filament-panels::form>
