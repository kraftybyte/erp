<x-filament-panels::page>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap');

    .ptb-wrap * { font-family: 'Plus Jakarta Sans', sans-serif; }

    /* View Toggle */
    .view-toggle-wrap {
        display: flex;
        justify-content: flex-end;
        margin-bottom: 1.25rem;
    }
    .view-toggle {
        display: flex;
        align-items: center;
        gap: 4px;
        background: #f0f4f8;
        border-radius: 12px;
        padding: 5px;
        border: 1px solid #e2e8f0;
    }
    .view-btn {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 7px 14px;
        border-radius: 9px;
        font-size: 13px;
        font-weight: 600;
        border: none;
        cursor: pointer;
        transition: all 0.2s ease;
        color: #94a3b8;
        background: transparent;
    }
    .view-btn.active {
        background: white;
        color: #0891b2;
        box-shadow: 0 1px 4px rgba(0,0,0,0.10), 0 0 0 1px #e0f2fe;
    }
    .view-btn svg { transition: color 0.2s; }
    .view-btn:not(.active):hover { color: #64748b; background: rgba(255,255,255,0.5); }

    /* Board */
    .kanban-scroll {
        overflow-x: auto;
        padding-bottom: 1rem;
        /* Custom scrollbar */
        scrollbar-width: thin;
        scrollbar-color: #cbd5e1 transparent;
    }
    .kanban-scroll::-webkit-scrollbar { height: 6px; }
    .kanban-scroll::-webkit-scrollbar-track { background: transparent; }
    .kanban-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }

    .kanban-columns {
        display: flex;
        gap: 16px;
        min-width: max-content;
        align-items: flex-start;
    }

    /* Column */
    .kanban-col {
        width: 292px;
        border-radius: 16px;
        background: #f8fafc;
        border: 1px solid #e8edf3;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        transition: box-shadow 0.2s;
    }

    .col-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 16px 13px;
        background: white;
        border-bottom: 1px solid #f0f4f8;
    }
    .col-header-left { display: flex; align-items: center; gap: 8px; }

    .col-dot {
        width: 9px; height: 9px;
        border-radius: 99px;
        flex-shrink: 0;
    }
    .col-title {
        font-size: 13px;
        font-weight: 700;
        color: #1e293b;
        letter-spacing: 0.01em;
    }
    .col-count {
        font-size: 11px;
        font-weight: 700;
        padding: 2px 8px;
        border-radius: 99px;
        background: #f1f5f9;
        color: #64748b;
        min-width: 22px;
        text-align: center;
    }

    /* Accent stripe per column */
    .col-stripe {
        height: 3px;
        width: 100%;
    }

    /* Card list */
    .card-list {
        display: flex;
        flex-direction: column;
        gap: 10px;
        padding: 12px;
        flex: 1;
        min-height: 120px;
    }

    /* Card */
    .task-card {
        background: white;
        border-radius: 12px;
        border: 1px solid #e8edf3;
        padding: 14px;
        cursor: pointer;
        transition: box-shadow 0.18s, transform 0.18s, border-color 0.18s;
        position: relative;
        overflow: hidden;
    }
    .task-card::before {
        content: '';
        position: absolute;
        left: 0; top: 0; bottom: 0;
        width: 3px;
        border-radius: 3px 0 0 3px;
    }
    .task-card:hover {
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        transform: translateY(-1px);
        border-color: #d0dae6;
    }

    /* Priority badge */
    .priority-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 10px;
        font-weight: 700;
        padding: 3px 8px;
        border-radius: 6px;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        margin-bottom: 9px;
    }
    .priority-dot {
        width: 5px; height: 5px; border-radius: 99px; background: currentColor;
    }

    /* Task title */
    .task-title {
        font-size: 13px;
        font-weight: 600;
        color: #1e293b;
        line-height: 1.45;
        margin-bottom: 4px;
    }
    .task-project {
        font-size: 11px;
        color: #94a3b8;
        font-weight: 500;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 5px;
    }
    .task-project svg { flex-shrink: 0; }

    /* Footer */
    .card-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    /* Avatars */
    .avatar-stack { display: flex; align-items: center; }
    .avatar {
        width: 26px; height: 26px;
        border-radius: 99px;
        display: flex; align-items: center; justify-content: center;
        font-size: 10px; font-weight: 700;
        color: white;
        border: 2px solid white;
        margin-left: -6px;
        flex-shrink: 0;
    }
    .avatar:first-child { margin-left: 0; }
    .avatar-more {
        background: #f1f5f9;
        color: #64748b;
    }

    /* Deadline chip */
    .deadline-chip {
        display: flex;
        align-items: center;
        gap: 4px;
        font-size: 11px;
        font-weight: 600;
        padding: 3px 8px;
        border-radius: 7px;
        background: #f8fafc;
        border: 1px solid #e8edf3;
        color: #64748b;
    }
    .deadline-chip.overdue {
        background: #fff4f0;
        border-color: #ffd5c2;
        color: #f05a28;
    }

    /* Empty state */
    .empty-col {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 28px 16px;
        gap: 6px;
        opacity: 0.5;
    }
    .empty-col svg { color: #cbd5e1; }
    .empty-col span { font-size: 12px; color: #94a3b8; font-weight: 500; }
</style>

<div class="ptb-wrap">

    {{-- ─── VIEW TOGGLE ─────────────────────────────────── --}}
    <div class="view-toggle-wrap">
        <div class="view-toggle">
            <button
                wire:click="switchView('board')"
                class="view-btn {{ $this->viewMode === 'board' ? 'active' : '' }}"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2"/>
                </svg>
                Board
            </button>
            <button
                wire:click="switchView('list')"
                class="view-btn {{ $this->viewMode === 'list' ? 'active' : '' }}"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
                </svg>
                List
            </button>
        </div>
    </div>

    {{-- ─── BOARD VIEW ──────────────────────────────────── --}}
    <div class="kanban-scroll">
        <div class="kanban-columns">
            @foreach ($statuses as $status)
                @php
                    $statusRecords = $records->where('status', $status->value);
                    $colColor = match($status->value) {
                        'Todo'    => '#f59e0b',
                        'Process' => '#0891b2',
                        'Review'  => '#9333ea',
                        'Done'    => '#16a34a',
                        default   => '#94a3b8',
                    };
                    $colBg = match($status->value) {
                        'Todo'    => '#fffbeb',
                        'Process' => '#ecfeff',
                        'Review'  => '#faf5ff',
                        'Done'    => '#f0fdf4',
                        default   => '#f8fafc',
                    };
                @endphp

                <div class="kanban-col">

                    {{-- Accent stripe --}}
                    <div class="col-stripe" style="background:{{ $colColor }};"></div>

                    {{-- Column Header --}}
                    <div class="col-header">
                        <div class="col-header-left">
                            <span class="col-dot" style="background:{{ $colColor }};"></span>
                            <span class="col-title">{{ $status->name }}</span>
                        </div>
                        <span class="col-count">{{ $statusRecords->count() }}</span>
                    </div>

                    {{-- Cards --}}
                    <div class="card-list" style="background:{{ $colBg }};">
                        @forelse ($statusRecords as $record)
                            @php
                                $priorityColor = match($record->proritas) {
                                    'Urgent' => '#f05a28',
                                    'Normal' => '#0891b2',
                                    'Low'    => '#16a34a',
                                    default  => '#64748b',
                                };
                                $priorityBg = match($record->proritas) {
                                    'Urgent' => '#fff4f0',
                                    'Normal' => '#ecfeff',
                                    'Low'    => '#f0fdf4',
                                    default  => '#f1f5f9',
                                };
                                $isOverdue = $record->selesai_task
                                    && \Carbon\Carbon::parse($record->selesai_task)->isPast()
                                    && $record->status !== 'Done';

                                $avatarColors = ['#0891b2','#7c3aed','#db2777','#ea580c','#16a34a'];
                            @endphp

                            <div class="task-card" style="--accent:{{ $colColor }};">
                                {{-- Left accent --}}
                                <div style="position:absolute;left:0;top:0;bottom:0;width:3px;background:{{ $colColor }};border-radius:3px 0 0 3px;"></div>

                                {{-- Priority --}}
                                <div class="priority-badge" style="background:{{ $priorityBg }};color:{{ $priorityColor }};">
                                    <span class="priority-dot"></span>
                                    {{ $record->proritas ?? 'Normal' }}
                                </div>

                                {{-- Title --}}
                                <p class="task-title">{{ $record->nama_task }}</p>

                                {{-- Project --}}
                                @if ($record->project?->nama_perusahaan)
                                    <p class="task-project">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                                        </svg>
                                        {{ $record->project->nama_perusahaan }}
                                    </p>
                                @endif

                                {{-- Footer --}}
                                <div class="card-footer">

                                    {{-- Assignees --}}
                                    <div class="avatar-stack">
                                        @foreach ($record->users->take(3) as $i => $user)
                                            <div class="avatar" style="background:{{ $avatarColors[$i % count($avatarColors)] }};">
                                                {{ strtoupper(substr($user->firstname ?? 'U', 0, 1)) }}
                                            </div>
                                        @endforeach
                                        @if ($record->users->count() > 3)
                                            <div class="avatar avatar-more">+{{ $record->users->count() - 3 }}</div>
                                        @endif
                                    </div>

                                    {{-- Deadline --}}
                                    @if ($record->selesai_task)
                                        <div class="deadline-chip {{ $isOverdue ? 'overdue' : '' }}">
                                            @if ($isOverdue)
                                                <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                                                </svg>
                                            @else
                                                <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                                </svg>
                                            @endif
                                            {{ \Carbon\Carbon::parse($record->selesai_task)->format('d M') }}
                                        </div>
                                    @endif

                                </div>
                            </div>

                        @empty
                            <div class="empty-col">
                                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                                </svg>
                                <span>Tidak ada task</span>
                            </div>
                        @endforelse
                    </div>

                </div>
            @endforeach
        </div>
    </div>

</div>

</x-filament-panels::page>