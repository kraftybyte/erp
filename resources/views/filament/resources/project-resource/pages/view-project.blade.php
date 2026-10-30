<x-filament-panels::page>
    @php
        $date   = fn ($value) => $value ? \Carbon\Carbon::parse($value)->locale('id')->translatedFormat('j M Y') : '—';
        $status = $record->deal?->status;
    @endphp

    {{-- Warna dari variabel tema Filament supaya ikut terang/gelap. --}}
    <style>
        .pv-meta { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1.25rem; font-size: .875rem; color: rgb(var(--gray-500)); }
        .pv-meta strong { font-weight: 500; color: rgb(var(--gray-950)); }
        .dark .pv-meta { color: rgb(var(--gray-400)); }
        .dark .pv-meta strong { color: #fff; }

        .pv-grid { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: 1.5rem; align-items: start; }
        @media (max-width: 1024px) { .pv-grid { grid-template-columns: 1fr; } }

        /* Markdown (MarkdownEditor); HTML mentah di-escape, baris baru tetap baris baru. */
        .pv-detail { font-size: .875rem; line-height: 1.6; color: rgb(var(--gray-700)); }
        .pv-detail ol { list-style: decimal; padding-left: 1.5rem; margin: .5rem 0; }
        .pv-detail ul { list-style: disc; padding-left: 1.25rem; margin: .25rem 0; }
        .pv-detail li { margin: .2rem 0; }
        .pv-detail p { margin: 0 0 .75rem; }
        .pv-detail strong { color: rgb(var(--gray-950)); }
        .dark .pv-detail { color: rgb(var(--gray-300)); }
        .dark .pv-detail strong { color: #fff; }

        .pv-details { display: grid; gap: 1rem; font-size: .875rem; }
        .pv-details dt { color: rgb(var(--gray-500)); }
        .pv-details dd { margin: .125rem 0 0; font-weight: 500; color: rgb(var(--gray-950)); overflow-wrap: anywhere; white-space: pre-line; }
        .dark .pv-details dt { color: rgb(var(--gray-400)); }
        .dark .pv-details dd { color: #fff; }

        .pv-task { display: flex; justify-content: space-between; gap: 1rem; padding: 1rem 0; border-bottom: 1px solid rgba(var(--gray-950), .06); }
        .pv-task:first-child { padding-top: 0; }
        .pv-task:last-child { border-bottom: 0; padding-bottom: 0; }
        .dark .pv-task { border-color: rgba(255, 255, 255, .08); }
        .pv-task .name { font-weight: 600; color: rgb(var(--gray-950)); }
        .pv-task .desc, .pv-task .info { margin-top: .25rem; font-size: .8125rem; color: rgb(var(--gray-500)); }
        .dark .pv-task .name { color: #fff; }
        .dark .pv-task .desc, .dark .pv-task .info { color: rgb(var(--gray-400)); }
        .pv-people { display: flex; justify-content: flex-end; margin-top: .5rem; }
        .pv-people span { display: inline-flex; width: 1.75rem; height: 1.75rem; margin-left: -.375rem; align-items: center; justify-content: center; border-radius: 9999px;
                          font-size: .6875rem; font-weight: 600; color: rgb(var(--gray-700)); background: rgb(var(--gray-100)); box-shadow: 0 0 0 2px #fff; }
        .dark .pv-people span { color: rgb(var(--gray-200)); background: rgb(var(--gray-700)); box-shadow: 0 0 0 2px rgb(var(--gray-900)); }
    </style>

    <div x-data="{ tab: 'overview' }" class="flex flex-col gap-6">
        <div class="pv-meta">
            @if($status)
                <span class="flex items-center gap-2">Status deal
                    <x-filament::badge :color="\App\Helpers\ColorHelper::dealStatus($status)" size="sm">{{ $status }}</x-filament::badge>
                </span>
            @endif
            <span>Timeline <strong>{{ $date($record->timeline_mulai) }} – {{ $date($record->timeline_selesai) }}</strong></span>
            <span>ID <strong>#{{ $record->id }}</strong></span>
        </div>

        <x-filament::tabs>
            <x-filament::tabs.item alpine-active="tab === 'overview'" x-on:click="tab = 'overview'">Overview</x-filament::tabs.item>
            <x-filament::tabs.item alpine-active="tab === 'tasks'" x-on:click="tab = 'tasks'">
                Tasks
                <x-slot name="badge">{{ $tasks->count() }}</x-slot>
            </x-filament::tabs.item>
        </x-filament::tabs>

        <div x-show="tab === 'overview'" class="pv-grid">
            <x-filament::section heading="Detail project">
                @if(filled($record->detail_project))
                    <div class="pv-detail">
                        {!! \Illuminate\Support\Str::markdown((string) $record->detail_project, [
                            'html_input'         => 'escape',
                            'allow_unsafe_links' => false,
                            'renderer'           => ['soft_break' => "<br>\n"],
                        ]) !!}
                    </div>
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400">Belum ada detail project.</p>
                @endif
            </x-filament::section>

            <x-filament::section heading="Klien">
                <dl class="pv-details">
                    <div>
                        <dt>Perusahaan</dt>
                        <dd>{{ $record->nama_perusahaan ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt>PIC</dt>
                        <dd>{{ filled($record->nama_pic) && $record->nama_pic !== '-' ? $record->nama_pic : '—' }}</dd>
                    </div>
                    @if(filled($record->alamat))
                        <div>
                            <dt>Alamat</dt>
                            <dd>{{ $record->alamat }}</dd>
                        </div>
                    @endif
                    @if(filled($record->notes))
                        <div>
                            <dt>Catatan</dt>
                            <dd>{{ $record->notes }}</dd>
                        </div>
                    @endif
                    @if($record->invoice)
                        <div>
                            <dt>Dibuat dari invoice</dt>
                            <dd>
                                <x-filament::link :href="\App\Filament\Resources\InvoiceResource::getUrl('view', ['record' => $record->invoice])">
                                    {{ $record->invoice->uuid }}
                                </x-filament::link>
                            </dd>
                        </div>
                    @endif
                </dl>
            </x-filament::section>
        </div>

        <div x-show="tab === 'tasks'" x-cloak>
            <x-filament::section>
                @forelse($tasks as $task)
                    <div class="pv-task">
                        <div class="min-w-0">
                            <div class="name">{{ $task->nama_task }}</div>
                            @if(filled($task->detail_task))
                                <div class="desc">{{ \Illuminate\Support\Str::limit(trim(strip_tags($task->detail_task)), 140) }}</div>
                            @endif
                            <div class="info">
                                {{ $date($task->mulai_task) }} – {{ $date($task->selesai_task) }}
                                @if(filled($task->proritas)) · Prioritas {{ $task->proritas }} @endif
                            </div>
                        </div>
                        <div class="shrink-0 text-right">
                            <x-filament::badge :color="\App\Helpers\ColorHelper::status((string) $task->status)" size="sm">{{ ucfirst((string) $task->status) }}</x-filament::badge>
                            @if($task->users->isNotEmpty())
                                <div class="pv-people">
                                    @foreach($task->users as $user)
                                        <span title="{{ $user->fullName }}">{{ strtoupper(mb_substr((string) $user->firstname, 0, 1)) }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400">Belum ada task untuk project ini.</p>
                @endforelse
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
