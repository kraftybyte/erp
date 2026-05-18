@props(['record'])

<div
    x-data
    x-on:dblclick="$dispatch('open-modal', { id: 'taskDetailModal-{{ $record->id }}' })"
    class="rounded-lg shadow p-4 bg-white dark:bg-gray-900 cursor-pointer"
>
    <div class="font-semibold text-sm text-gray-800 dark:text-white">
        {{ $record->nama_task }}
    </div>
    <div class="text-xs text-gray-600 dark:text-gray-300">
        {{ $record->project->nama_perusahaan ?? '-' }}
    </div>

    {{-- Modal per card --}}
    <x-filament::modal id="taskDetailModal-{{ $record->id }}" width="2xl">
        <x-slot name="header">
            Detail Task
        </x-slot>

        <div class="space-y-3">
            <div><strong>Judul:</strong> {{ $record->nama_task }}</div>
            <div><strong>Klien:</strong> {{ $record->project->nama_perusahaan ?? '-' }}</div>
            <div><strong>Layanan:</strong> {{ $record->nama_layanan }}</div>
            <div><strong>Status:</strong> {{ $record->status }}</div>
            <div><strong>Prioritas:</strong> {{ $record->proritas }}</div>
            <div><strong>Detail:</strong> {!! $record->detail_task !!}</div>
        </div>
    </x-filament::modal>
</div>
