<x-filament-widgets::widget>
@php
$settings = app(\App\Settings\GeneralSettings::class);
$primary = $settings->site_theme['primary'] ?? '#6366f1';
@endphp

<x-filament::section class="h-full">

{{-- Header --}}
<div class="flex items-center justify-between mb-4 pb-3 border-b border-gray-200 dark:border-gray-700">
    <h2 class="text-sm font-bold text-gray-900 dark:text-white flex items-center gap-2">
        <div style="width:28px;height:28px;background:{{ $primary }};border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <svg width="16" height="16" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
            </svg>
        </div>
        ToDo List
    </h2>

    {{-- Filter Tabs --}}
    <div style="display:flex;gap:4px;background:#f3f4f6;border-radius:12px;padding:4px;">
        @foreach([
            ['all',     'All',     null],
            ['pending', 'Pending', $this->countPending()],
            ['done',    'Done',    $this->countDone()],
        ] as [$val, $lbl, $count])
            <button wire:click="setFilter('{{ $val }}')"
                style="
                    font-size:11px;font-weight:600;padding:4px 12px;border-radius:8px;border:none;cursor:pointer;white-space:nowrap;transition:all 0.15s;
                    {{ $filter === $val
                        ? 'background:white;color:#000000;box-shadow:0 1px 3px rgba(0,0,0,0.1);'
                        : 'background:transparent;color:#9ca3af;' }}
                ">
                {{ $lbl }}@if($count !== null) ({{ $count }})@endif
            </button>
        @endforeach
    </div>
</div>

{{-- Task List --}}
<div style="position:relative;">
    <style>
        .task-scroll-v::-webkit-scrollbar { width: 3px; }
        .task-scroll-v::-webkit-scrollbar-track { background: transparent; }
        .task-scroll-v::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 999px; }
    </style>
    <div class="task-scroll-v" style="max-height:168px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:#e2e8f0 transparent;">
        @forelse($this->getFilteredTasks() as $task)
            @php $isDone = $task->status === 'done'; @endphp

            <div wire:key="task-{{ $task->id }}"
                style="display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid #f3f4f6;{{ $isDone ? 'opacity:0.5;' : '' }}">

                {{-- Checkbox --}}
                <div x-data="{ checked: {{ $isDone ? 'true' : 'false' }} }">
                    <button type="button"
                        @click="checked = !checked; $wire.openDoneModal({{ $task->id }})"
                        :style="checked
                            ? 'width:18px;height:18px;min-width:18px;border-radius:4px;border:2px solid {{ $primary }};background:{{ $primary }};display:flex;align-items:center;justify-content:center;cursor:pointer;padding:0;transition:all 0.15s;'
                            : 'width:18px;height:18px;min-width:18px;border-radius:4px;border:2px solid #d1d5db;background:white;display:flex;align-items:center;justify-content:center;cursor:pointer;padding:0;transition:all 0.15s;'">
                        <svg x-show="checked" width="10" height="8" viewBox="0 0 10 8" fill="none"
                            stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="1,4 3.5,6.5 9,1"/>
                        </svg>
                    </button>
                </div>

                {{-- Task Name + Client --}}
                <span style="flex:1;min-width:0;font-size:14px;line-height:1.4;text-decoration:{{ $isDone ? 'line-through' : 'none' }};color:{{ $isDone ? '#9ca3af' : '#1f2937' }};">
                    {{ $task->nama_task }}
                    <span style="display:block;font-size:11px;color:#9ca3af;font-weight:400;text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px;">
                        {{ \Illuminate\Support\Str::limit($task->project?->nama_perusahaan ?? '', 25) }}
                    </span>
                </span>

                {{-- Right side --}}
                <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">

                    {{-- Deadline badge --}}
                    @if($task->selesai_task && !$isDone)
                        @php
                            $diff = (int) round(now()->diffInDays(\Carbon\Carbon::parse($task->selesai_task), false));
                            if ($diff < 0) {
                                $deadlineText  = abs($diff) . ' hari terlambat';
                                $deadlineBadge = 'background:#fff1eb;color:#ff5915;';
                            } elseif ($diff === 0) {
                                $deadlineText  = 'Hari ini';
                                $deadlineBadge = 'background:#fff8e6;color:#f59e0b;';
                            } else {
                                $deadlineText  = $diff . ' hari lagi';
                                $deadlineBadge = 'background:#f1f5f9;color:#94a3b8;';
                            }
                        @endphp
                        <span style="font-size:10px;font-weight:600;padding:5px 8px;border-radius:20px;white-space:nowrap;{{ $deadlineBadge }}">
                            {{ $deadlineText }}
                        </span>
                    @endif

                    {{-- Status badge --}}
                    @php
                        $badgeStyle = match($task->status) {
                            'done'    => 'background:'.$primary.'20;color:'.$primary.';',
                            'process' => 'background:#fff4ed;color:#ff5915;',
                            'review'  => 'background:#fff8e6;color:#f59e0b;',
                            'hold'    => 'background:#fff0eb;color:#ff5915;',
                            default   => 'background:#f3f4f6;color:#6b7280;',
                        };
                    @endphp
                    <span style="font-size:10px;font-weight:600;padding:5px 8px;border-radius:20px;white-space:nowrap;{{ $badgeStyle }}">
                        {{ ucfirst((string) $task->status) }}
                    </span>
            </div>
            </div>

        @empty
            <div style="text-align:center;padding:24px 0;">
                <p style="font-size:12px;color:#9ca3af;">
                    {{ $filter === 'done' ? 'Belum ada task selesai' : 'Tidak ada task hari ini' }}
                </p>
            </div>
        @endforelse
    </div>
   

