<x-filament-panels::page>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/fullcalendar-scheduler@6.1.8/index.global.min.css" rel="stylesheet">

<div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">

    <div id="historyBadge" class="tl-history">
        <span class="tl-history-dot"></span>
        <span id="historyText">{{ $lastHistoryText ?? 'Belum ada perubahan' }}</span>
    </div>

    <div wire:ignore>
        <div id="calendar"></div>
    </div>

</div>

<style>
    body, .fc { font-family: 'Sora', sans-serif; }
    #calendar { height: 550px; }
    .fc { border: none !important; }

    .fc-toolbar-title {
        font-size: 1.2rem !important;
        font-weight: 600 !important;
        color: #111827 !important;
        line-height: 1.4 !important;
    }

    .fc .fc-button,
    .fc .fc-button-primary {
        border-radius: 9999px !important;
        border: 1px solid #e5e7eb !important;
        background: #f9fafb !important;
        color: #374151 !important;
        font-size: 0.83rem !important;
        padding: 5px 14px !important;
        box-shadow: none !important;
        font-weight: 500 !important;
        transition: background 0.15s !important;
    }

    .fc .fc-button:hover,
    .fc .fc-button-primary:hover {
        background: #e5e7eb !important;
        color: #111827 !important;
    }

    .fc .fc-button-primary:not(:disabled).fc-button-active {
        background: #111827 !important;
        color: #fff !important;
        border-color: #111827 !important;
    }

    .fc .fc-prev-button,
    .fc .fc-next-button {
        width: 34px !important;
        height: 34px !important;
        padding: 0 !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
    }

    .fc .fc-prev-button .fc-icon,
    .fc .fc-next-button .fc-icon {
        font-size: 15px !important;
        color: #111827 !important;
    }

    .fc-event {
        border-radius: 4px !important;
        border: none !important;
        padding-left: 6px !important;
        font-size: 0.82rem !important;
        box-shadow: 0 1px 2px rgba(0,0,0,0.06) !important;
    }

    .fc-event.ring-highlight {
        box-shadow: 0 0 0 3px rgba(37,99,235,0.4) !important;
    }

    .tl-history {
        display: flex;
        align-items: center;
        gap: 7px;
        font-size: 13px;
        color: #6b7280;
        margin-bottom: 12px;
    }

    .tl-history-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #10b981;
        flex-shrink: 0;
        transition: transform 0.3s, background 0.3s;
    }

    .tl-history.updated .tl-history-dot {
        transform: scale(1.6);
        background: #2563eb;
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar-scheduler@6.1.8/index.global.min.js"></script>
<script>
(function () {

    const ALL_RESOURCES = @json($resources);
    const ALL_EVENTS = @json($events);
    const UPDATE_URL = "{{ route('timeline.update') }}";
    const CSRF = "{{ csrf_token() }}";

    // ── Filter: sembunyikan event yang end-nya sudah lewat hari ini ──────────
    function filterActive(resources, events) {
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        const activeEvents = events.filter(ev => {
            const endRaw = ev.end || ev.start;
            if (!endRaw) return true;

            const endDate = new Date(endRaw);

            if (isNaN(endDate.getTime())) {
                return true;
            }

            return endDate >= today;
        });

        if (activeEvents.length === 0 && events.length > 0) {
            return { activeResources: resources, activeEvents: events };
        }

        const activeChildIds = new Set(activeEvents.map(ev => String(ev.resourceId)));
        const activeParentIds = new Set();

        resources.forEach(r => {
            if (r.parentId && activeChildIds.has(String(r.id))) {
                activeParentIds.add(String(r.parentId));
            }
        });

        const activeResources = resources.filter(r => {
            if (!r.parentId) {
                return activeParentIds.has(String(r.id));
            }
            return activeChildIds.has(String(r.id));
        });

        const finalResources = activeResources.length > 0 ? activeResources : resources;

        return { activeResources: finalResources, activeEvents };
    }

    const { activeResources, activeEvents } = filterActive(ALL_RESOURCES, ALL_EVENTS);

    let cal = null;

    // ── Fix toolbar via inline style ──────────────────────────────────────────
    function fixToolbar() {
        const toolbar = document.querySelector('.fc-toolbar');
        if (!toolbar) return;

        toolbar.style.cssText = [
            'display: flex',
            'flex-direction: row',
            'align-items: center',
            'justify-content: space-between',
            'flex-wrap: wrap',
            'gap: 8px',
        ].join(' !important;') + ' !important;';

        document.querySelectorAll('.fc-toolbar-chunk').forEach(chunk => {
            chunk.style.cssText = [
                'display: flex',
                'flex-direction: row',
                'align-items: center',
                'gap: 6px',
            ].join(' !important;') + ' !important;';
        });
    }

    // ── Init ──────────────────────────────────────────────────────────────────
    function initCalendar() {
        const el = document.getElementById('calendar');
        if (!el) return;

        if (cal) {
            try { cal.destroy(); } catch (_) {}
            cal = null;
        }

        cal = new FullCalendar.Calendar(el, {
            schedulerLicenseKey: 'GPL-My-Project-Is-Open-Source',
            locale: 'id',
            initialView: 'resourceTimelineMonth',

            headerToolbar: {
                left:   'prev,next today',
                center: 'title',
                right:  'resourceTimelineMonth,resourceTimelineWeek'
            },

            buttonText: {
                today: 'Hari Ini',
                month: 'Bulan',
                week:  'Minggu'
            },

            resources: activeResources,
            events:    activeEvents,

            editable: true,
            eventResourceEditable: false,
            resourceAreaHeaderContent: 'Nama Client',

            slotDuration: { days: 1 },
            slotLabelFormat: { weekday: 'short', day: '2-digit' },

            datesSet: function () {
                requestAnimationFrame(fixToolbar);
            },

            slotLaneDidMount: function (info) {
                const d = info.date.getDay();
                if (d === 0 || d === 6) info.el.style.backgroundColor = '#fff1f1';
            },

            slotLabelDidMount: function (info) {
                const d = info.date.getDay();
                if (d === 0 || d === 6) {
                    info.el.style.color      = '#dc2626';
                    info.el.style.fontWeight = '600';
                }
            },

            resourceLabelDidMount: function (info) {
                const rid = info.resource.id;

                if (rid.startsWith('project-')) {
                    info.el.style.fontWeight      = '600';
                    info.el.style.backgroundColor = '#f9fafb';
                    return;
                }

                info.el.style.cursor = 'pointer';
                info.el.addEventListener('click', function () {
                    if (!cal) return;
                    const ev = cal.getEvents().find(e =>
                        e.getResources().some(r => r.id === rid)
                    );
                    if (!ev) return;
                    cal.gotoDate(ev.start);
                    ev.setProp('classNames', ['ring-highlight']);
                    setTimeout(() => ev.setProp('classNames', []), 1500);
                });
            },

            eventDrop:   i => doUpdate(i.event),
            eventResize: i => doUpdate(i.event),
        });

        cal.render();
        requestAnimationFrame(fixToolbar);
    }

    // ── Update drag/resize ────────────────────────────────────────────────────
    function doUpdate(event) {
        fetch(UPDATE_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({ id: event.id, start: event.startStr, end: event.endStr })
        })
        .then(r => { if (!r.ok) throw new Error(r.status); return r.json(); })
        .then(d => { if (d.success) flashHistory(d.message); })
        .catch(e => {
            console.error('[Timeline] Update gagal:', e);
            flashHistory('Gagal menyimpan perubahan');
 });
    }

    // ── History flash ─────────────────────────────────────────────────────────
    function flashHistory(text) {
        const badge = document.getElementById('historyBadge');
        const label = document.getElementById('historyText');
        if (!badge || !label) return;
        label.innerText = text;
        badge.classList.add('updated');
        setTimeout(() => badge.classList.remove('updated'), 800);
    }

    // ── Boot ──────────────────────────────────────────────────────────────────
    function boot() {
        if (typeof FullCalendar === 'undefined') { setTimeout(boot, 80); return; }
        initCalendar();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    // ── Re-init calendar saat balik dari navigasi Livewire (cancel form, dll) ─
    document.addEventListener('livewire:navigated', () => { cal = null; boot(); });

    document.addEventListener('livewire:init', () => {
        Livewire.on('timeline-created', () => {
            window.location.reload();
        });
    });

})();
</script>

</x-filament::page>