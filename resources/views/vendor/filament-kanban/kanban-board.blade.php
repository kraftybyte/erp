<x-filament-panels::page class="bg-gray-50 p-6">

    {{-- ─── KANBAN STYLES ─────────────────────────────── --}}
    <style>
        /* Container utama - viewport height minus header */
        .kanban-container {
            height: calc(100vh - 220px);
            overflow: hidden;
            position: relative;
        }

        /* Wrapper untuk kolom - scroll horizontal */
        .kanban-wrapper {
            display: flex;
            gap: 16px;
            height: 100%;
            overflow-x: auto;
            overflow-y: hidden;
            padding-bottom: 16px;
            padding-right: 8px;
        }

        /* Scrollbar horizontal styling */
        .kanban-wrapper::-webkit-scrollbar {
            height: 8px;
        }

        .kanban-wrapper::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 4px;
        }

        .kanban-wrapper::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        .kanban-wrapper::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>

    {{-- ─── VIEW TOGGLE ─────────────────────────────────── --}}
    <div class="flex items-center justify-start mb-4">
        <div class="flex items-center gap-1 rounded-lg p-1" style="background:#e2e8f0;">
            <button
                wire:click="switchView('board')"
                class="flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm font-medium transition-all"
                style="{{ $this->viewMode === 'board'
                    ? 'background:white; color:#07a0cf; box-shadow:0 1px 3px rgba(0,0,0,0.1);'
                    : 'background:transparent; color:#64748b;' }}"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2"/>
                </svg>
                Board
            </button>
            <button
                wire:click="switchView('list')"
                class="flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm font-medium transition-all"
                style="{{ $this->viewMode === 'list'
                    ? 'background:white; color:#07a0cf; box-shadow:0 1px 3px rgba(0,0,0,0.1);'
                    : 'background:transparent; color:#64748b;' }}"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
                </svg>
                List
            </button>
        </div>
    </div>

    <div
        x-data
        wire:ignore.self
        class="kanban-container"
    >
        <div class="kanban-wrapper">
            @foreach($statuses as $status)
                @include(static::$statusView)
            @endforeach
        </div>
        <div wire:ignore>
            @include(static::$scriptsView)
        </div>
    </div>
    @unless($disableEditModal)
        <x-filament-kanban::edit-record-modal />
    @endunless
</x-filament-panels::page>