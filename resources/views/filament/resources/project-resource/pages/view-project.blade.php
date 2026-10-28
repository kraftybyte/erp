<x-filament::page>

<style>
    .fi-header-heading { display:none !important; }
</style>

@php
    $record = $this->getRecord();

    $tasks = $record->tasks()
        ->with('users')
        ->latest()
        ->get();

    // Color mapping kategori project
    $projectColor = match($record->nama_project) {
        'Social Media Management' => '#3bbeff',
        'Video Production'        => '#347b9e',
        'SEO'                     => '#ff5915',
        'Tiktok Handling'         => '#fcc530',
        'Website Development'     => '#e53935',
        'Live Tiktok'             => '#3bbeff',
        'Photo Product'           => '#347b9e',
        'Lain-lain'               => '#9e9e9e',
        default                   => '#9e9e9e',
    };
@endphp


<div x-data="{ tab: 'overview' }" class="space-y-10">

    {{-- ================= HEADER ================= --}}
    <div class="space-y-5">

        <div class="flex items-center gap-4 flex-wrap">

            <h1 class="text-3xl font-bold tracking-tight">
                {{ $record->nama_perusahaan }}
            </h1>

            {{-- STATUS BADGE --}}
            <span class="
                px-4 py-1.5 text-xs font-semibold rounded-full
                @if($record->deal?->status === 'Dalam Tahap Project')
                    bg-blue-100 text-blue-700
                @elseif($record->deal?->status === 'Selesai')
                    bg-green-100 text-green-700
                @elseif($record->deal?->status === 'Menunggu Pembayaran')
                    bg-yellow-100 text-orange-700
                @elseif($record->deal?->status === 'Negosiasi')
                    bg-gray-100 text-gray-600
                @else
                    bg-gray-100 text-gray-600
                @endif
            ">
                {{ $record->deal?->status ?? '—' }}
            </span>

        </div>

        {{-- META BADGES --}}
        <div class="flex flex-wrap gap-3 text-xs">

            {{-- ID --}}
            <span class="px-3 py-1 rounded-full bg-blue-50 text-blue-600  font-medium">
                ID #{{ $record->id }}
            </span>

            {{-- PROJECT CATEGORY BADGE (COLORED) --}}
            <span 
                class="px-3 py-1 rounded-full font-semibold"
                style="
                    background-color: {{ $projectColor }}15;
                    color: {{ $projectColor }};
                    border: 1px solid {{ $projectColor }}30;
                "
            >
                {{ $record->nama_project }}
            </span>

            {{-- TIMELINE --}}
            <span class="px-3 py-1 rounded-full bg-indigo-50 text-indigo-600 font-medium">
                {{ \Carbon\Carbon::parse($record->timeline_mulai)->format('d M Y') }}
                —
                {{ \Carbon\Carbon::parse($record->timeline_selesai)->format('d M Y') }}
            </span>

        </div>

    </div>


    {{-- ================= TABS ================= --}}
    <div class="border-b">
        <div class="flex gap-8 text-sm font-medium">

            <button
                @click="tab = 'overview'"
                :class="tab === 'overview'
                    ? 'border-blue-400 text-blue-400 border-b-2 pb-3'
                    : 'text-gray-500 pb-3'"
            >
                Overview
            </button>

            <button
                @click="tab = 'tasks'"
                :class="tab === 'tasks'
                    ? 'border-blue-400 text-blue-400 border-b-2 pb-3'
                    : 'text-gray-500 pb-3'"
            >
                Tasks ({{ $tasks->count() }})
            </button>

        </div>
    </div>


    {{-- ================= OVERVIEW TAB ================= --}}
    <div x-show="tab === 'overview'" x-cloak>

        <div class="grid md:grid-cols-3 gap-8">

            {{-- DETAIL PROJECT --}}
            <div class="md:col-span-2">

                <div class="bg-white border rounded-2xl p-8 shadow-sm">

                    <h2 class="text-lg font-semibold mb-6">
                        Detail Project
                    </h2>

                    <div class="prose max-w-none text-sm leading-relaxed text-gray-700">
                        {!! nl2br(e($record->detail_project)) !!}
                    </div>

                </div>

            </div>


            {{-- CLIENT INFO --}}
            <div>

                <div class="bg-white border rounded-2xl p-8 shadow-sm">

                    <h3 class="text-lg font-semibold mb-6">
                        Informasi Klien
                    </h3>

                    <div class="space-y-5 text-sm">

                        <div>
                            <div class="text-gray-400">Nama Perusahaan</div>
                            <div class="font-medium text-gray-800">
                                {{ $record->nama_perusahaan }}
                            </div>
                        </div>

                        <div>
                            <div class="text-gray-400">Nama PIC</div>
                            <div class="font-medium text-gray-800">
                                {{ $record->nama_pic }}
                            </div>
                        </div>

                        <div>
                            <div class="text-gray-400">Alamat</div>
                            <div class="font-medium text-gray-800">
                                {{ $record->alamat }}
                            </div>
                        </div>

                        <div>
                            <div class="text-gray-400">Catatan</div>
                            <div class="font-medium text-gray-800">
                                {{ $record->notes }}
                            </div>
                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>


    {{-- ================= TASK TAB ================= --}}
    <div x-show="tab === 'tasks'" x-cloak>

        <div class="bg-white border rounded-2xl shadow-sm divide-y">

            @forelse($tasks as $task)

                <div class="p-6 hover:bg-gray-50 transition">

                    <div class="flex justify-between items-start">

                        {{-- LEFT SIDE --}}
                        <div class="space-y-2">

                            <div class="font-semibold text-gray-900">
                                {{ $task->nama_task }}
                            </div>

                            <div class="text-sm text-gray-500">
                                {!! \Illuminate\Support\Str::limit(strip_tags($task->detail_task), 120) !!}
                            </div>

                            <div class="flex gap-4 text-xs text-gray-500 mt-2">

                                <div>
                                    {{ \Carbon\Carbon::parse($task->mulai_task)->format('d M Y') }}
                                    —
                                    {{ \Carbon\Carbon::parse($task->selesai_task)->format('d M Y') }}
                                </div>

                                <div>
                                    Prioritas: {{ $task->proritas }}
                                </div>

                            </div>

                        </div>

                        {{-- RIGHT SIDE --}}
                        <div class="text-right space-y-3">

                            <span class="
                                px-3 py-1 text-xs rounded-full
                                @if($task->status === 'done') bg-green-100 text-green-700
                                @elseif($task->status === 'process') bg-blue-100 text-blue-700
                                @else bg-gray-100 text-gray-600
                                @endif
                            ">
                                {{ ucfirst((string) $task->status) }}
                            </span>

                            <div class="flex -space-x-2 justify-end">
                                @foreach($task->users as $user)
                                    <div class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center text-xs font-semibold border">
                                        {{ strtoupper(substr($user->firstname, 0, 1)) }}
                                    </div>
                                @endforeach
                            </div>

                        </div>

                    </div>

                </div>

            @empty

                <div class="p-8 text-center text-gray-400">
                    Belum ada task untuk project ini.
                </div>

            @endforelse

        </div>

    </div>

</div>

</x-filament::page>
