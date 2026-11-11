<x-filament-panels::page>
    @php
        $canEdit = $this->canEditBoard();
        $canCreate = auth()->user()->can('create', \App\Models\ProjectTimeline::class);
    @endphp

    <div class="tl-board" data-tl-root data-can-create="{{ $canCreate ? 1 : 0 }}">
        <x-filament::tabs label="Fokus">
            <x-filament::tabs.item :active="$focus === 'all'" wire:click="$set('focus', 'all')">
                Semua
            </x-filament::tabs.item>
            <x-filament::tabs.item :active="$focus === 'running'" wire:click="$set('focus', 'running')" :badge="$this->counts['running'] ?: null">
                Berjalan hari ini
            </x-filament::tabs.item>
            <x-filament::tabs.item :active="$focus === 'overdue'" wire:click="$set('focus', 'overdue')" :badge="$this->counts['overdue'] ?: null" badge-color="danger">
                Terlambat
            </x-filament::tabs.item>
        </x-filament::tabs>

        <x-filament::section class="tl-section">
            <div class="tl-toolbar">
                <div class="tl-nav" wire:ignore>
                    <x-filament::icon-button icon="heroicon-m-chevron-left" color="gray" label="Sebelumnya" data-tl-nav="prev" />
                    <x-filament::button size="sm" color="gray" data-tl-nav="today">Hari ini</x-filament::button>
                    <x-filament::icon-button icon="heroicon-m-chevron-right" color="gray" label="Berikutnya" data-tl-nav="next" />
                    <h2 class="tl-title" data-tl-title aria-live="polite"></h2>
                </div>

                <div class="tl-controls">
                    <div class="tl-views" role="group" aria-label="Rentang tampilan" wire:ignore>
                        <button type="button" data-tl-view="twoWeeks">2 Minggu</button>
                        <button type="button" data-tl-view="month">Bulan</button>
                        <button type="button" data-tl-view="quarter">3 Bulan</button>
                    </div>

                    <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass" class="tl-search">
                        <x-filament::input type="search" wire:model.live.debounce.400ms="search" placeholder="Cari client atau project" aria-label="Cari client atau project" />
                    </x-filament::input.wrapper>

                    <label class="tl-check">
                        <x-filament::input.checkbox wire:model.live="showTasks" />
                        <span>Task</span>
                    </label>
                    <label class="tl-check">
                        <x-filament::input.checkbox wire:model.live="showDone" />
                        <span>Yang sudah selesai</span>
                    </label>
                </div>
            </div>

            @if ($canEdit)
                <p class="tl-hint">
                    Geser bar untuk memindah tanggal, tarik ujungnya untuk mengubah durasi.
                    @if ($canCreate) Tarik di baris kosong untuk menambah phase. @endif
                    Klik bar untuk mengubah atau menghapus.
                </p>
            @endif

            <div wire:ignore>
                <div data-tl-calendar></div>
                <p class="tl-empty" data-tl-empty hidden></p>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>

