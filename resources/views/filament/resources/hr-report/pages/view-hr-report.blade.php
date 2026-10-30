<x-filament-panels::page>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    /* ── TOKENS ── */
    :root {
        --c-primary:   #07a0cf;
        --c-primary-dk:#0589b0;
        --c-accent:    #ff5915;
        --c-warn:      #d97706;
        --c-success:   #10b981;
        --c-muted:     #64748b;
        --c-text:      #0f172a;
        --c-surface:   #ffffff;
        --c-bg:        #f4f7fb;
        --c-border:    rgba(0,0,0,.06);
        --c-glass:     rgba(255,255,255,.72);
        --shadow-xs:   0 1px 3px rgba(0,0,0,.05), 0 1px 2px rgba(0,0,0,.04);
        --shadow-sm:   0 2px 8px rgba(0,0,0,.06), 0 1px 3px rgba(0,0,0,.05);
        --shadow-md:   0 8px 24px rgba(0,0,0,.08), 0 2px 6px rgba(0,0,0,.05);
        --shadow-lg:   0 20px 48px rgba(0,0,0,.10), 0 4px 12px rgba(0,0,0,.06);
        --radius-sm:   10px;
        --radius-md:   16px;
        --radius-lg:   22px;
    }

    /* Use Inter font stack for consistency */
    .hr-page * {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        box-sizing: border-box;
    }

    /* ── LAYOUT ── */
    .hr-page { display: flex; flex-direction: column; gap: 16px; }

    /* ── CARD BASE ── */
    .card {
        background: var(--c-surface);
        border: 1px solid #e2e8f0;
        border-radius: var(--radius-md);
        box-shadow: 0 1px 3px rgba(0,0,0,.04), 0 1px 2px rgba(0,0,0,.03);
        padding: 20px;
        transition: all 0.2s ease;
    }
    .card:hover {
        box-shadow: var(--shadow-sm);
        border-color: #cbd5e1;
    }
    .card-title {
        font-size: 12px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 14px;
        display: flex;
        align-items: center;
        gap: 8px;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }
    .card-title::before {
        content: '';
        display: inline-block;
        width: 4px;
        height: 14px;
        background: linear-gradient(180deg, var(--c-primary) 0%, var(--c-primary-dk) 100%);
        border-radius: 2px;
    }

    /* ── PERIOD TABS ── */
    .period-bar {
        display: flex;
        justify-content: flex-end;
    }
    .period-group {
        display: flex;
        gap: 2px;
        background: #f1f5f9;
        border-radius: 10px;
        padding: 3px;
        border: 1px solid #e2e8f0;
    }
    .period-btn {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        font-size: 11px;
        font-weight: 600;
        padding: 7px 14px;
        border-radius: 8px;
        border: none;
        cursor: pointer;
        transition: all 0.15s ease;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    .period-btn-active {
        background: white;
        color: var(--c-primary);
        box-shadow: 0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
        font-weight: 700;
    }
    .period-btn-inactive {
        background: transparent;
        color: #475569;
    }
    .period-btn-inactive:hover {
        color: #334155;
        background: rgba(255,255,255,.6);
    }

    /* ── PROFILE CARD ── */
    .profile-card {
        flex: 1;
        min-width: 280px;
        display: flex;
        align-items: center;
        gap: 20px;
        position: relative;
        overflow: hidden;
    }
    .profile-card::before {
        content: '';
        position: absolute;
        top: -40px; right: -40px;
        width: 160px; height: 160px;
        background: radial-gradient(circle, rgba(7,160,207,.08) 0%, transparent 70%);
        border-radius: 50%;
        pointer-events: none;
    }
    .avatar-ring {
        position: relative;
        flex-shrink: 0;
    }
    .avatar-ring::after {
        content: '';
        position: absolute;
        inset: -3px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--c-primary), #0284c7, #07a0cf);
        z-index: -1;
    }
    .avatar-img {
        width: 68px; height: 68px;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid white;
        display: block;
    }
    .avatar-initials {
        width: 68px; height: 68px;
        border-radius: 50%;
        background: linear-gradient(135deg, #07a0cf 0%, #0284c7 100%);
        display: flex; align-items: center; justify-content: center;
        color: white; font-weight: 700; font-size: 20px;
        border: 3px solid white;
    }
    .profile-name {
        font-weight: 700;
        font-size: 16px;
        color: #0f172a;
        line-height: 1.3;
    }
    .profile-email {
        font-size: 11px;
        color: #64748b;
        margin-top: 4px;
        font-weight: 500;
    }
    .role-chip {
        background: rgba(7,160,207,.1);
        color: var(--c-primary);
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 10px;
        font-weight: 600;
        border: 1px solid rgba(7,160,207,.2);
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    /* ── KPI CARD ── */
    .kpi-card {
        min-width: 180px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        gap: 8px;
        padding: 24px 20px;
    }
    .kpi-badge {
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        opacity: 0.9;
    }
    .kpi-number {
        font-size: 48px;
        font-weight: 800;
        line-height: 1;
        letter-spacing: -1.5px;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        width: 100%;
        text-align: center;
    }
    .kpi-label-tag {
        font-size: 11px;
        font-weight: 600;
        padding: 5px 14px;
        border-radius: 999px;
        color: white;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    .kpi-sub {
        font-size: 10px;
        opacity: 0.8;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    /* ── STAT GRID ── */
    .stat-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: 12px;
    }
    .stat-card {
        background: var(--c-surface);
        border: 1px solid #e2e8f0;
        border-radius: var(--radius-sm);
        padding: 16px 14px;
        text-align: center;
        box-shadow: var(--shadow-xs);
        transition: all 0.15s ease;
        position: relative;
        overflow: hidden;
    }
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-sm);
    }
    .stat-card::before {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 3px;
        border-radius: 0 0 var(--radius-sm) var(--radius-sm);
    }
    .stat-val {
        font-size: 26px;
        font-weight: 800;
        line-height: 1;
        letter-spacing: -0.5px;
    }
    .stat-lbl {
        font-size: 10px;
        color: #64748b;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        margin-top: 6px;
    }

    /* ── CHART CARDS ── */
    .chart-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }

    /* ── ATTENDANCE ── */
    .attend-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
        gap: 12px;
        margin-bottom: 16px;
    }
    .attend-item {
        text-align: center;
        padding: 14px 10px;
        background: #f8fafc;
        border-radius: var(--radius-sm);
        border: 1px solid #e2e8f0;
    }
    .attend-val {
        font-size: 22px;
        font-weight: 800;
        line-height: 1.2;
    }
    .attend-lbl {
        font-size: 10px;
        color: #64748b;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-top: 6px;
    }
    .prog-bar-bg {
        height: 8px;
        background: #f1f5f9;
        border-radius: 999px;
        overflow: hidden;
        margin-top: 12px;
    }
    .prog-bar-fill {
        height: 100%;
        border-radius: 999px;
        transition: width 0.5s ease;
    }
    .prog-label {
        display: flex;
        justify-content: space-between;
        font-size: 11px;
        color: #64748b;
        margin-bottom: 8px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    /* ── UNIFIED TABLE SYSTEM ── */
    .tbl-container {
        border-radius: var(--radius-sm);
        overflow: hidden;
        border: 1px solid #e8edf4;
    }
    .tbl {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }
    .tbl thead {
        background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    }
    .tbl thead tr {
        border-bottom: 2px solid #e2e8f0;
    }
    .tbl thead th {
        padding: 10px 14px;
        text-align: left;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        color: #64748b;
        white-space: nowrap;
    }
    .tbl tbody tr {
        border-bottom: 1px solid #f1f5f9;
        transition: all 0.15s ease;
    }
    .tbl tbody tr:last-child {
        border-bottom: none;
    }
    .tbl tbody tr:hover {
        background: #f8fafc;
    }
    .tbl tbody td {
        padding: 11px 14px;
        color: #334155;
        vertical-align: middle;
        line-height: 1.4;
    }

    /* ── TASK TABLE (Grid-based) ── */
    .task-header {
        display: grid;
        grid-template-columns: 1.4fr 90px 100px 100px 75px 1fr;
        gap: 8px;
        padding: 11px 14px;
        background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        border-radius: var(--radius-sm);
        margin-bottom: 6px;
        border-bottom: 2px solid #e2e8f0;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        color: #64748b;
    }
    .task-row {
        display: grid;
        grid-template-columns: 1.4fr 90px 100px 100px 75px 1fr;
        gap: 8px;
        align-items: center;
        padding: 11px 14px;
        border-radius: var(--radius-sm);
        font-size: 12px;
        margin-bottom: 4px;
        transition: all 0.15s ease;
        border: 1px solid transparent;
        background: white;
    }
    .task-row:hover {
        background: #f8fafc;
        border-color: #e2e8f0;
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    }
    .task-row-late {
        background: #fff8f6;
        border-color: rgba(239,68,68,.1);
    }
    .task-row-late:hover {
        background: #fff2ef;
    }
    .task-name {
        font-weight: 600;
        color: #0f172a;
        font-size: 12px;
        line-height: 1.4;
    }
    .task-client {
        font-size: 11px;
        color: #64748b;
        margin-top: 2px;
        font-weight: 500;
    }
    .task-note {
        font-size: 11px;
        color: #64748b;
        margin-top: 2px;
        font-style: italic;
    }
    .status-chip {
        font-size: 10px;
        font-weight: 600;
        padding: 4px 10px;
        border-radius: 999px;
        display: inline-block;
        white-space: nowrap;
        text-transform: capitalize;
    }
    .late-flag {
        font-size: 10px;
        color: #ef4444;
        margin-top: 3px;
        font-weight: 600;
    }
    .task-date {
        font-size: 12px;
        color: #64748b;
        font-weight: 500;
    }
    .task-dur {
        font-size: 12px;
        font-weight: 600;
    }

    /* ── ABSENCE TABLE ── */
    .abs-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }
    .abs-table thead {
        background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    }
    .abs-table thead tr {
        border-bottom: 2px solid #e2e8f0;
    }
    .abs-table thead th {
        padding: 10px 14px;
        text-align: left;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        color: #64748b;
    }
    .abs-table tbody tr {
        border-bottom: 1px solid #f1f5f9;
        transition: background 0.12s;
    }
    .abs-table tbody tr:hover {
        background: #f8fafc;
    }
    .abs-table tbody tr:last-child {
        border-bottom: none;
    }
    .abs-table tbody td {
        padding: 11px 14px;
        color: #334155;
        vertical-align: middle;
    }
    .abs-type-chip {
        font-size: 10px;
        font-weight: 600;
        padding: 4px 10px;
        border-radius: 999px;
        display: inline-block;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    /* ── SCROLLBAR ── */
    .thin-scroll::-webkit-scrollbar { width: 5px; }
    .thin-scroll::-webkit-scrollbar-track { background: transparent; }
    .thin-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
    .thin-scroll::-webkit-scrollbar-thumb:hover { background: #64748b; }
    .thin-scroll { scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent; }

    /* ── BREAKDOWN ── */
    .breakdown-item {
        margin-bottom: 14px;
    }
    .breakdown-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 6px;
    }
    .breakdown-name {
        font-size: 12px;
        font-weight: 600;
        color: #334155;
    }
    .breakdown-meta {
        display: flex;
        gap: 8px;
        align-items: center;
    }
    .breakdown-count {
        font-size: 14px;
        font-weight: 800;
    }
    .breakdown-pct {
        font-size: 11px;
        color: #64748b;
        font-weight: 600;
    }
    .avg-box {
        margin-top: 16px;
        padding-top: 16px;
        border-top: 2px solid #e2e8f0;
        text-align: center;
    }
    .avg-box-label {
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #64748b;
        margin-bottom: 6px;
    }
    .avg-box-num {
        font-size: 36px;
        font-weight: 800;
        color: var(--c-primary);
        line-height: 1;
        letter-spacing: -1px;
    }
    .avg-box-sub {
        font-size: 11px;
        color: #64748b;
        margin-top: 4px;
        font-weight: 600;
    }

    /* ── DONE NOTES ── */
    .note-card {
        background: #f8fafc;
        border-radius: var(--radius-sm);
        padding: 14px 16px;
        border-left: 3px solid var(--c-primary);
        transition: all 0.15s ease;
    }
    .note-card:hover {
        background: #f1f5f9;
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    }
    .note-task {
        font-size: 12px;
        font-weight: 700;
        color: #0f172a;
        margin: 0 0 4px;
    }
    .note-meta {
        font-size: 10px;
        color: #64748b;
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    .note-body {
        font-size: 12px;
        color: #475569;
        line-height: 1.6;
        margin: 0;
        white-space: pre-line;
    }

    /* ── SECTION DIVIDER ── */
    .section-row { display: grid; gap: 14px; }

    /* ── GRID RESPONSIF ── dulu 2, 3, dan 5 kolom tetap, sehingga grafik
       meluber keluar layar dan kartu menyempit di HP. */
    .hr-grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
    .hr-grid-main { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: 14px; }
    .chart-grid > *, .hr-grid-3 > *, .hr-grid-main > * { min-width: 0; }
    @media (max-width: 1024px) {
        .chart-grid, .hr-grid-3, .hr-grid-main { grid-template-columns: minmax(0, 1fr); }
    }

    /* ── TOKEN TEKS ── */
    .hr-page { --hr-muted: #64748b; --hr-body: #475569; --hr-faint: #94a3b8; --hr-chip: #f0f4f8; }

    /* ── MODE GELAP ── dulu semua kartu tetap putih. */
    .dark .hr-page { --c-surface: rgb(var(--gray-900)); --c-text: #fff; --c-border: rgba(255,255,255,.1);
                     --hr-muted: rgb(var(--gray-300)); --hr-body: rgb(var(--gray-200)); --hr-faint: rgb(var(--gray-400)); --hr-chip: rgba(255,255,255,.08); }
    .dark .hr-page .card,
    .dark .hr-page .stat-card { border-color: rgba(255,255,255,.1); }
    .dark .hr-page .card:hover { border-color: rgba(255,255,255,.2); }
    .dark .hr-page .card-title,
    .dark .hr-page .profile-name,
    .dark .hr-page .task-name,
    .dark .hr-page .note-task { color: #fff; }
    .dark .hr-page .profile-email,
    .dark .hr-page .stat-lbl,
    .dark .hr-page .attend-lbl,
    .dark .hr-page .prog-label,
    .dark .hr-page .tbl thead th,
    .dark .hr-page .abs-table thead th,
    .dark .hr-page .task-header,
    .dark .hr-page .task-client,
    .dark .hr-page .task-note,
    .dark .hr-page .task-date,
    .dark .hr-page .breakdown-pct,
    .dark .hr-page .avg-box-label,
    .dark .hr-page .avg-box-sub,
    .dark .hr-page .note-meta,
    .dark .hr-page .period-btn-inactive { color: rgb(var(--gray-300)); }
    .dark .hr-page .tbl tbody td,
    .dark .hr-page .abs-table tbody td,
    .dark .hr-page .breakdown-name,
    .dark .hr-page .note-body { color: rgb(var(--gray-200)); }
    .dark .hr-page .period-group { background: rgba(255,255,255,.05); border-color: rgba(255,255,255,.1); }
    .dark .hr-page .period-btn-active { background: rgb(var(--gray-800)); }
    .dark .hr-page .period-btn-inactive:hover { color: #fff; background: rgba(255,255,255,.06); }
    .dark .hr-page .avatar-img, .dark .hr-page .avatar-initials { border-color: rgb(var(--gray-900)); }
    .dark .hr-page .attend-item,
    .dark .hr-page .note-card { background: rgba(255,255,255,.04); border-color: rgba(255,255,255,.08); }
    .dark .hr-page .note-card:hover { background: rgba(255,255,255,.07); }
    .dark .hr-page .prog-bar-bg { background: rgba(255,255,255,.1); }
    .dark .hr-page .tbl-container { border-color: rgba(255,255,255,.1); }
    .dark .hr-page .tbl thead,
    .dark .hr-page .abs-table thead,
    .dark .hr-page .task-header { background: rgba(255,255,255,.04); border-color: rgba(255,255,255,.1); }
    .dark .hr-page .tbl thead tr,
    .dark .hr-page .abs-table thead tr { border-color: rgba(255,255,255,.1); }
    .dark .hr-page .tbl tbody tr,
    .dark .hr-page .abs-table tbody tr { border-color: rgba(255,255,255,.06); }
    .dark .hr-page .tbl tbody tr:hover,
    .dark .hr-page .abs-table tbody tr:hover { background: rgba(255,255,255,.03); }
    .dark .hr-page .task-row { background: transparent; }
    .dark .hr-page .task-row:hover { background: rgba(255,255,255,.04); border-color: rgba(255,255,255,.1); }
    .dark .hr-page .task-row-late { background: rgba(var(--danger-500), .08); border-color: rgba(var(--danger-500), .2); }
    .dark .hr-page .avg-box { border-color: rgba(255,255,255,.1); }
    .dark .hr-page .kpi-card { background: rgb(var(--gray-900)) !important; } /* latar pastel inline */
</style>

@php
    $employee  = $record;
    $avatar    = $employee->getFirstMediaUrl('avatars') ?? null;
    $initials  = strtoupper(substr($employee->firstname ?? 'U',0,1).substr($employee->lastname ?? 'U',0,1));
    $avgLabel  = ($stats['avgDays'] !== null) ? $stats['avgDays'].' hari' : '-';
    $rateLabel = $stats['doneRate'].'%';
    $statusColors  = ['done'=>'#07a0cf','process'=>'#ff5915','review'=>'#d97706','hold'=>'#64748b'];
    $statusBgAlpha = ['done'=>'rgba(7,160,207,.12)','process'=>'rgba(255,89,21,.12)','review'=>'rgba(245,158,11,.12)','hold'=>'rgba(148,163,184,.12)'];
    $chartJson = json_encode($chartData ?? ['labels'=>[],'taskDone'=>[],'taskTotal'=>[],'attendance'=>[]]);

    $periodLabels = ['all_time'=>'Semua','this_year'=>'Tahun Ini','last_year'=>'Tahun Lalu','this_quarter'=>'Kuartal Ini','this_month'=>'Bulan Ini'];

    /* stat card bottom accent colors */
    $statAccents = [
        'total'   => 'var(--c-text)',
        'done'    => '#07a0cf',
        'process' => '#ff5915',
        'review'  => '#d97706',
        'late'    => '#ef4444',
        'avg'     => '#d97706',
        'rate'    => '#0284c7',
    ];
@endphp

<div class="hr-page">

    {{-- ── PERIOD ── --}}
    <div class="period-bar">
        <div class="period-group">
            @foreach([['all_time','Semua'],['this_year','Tahun Ini'],['last_year','Tahun Lalu'],['this_quarter','Kuartal Ini'],['this_month','Bulan Ini']] as [$val,$lbl])
            <button wire:click="$set('period','{{ $val }}')" class="period-btn {{ $period===$val ? 'period-btn-active' : 'period-btn-inactive' }}">
                {{ $lbl }}
            </button>
            @endforeach
        </div>
    </div>

    {{-- ── PROFILE + KPI ── --}}
    <div style="display:flex;gap:14px;flex-wrap:wrap;">

        <div class="card profile-card">
            <div class="avatar-ring">
                @if($avatar)
                    <img src="{{ $avatar }}" class="avatar-img" alt="{{ $employee->firstname }}">
                @else
                    <div class="avatar-initials">{{ $initials }}</div>
                @endif
            </div>
            <div style="flex:1;min-width:0;">
                <div class="profile-name">{{ $employee->firstname }} {{ $employee->lastname }}</div>
                <div class="profile-email">{{ $employee->email }}</div>
                <div style="margin-top:10px;display:flex;gap:5px;flex-wrap:wrap;">
                    @foreach($employee->roles as $role)
                    <span class="role-chip">{{ \Illuminate\Support\Str::headline($role->name) }}</span>
                    @endforeach
                </div>
                <div style="margin-top:12px;font-size:11px;color:var(--hr-muted);font-weight:600;">
                    Periode: <span style="color:#07a0cf;">{{ $periodLabels[$period] ?? 'Semua' }}</span>
                </div>
            </div>
        </div>

        <div class="card kpi-card" style="background:{{ $kpi['bg'] }};">
            <div class="kpi-badge" style="color:{{ $kpi['color'] }};">KPI Score</div>
            <div class="kpi-number" style="color:{{ $kpi['color'] }};">{{ $kpi['score'] }}</div>
            <span class="kpi-label-tag" style="background:{{ $kpi['color'] }};">{{ $kpi['label'] }}</span>
            <div class="kpi-sub" style="color:{{ $kpi['color'] }};">dari 100 poin</div>
        </div>

    </div>

    {{-- ── TASK STATS ── --}}
    <div class="stat-grid">
        @php
        $statItems = [
            ['val'=>$stats['total'],      'lbl'=>'Total Task',      'color'=>$statAccents['total']],
            ['val'=>$stats['done'],       'lbl'=>'Selesai',         'color'=>$statAccents['done']],
            ['val'=>$stats['process'],    'lbl'=>'Process',         'color'=>$statAccents['process']],
            ['val'=>$stats['review'],     'lbl'=>'Review',          'color'=>$statAccents['review']],
            ['val'=>$stats['late'],       'lbl'=>'Terlambat',       'color'=>$statAccents['late']],
            ['val'=>$avgLabel,            'lbl'=>'Avg Selesai',     'color'=>$statAccents['avg']],
            ['val'=>$rateLabel,           'lbl'=>'Completion Rate', 'color'=>$statAccents['rate']],
        ];
        @endphp
        @foreach($statItems as $s)
        <div class="stat-card" style="border-bottom:3px solid {{ $s['color'] }};">
            <div class="stat-val" style="color:{{ $s['color'] }};">{{ $s['val'] }}</div>
            <div class="stat-lbl">{{ $s['lbl'] }}</div>
        </div>
        @endforeach
    </div>

    {{-- ── CHARTS ── --}}
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <div class="chart-grid">
        <div class="card">
            <div class="card-title">Trend Task</div>
            <div wire:ignore style="position:relative;height:210px;">
                <canvas id="taskTrendChart"></canvas>
            </div>
        </div>
        <div class="card">
            <div class="card-title">Attendance Rate</div>
            <div wire:ignore style="position:relative;height:250px;">
                <canvas id="attendTrendChart"></canvas>
            </div>
        </div>
    </div>

    {{-- ── ATTENDANCE ── --}}
    <div class="card">
        <div class="card-title">Kehadiran Periode Ini</div>
        <div class="attend-grid">
            <div class="attend-item">
                <div class="attend-val" style="color:#07a0cf;">{{ $absenceStat['hadir'] }}</div>
                <div class="attend-lbl">Hadir</div>
            </div>
            <div class="attend-item">
                <div class="attend-val" style="color:#0284c7;">{{ $absenceStat['wfa'] }}</div>
                <div class="attend-lbl">WFA</div>
            </div>
            <div class="attend-item">
                <div class="attend-val" style="color:#d97706;">{{ $absenceStat['cuti'] }}</div>
                <div class="attend-lbl">Cuti / Izin</div>
            </div>
            <div class="attend-item">
                <div class="attend-val" style="color:#9333ea;">{{ $absenceStat['tukar'] }}</div>
                <div class="attend-lbl">Tukar Hari</div>
            </div>
            <div class="attend-item">
                <div class="attend-val" style="color:var(--hr-muted);">{{ $absenceStat['workDays'] }}</div>
                <div class="attend-lbl">Hari Kerja</div>
            </div>
        </div>
        @php
            $ar = $absenceStat['attendRate'];
            $arColor = $ar >= 80 ? '#07a0cf' : ($ar >= 60 ? '#d97706' : '#ef4444');
        @endphp
        <div class="prog-label">
            <span>Attendance Rate</span>
            <span style="font-weight:800;color:{{ $arColor }};font-family:var(--font-mono);">{{ $ar }}%</span>
        </div>
        <div class="prog-bar-bg">
            <div class="prog-bar-fill" style="width:{{ $ar }}%;background:{{ $arColor }};"></div>
        </div>
    </div>

    {{-- ── ATTENDANCE DETAIL TABLES ── --}}
    @php
        $now = \Carbon\Carbon::now();

        // $employee->id adalah users.id (Filament record = User model di konteks ini)
        // Jika Employee punya kolom user_id, ganti dengan $employee->user_id
        $absUserId = isset($employee->user_id) ? $employee->user_id : $employee->id;

        $absBase = \App\Models\Absence::where('user_id', $absUserId)
            ->where('approval_status', 'Approved');

        $absScoped = match($period) {
            'this_month'   => (clone $absBase)->whereMonth('start_time', $now->month)->whereYear('start_time', $now->year),
            'this_quarter' => (clone $absBase)->whereBetween('start_time', [
                                $now->copy()->firstOfQuarter()->startOfDay(),
                                $now->copy()->lastOfQuarter()->endOfDay(),
                             ]),
            'this_year'    => (clone $absBase)->whereYear('start_time', $now->year),
            'last_year'    => (clone $absBase)->whereYear('start_time', $now->year - 1),
            default        => (clone $absBase),
        };

        $allAbsences = $absScoped->orderBy('start_time')->get();

        $dayName = ['Sunday'=>'Minggu','Monday'=>'Senin','Tuesday'=>'Selasa',
                    'Wednesday'=>'Rabu','Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu'];

        // WFA / WFH
        $wfaRows = $allAbsences->whereIn('category', ['wfa','wfh'])->map(function($a) use ($dayName) {
            $start  = \Carbon\Carbon::parse($a->start_time);
            $end    = $a->end_time ? \Carbon\Carbon::parse($a->end_time) : null;
            $durasi = ($end && !$start->isSameDay($end)) ? ($start->diffInDays($end) + 1).' hari' : '1 hari';
            $tgl    = $start->format('d M Y');
            if ($end && !$start->isSameDay($end)) $tgl .= ' – '.$end->format('d M Y');
            return [
                'tanggal'     => $tgl,
                'kategori'    => strtoupper($a->category),
                'durasi'      => $durasi,
                'keterangan'  => $a->description ?: '—',
                'work_report' => $a->work_report ?: null,
            ];
        })->values();

        // Cuti & Izin
        $cutiRows = $allAbsences->whereIn('category', ['cuti','izin'])->map(function($a) {
            $start  = \Carbon\Carbon::parse($a->start_time);
            $end    = $a->end_time ? \Carbon\Carbon::parse($a->end_time) : null;
            $days   = $end ? (int) $start->startOfDay()->diffInDays($end->startOfDay()) : 1;
            $days   = max(1, $days);
            $durasi = $days . ' hari';
            $tgl    = $start->format('d M Y');
            if ($end && !$start->isSameDay($end)) $tgl .= ' – '.$end->format('d M Y');
            return [
                'tanggal'    => $tgl,
                'jenis'      => ucfirst($a->category),
                'durasi'     => $durasi,
                'keterangan' => $a->description ?: '—',
            ];
        })->values();

        // Tukar Hari
        // Dari contoh data: start_time = hari pengganti masuk (hari kerja),
        // end_time = juga hari pengganti (bisa sama). Hari libur ada di description.
        $tukarRows = $allAbsences->where('category', 'tukar_hari')->map(function($a) use ($dayName) {
            $masuk = \Carbon\Carbon::parse($a->start_time);
            $namaHariMasuk = $dayName[$masuk->format('l')] ?? $masuk->format('l');
            return [
                'tgl_masuk'  => $masuk->format('d M Y').' ('.$namaHariMasuk.')',
                'keterangan' => $a->description ?: '—',
            ];
        })->values();

        $absTableDefs = [
            [
                'title'   => 'Detail WFA / WFH',
                'color'   => '#0284c7',
                'bgColor' => 'rgba(59,190,255,.08)',
                'rows'    => $wfaRows,
                'type'    => 'wfa',
                'cols'    => ['Tanggal', 'Tipe', 'Durasi', 'Keterangan'],
                'keys'    => ['tanggal', 'kategori', 'durasi', 'keterangan'],
            ],
            [
                'title'   => 'Detail Cuti / Izin',
                'color'   => '#d97706',
                'bgColor' => 'rgba(245,158,11,.08)',
                'rows'    => $cutiRows,
                'type'    => '',
                'cols'    => ['Tanggal', 'Jenis', 'Durasi', 'Keterangan'],
                'keys'    => ['tanggal', 'jenis', 'durasi', 'keterangan'],
            ],
            [
                'title'   => 'Detail Tukar Hari',
                'color'   => '#9333ea',
                'bgColor' => 'rgba(147,51,234,.08)',
                'rows'    => $tukarRows,
                'type'    => '',
                'cols'    => ['Tgl Masuk Pengganti', 'Keterangan'],
                'keys'    => ['tgl_masuk', 'keterangan'],
            ],
        ];
    @endphp

    <div class="hr-grid-3">
        @foreach($absTableDefs as $tbl)
        <div class="card" style="border-top:3px solid {{ $tbl['color'] }};">
            <div class="card-title" style="margin-bottom:12px;">
                {{ $tbl['title'] }}
                <span style="margin-left:auto;background:{{ $tbl['bgColor'] }};color:{{ $tbl['color'] }};font-size:10px;font-weight:700;padding:2px 9px;border-radius:999px;letter-spacing:.3px;text-transform:none;">{{ count($tbl['rows']) }}</span>
            </div>
            @if(count($tbl['rows']) > 0)
            <div class="thin-scroll" style="max-height:260px;overflow-y:auto;">
                <table class="abs-table">
                    <thead>
                        <tr>
                            @foreach($tbl['cols'] as $col)
                            <th>{{ $col }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($tbl['rows'] as $row)
                        <tr>
                            @foreach($tbl['keys'] as $key)
                            <td>{{ $row[$key] ?? '—' }}</td>
                            @endforeach
                        </tr>
                        @if(($tbl['type'] ?? '') === 'wfa' && !empty($row['work_report']))
                        <tr style="background:rgba(59,190,255,.04);border-bottom:1px solid rgba(59,190,255,.12)!important;">
                            <td colspan="{{ count($tbl['cols']) }}" style="padding:10px 14px 12px;">
                                <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#0284c7;margin-bottom:4px;">Work Report</div>
                                <div style="font-size:12px;color:var(--hr-body);line-height:1.6;white-space:pre-line;">{{ $row['work_report'] }}</div>
                            </td>
                        </tr>
                        @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <div style="text-align:center;padding:32px 0;color:var(--hr-muted);font-size:11px;">
                <div style="font-size:28px;opacity:.25;margin-bottom:8px;">📅</div>
                Tidak ada data
            </div>
            @endif
        </div>
        @endforeach
    </div>

    {{-- ── TASK LIST + BREAKDOWN ── --}}
    <div class="hr-grid-main">

        <div class="card">
            <div class="card-title">Detail Task
                <span style="margin-left:auto;background:var(--hr-chip);color:var(--hr-muted);font-size:10px;font-weight:700;padding:2px 9px;border-radius:999px;letter-spacing:.3px;text-transform:none;">{{ count($taskDetail) }} items</span>
            </div>
            @if(count($taskDetail) > 0)
            <div class="task-header">
                <span>Nama Task</span>
                <span>Status</span>
                <span>Mulai</span>
                <span>Deadline</span>
                <span>Durasi</span>
                <span>Catatan Selesai</span>
            </div>
            <div class="thin-scroll" style="max-height:380px;overflow-y:auto;">
                @foreach($taskDetail as $t)
                @php $sc = $statusColors[$t['status']] ?? '#64748b'; $sbg = $statusBgAlpha[$t['status']] ?? 'rgba(148,163,184,.12)'; @endphp
                <div class="task-row {{ $t['is_late'] ? 'task-row-late' : '' }}">
                    <div>
                        <div class="task-name">{{ $t['nama_task'] }}</div>
                        <div class="task-client">{{ $t['client'] }}</div>
                    </div>
                    <div>
                        <span class="status-chip" style="background:{{ $sbg }};color:{{ $sc }};">{{ ucfirst((string) $t['status']) }}</span>
                        @if($t['is_late'])<div class="late-flag">⚠ Terlambat</div>@endif
                    </div>
                    <div class="task-date">{{ $t['mulai'] }}</div>
                    <div class="task-date" style="color:{{ $t['is_late'] ? '#ef4444' : '#64748b' }};">{{ $t['deadline'] }}</div>
                    <div class="task-dur" style="color:{{ $t['status']==='done' ? '#07a0cf' : '#d97706' }};">{{ $t['durasi'] }}</div>
                    <div style="font-size:11px;color:var(--hr-muted);line-height:1.5;">
                        @if($t['done_note'])
                            <span style="font-style:italic;">{{ Str::limit($t['done_note'], 80) }}</span>
                        @else
                            <span style="color:var(--hr-faint);">—</span>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
            @else
            <div style="text-align:center;padding:48px 0;color:var(--hr-muted);font-size:12px;">
                <div style="font-size:36px;margin-bottom:10px;opacity:.3;">📋</div>
                Tidak ada task di periode ini
            </div>
            @endif
        </div>

        <div class="card">
            <div class="card-title">Task Breakdown</div>
            @foreach($taskSummary as $item)
            @php $pct = $stats['total'] > 0 ? round($item['count']/$stats['total']*100) : 0; @endphp
            <div class="breakdown-item">
                <div class="breakdown-header">
                    <span class="breakdown-name">{{ $item['label'] }}</span>
                    <div class="breakdown-meta">
                        <span class="breakdown-count" style="color:{{ $item['color'] }};">{{ $item['count'] }}</span>
                        <span class="breakdown-pct">{{ $pct }}%</span>
                    </div>
                </div>
                <div class="prog-bar-bg">
                    <div class="prog-bar-fill" style="width:{{ $pct }}%;background:{{ $item['color'] }};"></div>
                </div>
            </div>
            @endforeach

            @if($stats['avgDays'] !== null)
            <div class="avg-box">
                <div class="avg-box-label">Avg per Task</div>
                <div class="avg-box-num">{{ $stats['avgDays'] }}</div>
                <div class="avg-box-sub">hari per task</div>
            </div>
            @endif
        </div>

    </div>

    {{-- ── DONE NOTES ── --}}
    @if(count($doneNotes) > 0)
    <div class="card">
        <div class="card-title">Catatan Penyelesaian
            <span style="margin-left:auto;background:var(--hr-chip);color:var(--hr-muted);font-size:10px;font-weight:700;padding:3px 10px;border-radius:999px;letter-spacing:0.3px;text-transform:none;">{{ count($doneNotes) }} catatan</span>
        </div>
        <div class="thin-scroll" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px;max-height:320px;overflow-y:auto;">
            @foreach($doneNotes as $note)
            <div class="note-card">
                <h4 class="note-task">{{ $note['nama_task'] }}</h4>
                <small class="note-meta">{{ $note['client'] }} · Selesai {{ $note['selesai'] }} · {{ $note['duration'] }}</small>
                <p class="note-body">{{ $note['done_note'] }}</p>
            </div>
            @endforeach
        </div>
    </div>
    @endif

</div>{{-- end .hr-page --}}

<script>
(function () {
    var chartData = {!! $chartJson !!};

    var BRAND  = '#07a0cf';
    var BRAND2 = '#0284c7';
    var GRID   = '#f1f5f9';
    var FONT   = "'Inter', sans-serif";

    function destroyChart(id) {
        var ex = Chart.getChart(id);
        if (ex) ex.destroy();
    }

    function buildCharts() {
        destroyChart('taskTrendChart');
        destroyChart('attendTrendChart');

        var t = document.getElementById('taskTrendChart');
        var a = document.getElementById('attendTrendChart');
        if (!t || !a) return;

        Chart.defaults.font.family = FONT;

        new Chart(t, {
            type: 'bar',
            data: {
                labels: chartData.labels,
                datasets: [
                    {
                        label: 'Total',
                        data: chartData.taskTotal,
                        backgroundColor: 'rgba(59,190,255,0.18)',
                        borderColor: 'rgba(59,190,255,0.4)',
                        borderWidth: 1,
                        borderRadius: 6,
                        order: 2
                    },
                    {
                        label: 'Selesai',
                        data: chartData.taskDone,
                        backgroundColor: BRAND,
                        borderRadius: 6,
                        order: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { size: 10, weight: '700' }, boxWidth: 10, padding: 10, color: '#64748b' }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 10 }, color: '#64748b' },
                        border: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: GRID },
                        ticks: { font: { size: 10 }, color: '#64748b', stepSize: 1 },
                        border: { display: false, dash: [4,4] }
                    }
                }
            }
        });

        new Chart(a, {
            type: 'line',
            data: {
                labels: chartData.labels,
                datasets: [{
                    label: 'Attendance %',
                    data: chartData.attendance,
                    borderColor: BRAND,
                    clip: false,
                    backgroundColor: function(ctx) {
                        var h = ctx.chart.height || 180;
                        var g = ctx.chart.ctx.createLinearGradient(0, 0, 0, h);
                        g.addColorStop(0, 'rgba(7,160,207,0.22)');
                        g.addColorStop(1, 'rgba(7,160,207,0.02)');
                        return g;
                    },
                    borderWidth: 2.5,
                    pointBackgroundColor: BRAND,
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { top: 20, right: 12, bottom: 0, left: 4 } },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { size: 10, weight: '600', family: FONT }, boxWidth: 8, boxHeight: 8, padding: 12, color: '#64748b' }
                    },
                    tooltip: {
                        callbacks: { label: function(c) { return '  ' + c.raw + '%'; } },
                        backgroundColor: '#0f172a',
                        titleFont: { size: 10, family: FONT },
                        bodyFont: { size: 12, weight: '700', family: FONT },
                        padding: 10,
                        cornerRadius: 8
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 10, family: FONT }, color: '#64748b', maxRotation: 0 },
                        border: { display: false }
                    },
                    y: {
                        min: function() {
                            var vals = (chartData.attendance || []).filter(function(v){ return v !== null && v !== undefined; });
                            if (!vals.length) return 60;
                            var minVal = Math.min.apply(null, vals);
                            // snap ke bawah kelipatan 5, kasih ruang 5 poin
                            return Math.max(0, Math.floor((minVal - 5) / 5) * 5);
                        }(),
                        max: 103,
                        grid: { color: '#f0f4f8' },
                        ticks: {
                            font: { size: 10, family: FONT },
                            color: '#64748b',
                            stepSize: 5,
                            callback: function(v) {
                                if (v > 100) return '';
                                return v + '%';
                            }
                        },
                        border: { display: false }
                    }
                }
            }
        });
    }

    buildCharts();

    document.addEventListener('livewire:updated', function () {
        chartData = {!! $chartJson !!};
        buildCharts();
    });
})();
</script>
</x-filament-panels::page>