<x-filament-panels::form>

    <x-filament::modal
        id="kanban--edit-record-modal"
        :slideOver="true"
        width="3xl"
    >

        {{-- HEADER --}}
        <x-slot name="header">
            <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700">
                <x-filament::modal.heading class="text-base font-semibold">
                    Detail Task
                </x-filament::modal.heading>
            </div>
        </x-slot>

        {{-- BODY --}}
        <div class="px-6 py-6 space-y-6">
            {{ $this->form }}
        </div>

    </x-filament::modal>

</x-filament-panels::form>