@assets
<script src="https://cdn.jsdelivr.net/npm/fullcalendar-scheduler@6.1.8/index.global.min.js"></script>
<style>
    .tl-board { display: flex; flex-direction: column; gap: 1rem; }
    .tl-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; }
    .tl-nav { display: flex; align-items: center; gap: .25rem; }
    .tl-title { margin-left: .5rem; font-size: 1rem; font-weight: 600; color: rgb(var(--gray-950)); }
    .dark .tl-title { color: #fff; }
    .tl-controls { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem 1rem; }
    .tl-views { display: inline-flex; overflow: hidden; border: 1px solid rgba(var(--gray-950), .1); border-radius: .5rem; }
    .dark .tl-views { border-color: rgba(255, 255, 255, .1); }
    .tl-views button { padding: .375rem .75rem; font-size: .875rem; font-weight: 500; color: rgb(var(--gray-700)); }
    .tl-views button + button { border-left: 1px solid rgba(var(--gray-950), .1); }
    .dark .tl-views button { color: rgb(var(--gray-200)); }
    .dark .tl-views button + button { border-left-color: rgba(255, 255, 255, .1); }
    .tl-views button:hover { background: rgba(var(--gray-950), .05); }
    .dark .tl-views button:hover { background: rgba(255, 255, 255, .05); }
    .tl-views button[aria-pressed="true"] { background: rgb(var(--primary-600)); color: #fff; }
    .tl-views button:focus-visible { outline: 2px solid rgb(var(--primary-500)); outline-offset: -2px; }
    .tl-search { width: 16rem; }
    .tl-check { display: inline-flex; align-items: center; gap: .5rem; font-size: .875rem; color: rgb(var(--gray-700)); cursor: pointer; }
    .dark .tl-check { color: rgb(var(--gray-200)); }
    .tl-hint { margin: .75rem 0; font-size: .8125rem; color: rgb(var(--gray-500)); }
    .dark .tl-hint { color: rgb(var(--gray-400)); }
    .tl-empty { padding: 2rem 1rem; text-align: center; font-size: .875rem; color: rgb(var(--gray-500)); }
    .tl-busy [data-tl-calendar] { opacity: .6; transition: opacity .15s; }
    @media (max-width: 640px) {
        .tl-controls, .tl-search { width: 100%; }
    }

    .tl-board .fc {
        --fc-border-color: rgba(var(--gray-950), .08);
        --fc-page-bg-color: transparent;
        --fc-neutral-bg-color: rgba(var(--gray-950), .03);
        --fc-today-bg-color: rgba(var(--primary-500), .08);
        --fc-now-indicator-color: rgb(var(--danger-500));
        --fc-highlight-color: rgba(var(--primary-500), .18);
        font-size: .8125rem;
    }
    .dark .tl-board .fc {
        --fc-border-color: rgba(255, 255, 255, .08);
        --fc-neutral-bg-color: rgba(255, 255, 255, .04);
        color: rgb(var(--gray-200));
    }
    .tl-board .fc a { color: inherit; }
    .tl-board .fc .fc-datagrid-cell-cushion { padding: .375rem .5rem; }
    .tl-board .tl-weekend { background: rgba(var(--gray-950), .035); }
    .dark .tl-board .tl-weekend { background: rgba(255, 255, 255, .035); }
    .tl-board .tl-weekend-label { color: rgb(var(--danger-600)); }
    .dark .tl-board .tl-weekend-label { color: rgb(var(--danger-400)); }

    .tl-board .fc-timeline-event { border-radius: .375rem; padding: 2px 6px; margin-bottom: 2px; }
    .tl-board .fc-event.tl-task { font-size: .75rem; padding-top: 1px; padding-bottom: 1px; }
    .tl-board .fc-event.tl-done { opacity: .55; }
    .tl-board .fc-event.tl-late { box-shadow: 0 0 0 2px rgb(var(--danger-500)); }
    .tl-board .fc-event[data-editable="true"] { cursor: grab; }
    .tl-bar { display: flex; align-items: center; gap: .375rem; overflow: hidden; white-space: nowrap; font-weight: 500; }
    .tl-bar-title { overflow: hidden; text-overflow: ellipsis; }
    .tl-late-tag { flex-shrink: 0; padding: 0 .375rem; border-radius: .25rem; background: rgb(var(--danger-600)); color: #fff; font-size: .6875rem; font-weight: 600; }
    .tl-board .fc .fc-bg-event.tl-late-zone { opacity: 1; background: repeating-linear-gradient(135deg, rgba(var(--danger-500), .22) 0 6px, transparent 6px 12px); }
    .tl-board .fc .fc-bg-event.tl-project-span { opacity: 1; background: rgba(var(--primary-500), .06); }
    /* Terlambat dan bar aslinya sudah di luar periode: label + arsiran, bisa diklik. */
    .tl-board .fc-event.tl-late-chip { border: 1px dashed rgb(var(--danger-500)); background: repeating-linear-gradient(135deg, rgba(var(--danger-500), .16) 0 6px, rgba(var(--danger-500), .05) 6px 12px); cursor: pointer; }
    .tl-board .fc-event.tl-late-chip .fc-event-main { color: rgb(var(--danger-700)); }
    .dark .tl-board .fc-event.tl-late-chip .fc-event-main { color: rgb(var(--danger-300)); }

    .tl-project-name { font-weight: 600; color: rgb(var(--gray-950)); }
    .dark .tl-project-name { color: #fff; }
    .tl-project-name:hover { text-decoration: underline; }
    .tl-project-meta { white-space: normal; font-size: .75rem; color: rgb(var(--gray-500)); }
    .dark .tl-project-meta { color: rgb(var(--gray-400)); }
    .tl-meta-late { font-weight: 600; color: rgb(var(--danger-600)); }
    .dark .tl-meta-late { color: rgb(var(--danger-400)); }
    .tl-row-phase.is-editable { cursor: pointer; }
    .tl-row-phase.is-editable:hover { text-decoration: underline; }
    .tl-row-tasks { color: rgb(var(--gray-500)); }
    .dark .tl-row-tasks { color: rgb(var(--gray-400)); }
</style>
@endassets

@script
<script>
    const root = $wire.$el.querySelector('[data-tl-root]');
    const calendarEl = root.querySelector('[data-tl-calendar]');
    const titleEl = root.querySelector('[data-tl-title]');
    const emptyEl = root.querySelector('[data-tl-empty]');
    const canCreate = root.dataset.canCreate === '1';
    const narrow = window.matchMedia('(max-width: 640px)').matches;

    const VIEWS = {
        twoWeeks: { type: 'resourceTimeline', duration: { weeks: 2 }, dateAlignment: 'week', slotDuration: { days: 1 }, slotLabelFormat: { weekday: 'short', day: 'numeric' }, slotMinWidth: 60 },
        month: { type: 'resourceTimeline', duration: { months: 1 }, slotDuration: { days: 1 }, slotLabelFormat: { day: 'numeric' }, slotMinWidth: 22 },
        quarter: { type: 'resourceTimeline', duration: { months: 3 }, dateAlignment: 'month', slotDuration: { weeks: 1 }, snapDuration: { days: 1 }, slotLabelFormat: [{ month: 'long' }, { day: 'numeric' }], slotMinWidth: 48 },
    };

    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const day = (str) => String(str || '').slice(0, 10);
    const storage = {
        get(key) { try { return window.localStorage.getItem(key); } catch (e) { return null; } },
        set(key, value) { try { window.localStorage.setItem(key, value); } catch (e) {} },
    };

    // Satu permintaan untuk baris dan bar periode yang sama.
    let cache = null;
    function load(info) {
        const key = day(info.startStr) + '|' + day(info.endStr);
        if (!cache || cache.key !== key) {
            const promise = $wire.boardData(day(info.startStr), day(info.endStr)).then((data) => {
                showEmpty(data.resources.length === 0);
                return data;
            });
            cache = { key, promise };
            promise.catch(() => { if (cache && cache.promise === promise) cache = null; });
        }
        return cache.promise;
    }

    function showEmpty(empty) {
        const messages = {
            overdue: 'Tidak ada phase atau task yang terlambat.',
            running: 'Tidak ada phase atau task yang berjalan hari ini.',
            all: 'Belum ada phase di periode ini.',
        };
        let text = $wire.search ? 'Tidak ada yang cocok dengan pencarian di periode ini.' : (messages[$wire.focus] || messages.all);
        if ($wire.focus === 'all' && !$wire.search && canCreate) text += ' Klik "Tambah phase" untuk mulai.';
        emptyEl.textContent = text;
        emptyEl.hidden = !empty;
    }

    function save(info) {
        const event = info.event;
        $wire.move(event.id, day(event.startStr), day(event.endStr))
            .then((result) => { if (!result || !result.ok) info.revert(); })
            .catch(() => info.revert());
    }

    const savedView = storage.get('tl-view');
    let calendar = null;

    function start() {
        // Library kalender dari CDN bisa saja belum selesai dimuat.
        if (typeof FullCalendar === 'undefined') { setTimeout(start, 80); return; }

        calendar = new FullCalendar.Calendar(calendarEl, {
            schedulerLicenseKey: 'GPL-My-Project-Is-Open-Source',
            // Nama hari/bulan dari Intl browser; tanpa file locale terpisah.
            locales: [{ code: 'id', week: { dow: 1, doy: 7 }, allDayText: 'Sehari', moreLinkText: 'lainnya', noEventsText: 'Tidak ada data' }],
            locale: 'id',
            firstDay: 1,
            headerToolbar: false,
            views: VIEWS,
            initialView: VIEWS[savedView] ? savedView : 'month',
            height: 'auto',
            stickyHeaderDates: true,
            nowIndicator: true,
            resourceAreaWidth: narrow ? '9rem' : '14rem',
            resourceAreaHeaderContent: 'Project',
            resourceOrder: 'sortKey',
            resourcesInitiallyExpanded: true,
            refetchResourcesOnNavigate: true,
            resources: (info, success, failure) => load(info).then((data) => success(data.resources), failure),
            events: (info, success, failure) => load(info).then((data) => success(data.events), failure),
            forceEventDuration: true,
            editable: false,
            eventResourceEditable: false,
            selectable: canCreate,
            selectMirror: true,
            unselectAuto: true,

            loading: (busy) => root.classList.toggle('tl-busy', busy),

            datesSet: (info) => {
                titleEl.textContent = info.view.title;
                root.querySelectorAll('[data-tl-view]').forEach((button) => {
                    button.setAttribute('aria-pressed', String(button.dataset.tlView === info.view.type));
                });
                storage.set('tl-view', info.view.type);
            },

            slotLaneClassNames: (arg) => (arg.view.type !== 'quarter' && [0, 6].includes(arg.date.getDay()) ? ['tl-weekend'] : []),
            slotLabelClassNames: (arg) => (arg.view.type !== 'quarter' && arg.level === 0 && [0, 6].includes(arg.date.getDay()) ? ['tl-weekend-label'] : []),

            resourceLabelContent: (arg) => {
                const props = arg.resource.extendedProps;

                if (props.kind === 'project') {
                    const meta = [props.subtitle, props.total ? `${props.done}/${props.total} phase selesai` : null].filter(Boolean).map(esc);
                    if (props.late) meta.push(`<span class="tl-meta-late">${props.late} terlambat</span>`);

                    return { html: `<div><a href="${esc(props.url)}" class="tl-project-name">${esc(arg.resource.title)}</a>`
                        + (meta.length ? `<div class="tl-project-meta">${meta.join(' · ')}</div>` : '') + '</div>' };
                }

                if (props.kind === 'tasks') {
                    return { html: `<span class="tl-row-tasks">Task (${Number(props.count) || 0})</span>` };
                }

                return { html: `<span class="tl-row-phase${props.editable ? ' is-editable' : ''}">${esc(arg.resource.title)}</span>` };
            },

            // Klik nama phase sama dengan klik bar-nya: buka edit.
            resourceLabelDidMount: (info) => {
                const props = info.resource.extendedProps;
                if (props.kind !== 'phase' || !props.editable) return;
                info.el.addEventListener('click', () => $wire.mountAction('editPhase', { id: props.recordId }));
            },

            eventContent: (arg) => {
                if (arg.event.display === 'background') return true;
                const props = arg.event.extendedProps;
                const mark = props.done ? '<span aria-hidden="true">✓</span>' : '';
                const late = props.late ? `<span class="tl-late-tag">+${Number(props.late)} hari</span>` : '';

                return { html: `<div class="tl-bar">${mark}<span class="tl-bar-title">${esc(arg.event.title)}</span>${late}</div>` };
            },

            eventDidMount: (info) => {
                const props = info.event.extendedProps;
                if (props.tip) info.el.title = props.tip;
                info.el.dataset.editable = String(Boolean(info.event.startEditable ?? props.editable));
            },

            eventClick: (info) => {
                const props = info.event.extendedProps;
                if (props.kind === 'phase') $wire.mountAction('editPhase', { id: props.recordId });
                if (props.kind === 'task') $wire.mountAction('editTask', { id: props.recordId });
            },

            eventDrop: save,
            eventResize: save,

            // Tarik di baris kosong: tambah phase untuk project baris itu.
            select: (info) => {
                const projectId = info.resource?.extendedProps.projectId;
                calendar.unselect();
                if (projectId) $wire.mountAction('addPhase', { project_id: projectId, start: day(info.startStr), end: day(info.endStr) });
            },
        });

        calendar.render();
    }

    start();

    root.querySelectorAll('[data-tl-nav]').forEach((button) => {
        button.addEventListener('click', () => calendar && calendar[button.dataset.tlNav]());
    });
    root.querySelectorAll('[data-tl-view]').forEach((button) => {
        button.addEventListener('click', () => calendar && calendar.changeView(button.dataset.tlView));
    });

    $wire.on('timeline-refresh', () => {
        cache = null;
        if (!calendar) return;
        calendar.refetchResources();
        calendar.refetchEvents();
    });

    document.addEventListener('livewire:navigating', () => calendar && calendar.destroy(), { once: true });
</script>
@endscript