{{-- Footer --}}
@if($this->countAll() > 0)
    <div style="margin-top:16px;padding-top:12px;border-top:1px solid #f3f4f6;display:flex;align-items:center;justify-content:space-between;">
        <span style="font-size:11px;color:#9ca3af;">
            {{ $this->countDone() }} / {{ $this->countAll() }} selesai hari ini
        </span>
        <a href="/admin/task-kanban-board"
            style="font-size:11px;color:{{ $primary }};font-weight:600;text-decoration:none;display:flex;align-items:center;gap:2px;">
            View All
            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
            </svg>
        </a>
    </div>
@endif

{{-- ==================== MODAL ==================== --}}
@if($showModal)
    <div style="position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px;">
        {{-- Backdrop --}}
        <div wire:click="closeModal"
            style="position:absolute;inset:0;background:rgba(0,0,0,0.45);"></div>

        {{-- Modal Box --}}
        <div style="position:relative;background:white;border-radius:16px;padding:24px;width:100%;max-width:400px;box-shadow:0 24px 60px rgba(0,0,0,0.18);">

            {{-- Header --}}
            <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <div style="width:36px;height:36px;background:{{ $primary }};border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <svg width="18" height="18" fill="none" stroke="white" stroke-width="2.5"
                            stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                            <polyline points="20 6 9 17 4 12"/>
                        </svg>
                    </div>
                    <div>
                        <p style="font-size:14px;font-weight:700;color:#111827;margin:0;">Tandai Selesai</p>
                        <p style="font-size:11px;color:#6b7280;margin:2px 0 0;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            {{ $modalTaskName }}
                        </p>
                    </div>
                </div>
                <button wire:click="closeModal" type="button"
                    style="background:none;border:none;cursor:pointer;color:#9ca3af;padding:2px;flex-shrink:0;">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" viewBox="0 0 24 24">
                        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>

            {{-- Textarea — defer supaya tidak sync tiap keystroke --}}
            <div style="margin-bottom:20px;">
                <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;">
                    Catatan <span style="color:#9ca3af;font-weight:400;">(opsional)</span>
                </label>
                <textarea
                    wire:model.defer="doneNote"
                    placeholder="Tambahkan catatan penyelesaian task..."
                    rows="3"
                    style="width:100%;border:1.5px solid #e5e7eb;border-radius:10px;padding:10px 12px;font-size:13px;color:#111827;resize:none;outline:none;font-family:inherit;box-sizing:border-box;"
                    onfocus="this.style.borderColor='{{ $primary }}'"
                    onblur="this.style.borderColor='#e5e7eb'"></textarea>
            </div>

            {{-- Actions --}}
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button wire:click="closeModal" type="button"
                    wire:loading.attr="disabled"
                    wire:target="closeModal"
                    style="padding:8px 16px;border-radius:8px;font-size:13px;font-weight:500;border:1.5px solid #e5e7eb;background:white;color:#374151;cursor:pointer;">
                    Batal
                </button>
                <button wire:click="confirmDone" type="button"
                    wire:loading.attr="disabled"
                    wire:target="confirmDone"
                    style="padding:8px 20px;border-radius:8px;font-size:13px;font-weight:600;border:none;background:{{ $primary }};color:white;cursor:pointer;display:flex;align-items:center;gap:6px;">
                    <span wire:loading.remove wire:target="confirmDone" style="display:flex;align-items:center;gap:6px;">
                        <svg width="13" height="13" fill="none" stroke="white" stroke-width="2.5"
                            stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                            <polyline points="20 6 9 17 4 12"/>
                        </svg>
                        Selesai
                    </span>
                    <span wire:loading wire:target="confirmDone">
                        Menyimpan...
                    </span>
                </button>
            </div>
        </div>
    </div>
@endif

</x-filament::section>
</x-filament-widgets::widget>