<x-filament-panels::page>
@php
try {
    $settings = app(\App\Settings\GeneralSettings::class);
} catch (\Exception $e) {
    $settings = null;
}

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
| Menghapus karakter # dari HEX color.
*/
$stripHash = fn ($value) => ltrim((string) ($value ?? ''), '#');

/*
|--------------------------------------------------------------------------
| Theme from GeneralSettings
|--------------------------------------------------------------------------
| site_theme disimpan dalam bentuk JSON / array.
*/
$themeArray = is_array($settings?->site_theme)
    ? $settings->site_theme
    : [];

/*
|--------------------------------------------------------------------------
| Default Theme Colors
|--------------------------------------------------------------------------
*/
$themeDefaults = [
    'primary'      => '90c646',
    'primary_dark' => '6fa335',
    'success'      => '10b981',
    'warning'      => 'f59e0b',
    'danger'       => 'ef4444',
    'info'         => '3b82f6',
];

/*
|--------------------------------------------------------------------------
| Merge Theme
|--------------------------------------------------------------------------
| Default → site_theme
| site_theme akan override default.
*/
$theme = array_merge(
    $themeDefaults,
    $themeArray
);

/*
|--------------------------------------------------------------------------
| Normalize HEX colors
|--------------------------------------------------------------------------
*/
$theme = array_map(
    $stripHash,
    $theme
);

/*
|--------------------------------------------------------------------------
| Primary Colors
|--------------------------------------------------------------------------
| Priority:
| 1. GeneralSettings field
| 2. site_theme
| 3. default
|--------------------------------------------------------------------------
*/

$primaryFrom = $stripHash(
    $settings?->primary_gradient_from
);

$primaryTo = $stripHash(
    $settings?->primary_gradient_to
);

$primaryColor = '#' . (
    $primaryFrom !== ''
        ? $primaryFrom
        : $theme['primary']
);

$secondaryColor = '#' . (
    $primaryTo !== ''
        ? $primaryTo
        : $theme['primary_dark']
);

/*
|--------------------------------------------------------------------------
| Semantic Colors
|--------------------------------------------------------------------------
*/

$successColor = '#' . (
    $theme['success'] ?: $themeDefaults['success']
);

$warningColor = '#' . (
    $theme['warning'] ?: $themeDefaults['warning']
);

$dangerColor = '#' . (
    $theme['danger'] ?: $themeDefaults['danger']
);

$infoColor = '#' . (
    $theme['info'] ?: $themeDefaults['info']
);

/*
|--------------------------------------------------------------------------
| HEX → RGBA Helper
|--------------------------------------------------------------------------
|
| Example:
| $rgba('#90c646', 0.1)
| → rgba(144, 198, 70, 0.1)
|
*/
$rgba = function ($hex, $opacity) {
    $hex = ltrim(
        (string) ($hex ?? '#000000'),
        '#'
    );

    if (strlen($hex) === 3) {
        $hex =
            $hex[0] . $hex[0] .
            $hex[1] . $hex[1] .
            $hex[2] . $hex[2];
    }

    if (strlen($hex) !== 6) {
        $hex = '000000';
    }

    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));

    return "rgba({$r}, {$g}, {$b}, {$opacity})";
};
@endphp

    <style>
 :root {
        --primary: {{ $primaryColor }};
        --primary-dark: {{ $secondaryColor }};

        --success: {{ $successColor }};
        --warning: {{ $warningColor }};
        --danger: {{ $dangerColor }};
        --info: {{ $infoColor }};

        --primary-05: {{ $rgba($primaryColor, 0.05) }};
        --primary-10: {{ $rgba($primaryColor, 0.10) }};
        --primary-15: {{ $rgba($primaryColor, 0.15) }};
        --primary-20: {{ $rgba($primaryColor, 0.20) }};
        --primary-30: {{ $rgba($primaryColor, 0.30) }};
    }
        .analytics-page {
            width: 100%;
            max-width: 100%;
            margin: 0;
            padding: 1.25rem;
            box-sizing: border-box;
            overflow: hidden;
        }

        /* =========================================================
           FILTER BAR
        ========================================================= */

        .filter-bar {
            width: 100%;
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-sizing: border-box;
        }

        .filter-group {
            justify-content: center;
            display: flex;
            flex-direction: column;
            gap: .4rem;
            min-width: 0;
        }

        .filter-label {
            font-size: .7rem;
            font-weight: 600;
            color: #6b7280;
        }

        .filter-input {
            width: 220px;
            max-width: 100%;
            height: 40px;
            padding: 0 .75rem;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #fff;
            color: #374151;
            font-size: .8rem;
            box-sizing: border-box;
        }

        .quick-filters {
            display: flex;
            flex-wrap: wrap;
            gap: .4rem;
            justify-content: flex-start;
        }

        .quick-btn {
            height: 40px;
            padding: 0 .8rem;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #fff;
            color: #4b5563;
            font-size: .75rem;
            cursor: pointer;
            white-space: nowrap;
            transition: all .15s ease;
        }

        .quick-btn:hover {
            background: #f3f4f6;
        }

        .quick-btn.active {
            background: var(--primary, #90c646);
            border-color: var(--primary, #90c646);
            color: #fff;
        }

        /* =========================================================
           TABS
        ========================================================= */

        .tab-container {
            width: 100%;
            display: flex;
            flex-wrap: wrap;
            gap: .3rem;
            margin-bottom: 1.5rem;
            justify-content: center;
            box-sizing: border-box;
        }

        .tab-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .4rem;
            min-height: 38px;
            padding: .55rem .85rem;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #fff;
            color: #6b7280;
            font-size: .75rem;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
            transition: all .15s ease;
        }

        .tab-btn:hover {
            border-color: #d1d5db;
            color: #374151;
            background: #f9fafb;
        }

        .tab-btn.active {
            background: var(--primary, #90c646);
            border-color: var(--primary, #90c646);
            color: #fff;
        }

        .tab-icon {
            width: 1rem;
            height: 1rem;
            flex-shrink: 0;
        }

        .stat-icon {
            width: .95rem;
            height: .95rem;
            flex-shrink: 0;
            vertical-align: -2px;
            display: inline-block;
        }

        .section-icon {
            width: .95rem;
            height: .95rem;
            flex-shrink: 0;
        }

        .badge-icon {
            width: .8rem;
            height: .8rem;
            flex-shrink: 0;
            display: inline-block;
            vertical-align: -1px;
        }

        /* =========================================================
           GRID SYSTEM
        ========================================================= */

        .top-stats-grid {
            width: 100%;
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
            box-sizing: border-box;
        }

        .top-stats-grid.three-stats {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .top-stats-grid.projects-stats {
            max-width: 900px;
            margin-left: auto;
            margin-right: auto;
        }

        .grid-2 {
            width: 100%;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
            box-sizing: border-box;
        }

        .grid-3 {
            width: 100%;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1rem;
            box-sizing: border-box;
        }

        .overview-grid {
            width: 100%;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
            box-sizing: border-box;
        }

        .overview-col {
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        /* =========================================================
           CARD
        ========================================================= */

        .card {
            min-width: 0;
            width: 100%;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, .03);
            overflow: hidden;
            box-sizing: border-box;
        }

        .card:hover {
            box-shadow: 0 3px 10px rgba(0, 0, 0, .05);
        }

        .card-header {
            min-width: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
            padding: .9rem 1rem;
            background: #fafafa;
            border-bottom: 1px solid #f3f4f6;
            box-sizing: border-box;
        }

        .card-header h3 {
            min-width: 0;
            margin: 0;
            font-size: .8rem;
            font-weight: 600;
            color: #1f2937;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: .4rem;
        }

        .card-body {
            min-width: 0;
            padding: 1rem;
            box-sizing: border-box;
        }

        .card-body-title {
            display: flex;
            align-items: center;
            gap: .4rem;
            font-size: .875rem;
            font-weight: 600;
            margin: 0 0 .75rem;
            color: #1f2937;
        }

        /* =========================================================
           STAT CARDS
        ========================================================= */

        .stat-mini {
            padding: 1rem;
            min-width: 0;
        }

        .stat-mini-content {
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: .4rem;
        }

        .stat-mini-label {
            font-size: .68rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: .35px;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: flex;
            align-items: center;
            gap: .3rem;
        }

        .stat-mini-value {
            min-width: 0;
            font-size: clamp(1rem, 1.5vw, 1.35rem);
            font-weight: 700;
            color: #1f2937;
            line-height: 1.3;
            overflow-wrap: anywhere;
        }

        .stat-value.text-success,
        .stat-mini-value.text-success {
            color: #10b981;
        }

        .stat-value.text-danger,
        .stat-mini-value.text-danger {
            color: #ef4444;
        }

        .stat-value.text-warning,
        .stat-mini-value.text-warning {
            color: #f59e0b;
        }

        .stat-value.text-primary,
        .stat-mini-value.text-primary {
            color: var(--primary, #90c646);
        }

        .stat-value.text-info,
        .stat-mini-value.text-info {
            color: #3b82f6;
        }

        /* =========================================================
           MINI STATS
        ========================================================= */

        .mini-stats {
            display: flex;
            flex-direction: column;
            gap: 0;
        }

        .mini-stat {
            min-width: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: .75rem 0;
            border-bottom: 1px solid #f3f4f6;
        }

        .mini-stat:last-child {
            border-bottom: none;
        }

        .mini-stat-label {
            min-width: 0;
            font-size: .8rem;
            color: #6b7280;
            overflow: hidden;
            text-overflow: ellipsis;
            display: flex;
            align-items: center;
            gap: .35rem;
        }

        .mini-stat-value {
            flex-shrink: 0;
            font-size: .85rem;
            font-weight: 600;
            color: #1f2937;
            text-align: right;
        }

        /* =========================================================
           ACCOUNT
        ========================================================= */

        .account-list {
            display: flex;
            flex-direction: column;
            gap: .5rem;
        }

        .account-row {
            min-width: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: .8rem;
            background: #f9fafb;
            border-radius: 8px;
            box-sizing: border-box;
        }

        .account-row > div:first-child {
            min-width: 0;
        }

        .account-row small {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .account-balance {
            font-size: .85rem;
            font-weight: 700;
            color: #1f2937;
            white-space: nowrap;
        }

        .text-right {
            text-align: right;
        }

        .text-gray-500 {
            color: #6b7280;
        }

        .text-sm {
            font-size: .8rem;
        }

        /* =========================================================
           TABLE
        ========================================================= */

        .table-wrapper {
            width: 100%;
            max-width: 100%;
            overflow-x: auto;
            overflow-y: hidden;
            -webkit-overflow-scrolling: touch;
        }

        .data-table {
            width: 100%;
            min-width: 650px;
            border-collapse: collapse;
            font-size: .78rem;
        }

        .data-table th {
            padding: .75rem .85rem;
            text-align: left;
            font-size: .65rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .35px;
            color: #6b7280;
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
            white-space: nowrap;
        }

        .data-table td {
            padding: .75rem .85rem;
            border-bottom: 1px solid #f3f4f6;
            color: #374151;
            vertical-align: middle;
            white-space: nowrap;
        }

        .data-table tbody tr:hover td {
            background: #fafafa;
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        /* =========================================================
           BADGES
        ========================================================= */

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .2rem;
            padding: .25rem .55rem;
            border-radius: 999px;
            font-size: .62rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .badge-success {
            background: #dcfce7;
            color: #166534;
        }

        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-gray {
            background: #f3f4f6;
            color: #475569;
        }

        .badge-purple {
            background: #ede9fe;
            color: #5b21b6;
        }

        .badge-cyan {
            background: #cffafe;
            color: #0e7490;
        }

        .badge-count {
            flex-shrink: 0;
            padding: .25rem .5rem;
            border-radius: 999px;
            background: #e5e7eb;
            color: #6b7280;
            font-size: .62rem;
            font-weight: 600;
            white-space: nowrap;
        }

        /* =========================================================
           SECTION
        ========================================================= */

        .section {
            min-width: 0;
            margin-bottom: 1.5rem;
        }

        .section:last-child {
            margin-bottom: 0;
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: .5rem;
            margin: 0 0 .75rem;
            font-size: .85rem;
            font-weight: 600;
            color: #1f2937;
        }

        .section-title::after {
            content: "";
            flex: 1;
            height: 1px;
            background: #e5e7eb;
        }

        /* =========================================================
           EMPTY STATE
        ========================================================= */

        .empty-state {
            padding: 2rem 1rem;
            text-align: center;
        }

        .empty-state-icon {
            margin-bottom: .5rem;
            font-size: 2rem;
            opacity: .35;
        }

        .empty-state-text {
            font-size: .8rem;
            color: #9ca3af;
        }

        /* =========================================================
           PAGINATION
        ========================================================= */

        .pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: .35rem;
        }

        .pagination-btn {
            min-width: 34px;
            height: 34px;
            padding: 0 .6rem;
            border: 1px solid #d1d5db;
            border-radius: 7px;
            background: #fff;
            color: #4b5563;
            font-size: .75rem;
            cursor: pointer;
        }

        .pagination-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            height: 34px;
            padding: 0 .6rem;
            border: 1px solid #d1d5db;
            border-radius: 7px;
            background: #fff;
            color: #4b5563;
            font-size: .75rem;
            cursor: pointer;
            transition: all .15s ease;
            box-sizing: border-box;
            line-height: 1;
        }

        .pagination-btn:hover:not(.disabled):not(.active) {
            background: var(--primary-10, #f3f4f6);
            border-color: var(--primary, #90c646);
            color: var(--primary, #90c646);
        }

        .pagination-btn.active {
            background: var(--primary, #90c646);
            color: #fff;
            border-color: var(--primary, #90c646);
        }

        .pagination-btn.disabled {
            opacity: .45;
            cursor: not-allowed;
        }

        /* =========================================================
           STATUS DOT
        ========================================================= */

        .status-dot {
            display: inline-block;
            width: 7px;
            height: 7px;
            margin-right: .35rem;
            border-radius: 50%;
        }

        .status-dot.success {
            background: #10b981;
        }

        .status-dot.warning {
            background: #f59e0b;
        }

        .status-dot.danger {
            background: #ef4444;
        }

        .status-dot.info {
            background: #3b82f6;
        }

        .status-dot.gray {
            background: #6b7280;
        }

        /* =========================================================
           HELPERS
        ========================================================= */

        .stack-gap,
        .card-gap {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .mt-section {
            margin-top: 1.5rem;
        }

        .mb-section {
            margin-bottom: 1.5rem;
        }

        /* =========================================================
           FILAMENT COMPATIBILITY
        ========================================================= */

        .fi-page,
        .fi-page-content,
        .fi-main {
            min-width: 0;
        }

        /* =========================================================
           LARGE TABLET / SMALL DESKTOP
        ========================================================= */

        @media (max-width: 1280px) {

            .analytics-page {
                padding: 1rem;
            }

            .top-stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .top-stats-grid.three-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .overview-grid,
            .grid-2 {
                grid-template-columns: minmax(0, 1fr);
            }

            .grid-3 {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .filter-bar {
                align-items: stretch;
            }

            .filter-group {
                flex: 1 1 auto;
            }

            .filter-input {
                width: 100%;
            }
        }

        /* =========================================================
           MOBILE
        ========================================================= */

        @media (max-width: 640px) {

            .analytics-page {
                padding: .75rem;
            }

            .top-stats-grid,
            .top-stats-grid.three-stats,
            .top-stats-grid.projects-stats,
            .grid-2,
            .grid-3,
            .overview-grid {
                grid-template-columns: minmax(0, 1fr) !important;
            }

            .filter-bar {
                flex-direction: column;
                gap: .75rem;
                padding: .75rem;
            }

            .filter-group {
                width: 100%;
            }

            .quick-filters {
                width: 100%;
            }

            .quick-btn {
                flex: 1 1 auto;
            }

            .tab-container {
                flex-wrap: nowrap !important;
                overflow-x: auto;
                padding-bottom: .35rem;
                scrollbar-width: thin;
            }

            .tab-btn {
                flex-shrink: 0;
            }

            .card-body {
                padding: .85rem;
            }

            .stat-mini {
                padding: .85rem;
            }

            .account-row {
                align-items: flex-start;
                flex-direction: column;
            }

            .account-row .text-right {
                width: 100%;
                text-align: left;
            }

            .data-table {
                min-width: 600px;
            }

            .mini-stat {
                align-items: flex-start;
            }
        }

        /* =========================================================
           DARK MODE
        ========================================================= */

        .dark .analytics-page .card {
            background: #18181b;
            border-color: #27272a;
        }

        .dark .analytics-page .card-header,
        .dark .analytics-page .filter-bar {
            background: #18181b;
            border-color: #27272a;
        }

        .dark .analytics-page .tab-btn,
        .dark .analytics-page .quick-btn,
        .dark .analytics-page .filter-input,
        .dark .analytics-page .pagination-btn {
            background: #18181b;
            border-color: #3f3f46;
            color: #d4d4d8;
        }

        .dark .analytics-page .data-table th {
            background: #27272a;
            color: #a1a1aa;
        }

        .dark .analytics-page .data-table td {
            border-color: #27272a;
            color: #d4d4d8;
        }

        .dark .analytics-page .mini-stat {
            border-color: #27272a;
        }

        .dark .analytics-page .mini-stat-label,
        .dark .analytics-page .stat-mini-label,
        .dark .analytics-page .filter-label {
            color: #a1a1aa;
        }

        .dark .analytics-page .mini-stat-value,
        .dark .analytics-page .stat-mini-value,
        .dark .analytics-page .card-header h3,
        .dark .analytics-page .section-title {
            color: #f4f4f5;
        }

        .dark .analytics-page .account-row {
            background: #27272a;
        }
    </style>


    <div class="analytics-page">

        {{-- =====================================================
            FILTER BAR
        ====================================================== --}}

        <div class="filter-bar">

            <div class="filter-group">

                <span class="filter-label">
                    Quick Filters
                </span>

                <div class="quick-filters">

                    <button
                        type="button"
                        wire:click="setDateRange('today')"
                        class="quick-btn {{ $dateRange === 'today' ? 'active' : '' }}"
                    >
                        Today
                    </button>

                    <button
                        type="button"
                        wire:click="setDateRange('this_week')"
                        class="quick-btn {{ $dateRange === 'this_week' ? 'active' : '' }}"
                    >
                        This Week
                    </button>

                    <button
                        type="button"
                        wire:click="setDateRange('this_month')"
                        class="quick-btn {{ $dateRange === 'this_month' ? 'active' : '' }}"
                    >
                        This Month
                    </button>

                    <button
                        type="button"
                        wire:click="setDateRange('last_month')"
                        class="quick-btn {{ $dateRange === 'last_month' ? 'active' : '' }}"
                    >
                        Last Month
                    </button>

                    <button
                        type="button"
                        wire:click="setDateRange('this_quarter')"
                        class="quick-btn {{ $dateRange === 'this_quarter' ? 'active' : '' }}"
                    >
                        This Quarter
                    </button>

                    <button
                        type="button"
                        wire:click="setDateRange('this_year')"
                        class="quick-btn {{ $dateRange === 'this_year' ? 'active' : '' }}"
                    >
                        This Year
                    </button>

                </div>

            </div>


            <div class="filter-group">

                <span class="filter-label">
                    From
                </span>

                <input
                    type="date"
                    wire:model.live="dateFrom"
                    class="filter-input"
                >

            </div>


            <div class="filter-group">

                <span class="filter-label">
                    To
                </span>

                <input
                    type="date"
                    wire:model.live="dateTo"
                    class="filter-input"
                >

            </div>

        </div>


        {{-- =====================================================
            TABS
        ====================================================== --}}

        <div class="tab-container">

            <button
                type="button"
                wire:click="$set('activeTab', 'overview')"
                class="tab-btn @if($activeTab === 'overview') active @endif"
            >
                <x-heroicon-o-chart-pie class="tab-icon" />
                Overview
            </button>

            <button
                type="button"
                wire:click="$set('activeTab', 'revenue')"
                class="tab-btn @if($activeTab === 'revenue') active @endif"
            >
                <x-heroicon-o-banknotes class="tab-icon" />
                Revenue
            </button>

            <button
                type="button"
                wire:click="$set('activeTab', 'expenses')"
                class="tab-btn @if($activeTab === 'expenses') active @endif"
            >
                <x-heroicon-o-credit-card class="tab-icon" />
                Expenses
            </button>

            <button
                type="button"
                wire:click="$set('activeTab', 'advances')"
                class="tab-btn @if($activeTab === 'advances') active @endif"
            >
                <x-heroicon-o-credit-card class="tab-icon" />
                Advances
            </button>

            <button
                type="button"
                wire:click="$set('activeTab', 'advance_analysis')"
                class="tab-btn @if($activeTab === 'advance_analysis') active @endif"
            >
                <x-heroicon-o-clipboard-document-list class="tab-icon" />
                Advance Analysis
            </button>

            <button
                type="button"
                wire:click="$set('activeTab', 'transactions')"
                class="tab-btn @if($activeTab === 'transactions') active @endif"
            >
                <x-heroicon-o-building-library class="tab-icon" />
                Transactions
            </button>

            <button
                type="button"
                wire:click="$set('activeTab', 'pipeline')"
                class="tab-btn @if($activeTab === 'pipeline') active @endif"
            >
                <x-heroicon-o-flag class="tab-icon" />
                Pipeline
            </button>

            <button
                type="button"
                wire:click="$set('activeTab', 'projects')"
                class="tab-btn @if($activeTab === 'projects') active @endif"
            >
                <x-heroicon-o-folder class="tab-icon" />
                Projects
            </button>

            <button
                type="button"
                wire:click="$set('activeTab', 'assets')"
                class="tab-btn @if($activeTab === 'assets') active @endif"
            >
                <x-heroicon-o-building-office class="tab-icon" />
                Assets
            </button>

        </div>


        {{-- =====================================================
            OVERVIEW
        ====================================================== --}}

        @if($activeTab === 'overview')

            @php
                $data = $this->getOverviewData();
            @endphp


            {{-- KPI --}}

            <div class="top-stats-grid">

                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-banknotes class="stat-icon" />
                            Total Revenue
                        </span>

                        <span class="stat-mini-value text-success">
                            Rp {{ number_format($data['revenue']['all_time'], 0, ',', '.') }}
                        </span>

                    </div>

                </div>


                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-credit-card class="stat-icon" />
                            Total Expenses
                        </span>

                        <span class="stat-mini-value text-danger">
                            Rp {{ number_format($data['expenses']['all_time'], 0, ',', '.') }}
                        </span>

                    </div>

                </div>


                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-credit-card class="stat-icon" />
                            Total Advances
                        </span>

                        <span class="stat-mini-value text-warning">
                            Rp {{ number_format($data['advances']['all_time'], 0, ',', '.') }}
                        </span>

                    </div>

                </div>


                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-scale class="stat-icon" />
                            Balance
                        </span>

                        <span class="stat-mini-value text-primary">
                            Rp {{ number_format($data['balance']['total'], 0, ',', '.') }}
                        </span>

                    </div>

                </div>

            </div>


            {{-- OVERVIEW CONTENT --}}

            <div class="overview-grid">

                {{-- LEFT --}}

                <div class="overview-col">

                    {{-- CASH FLOW --}}

                    <div class="card">

                        <div class="card-body">

                            <h3 class="card-body-title">
                                <x-heroicon-o-arrow-trending-up class="section-icon" />
                                Cash Flow
                            </h3>

                            <div class="mini-stats">

                                <div class="mini-stat">

                                    <span class="mini-stat-label">
                                        Inflow (bulan ini)
                                    </span>

                                    <span class="mini-stat-value text-success">
                                        Rp {{ number_format($data['balance']['monthly_in'], 0, ',', '.') }}
                                    </span>

                                </div>


                                <div class="mini-stat">

                                    <span class="mini-stat-label">
                                        Outflow (bulan ini)
                                    </span>

                                    <span class="mini-stat-value text-danger">
                                        Rp {{ number_format($data['balance']['monthly_out'], 0, ',', '.') }}
                                    </span>

                                </div>


                                <div
                                    class="mini-stat"
                                    style="background:#f9fafb;padding:.625rem;border-radius:6px;margin-top:.25rem;"
                                >

                                    <span
                                        class="mini-stat-label"
                                        style="font-weight:600;"
                                    >
                                        Net
                                    </span>

                                    <span
                                        class="mini-stat-value {{ ($data['balance']['monthly_in'] - $data['balance']['monthly_out']) >= 0 ? 'text-success' : 'text-danger' }}"
                                    >
                                        Rp {{ number_format(($data['balance']['monthly_in'] - $data['balance']['monthly_out']), 0, ',', '.') }}
                                    </span>

                                </div>

                            </div>

                        </div>

                    </div>


                    {{-- PENDING --}}

                    <div class="card">

                        <div class="card-body">

                            <h3 class="card-body-title">
                                <x-heroicon-o-clock class="section-icon" />
                                Pending Approvals
                            </h3>

                            <div class="mini-stats">

                                <div class="mini-stat">

                                    <span class="mini-stat-label">
                                        Expenses
                                    </span>

                                    <span class="mini-stat-value">

                                        {{ $data['expenses']['pending_count'] }}

                                        <small style="color:#9ca3af;">
                                            (Rp {{ number_format($data['expenses']['pending'], 0, ',', '.') }})
                                        </small>

                                    </span>

                                </div>


                                <div class="mini-stat">

                                    <span class="mini-stat-label">
                                        Advances
                                    </span>

                                    <span class="mini-stat-value">

                                        {{ $data['advances']['pending_count'] }}

                                        <small style="color:#9ca3af;">
                                            (Rp {{ number_format($data['advances']['pending'], 0, ',', '.') }})
                                        </small>

                                    </span>

                                </div>

                            </div>

                        </div>

                    </div>


                    {{-- LEADS --}}

                    <div class="card">

                        <div class="card-body">

                            <h3 class="card-body-title">
                                <x-heroicon-o-users class="section-icon" />
                                Leads & Deals
                            </h3>

                            <div class="mini-stats">

                                <div class="mini-stat">

                                    <span class="mini-stat-label">
                                        Total Leads
                                    </span>

                                    <span class="mini-stat-value">
                                        {{ $data['leads']['total'] }}
                                    </span>

                                </div>


                                <div class="mini-stat">

                                    <span class="mini-stat-label">
                                        Converted
                                    </span>

                                    <span class="mini-stat-value text-success">

                                        {{ $data['leads']['converted'] }}

                                        <small style="color:#9ca3af;">
                                            ({{ $data['leads']['conversion_rate'] }}%)
                                        </small>

                                    </span>

                                </div>


                                <div class="mini-stat">

                                    <span class="mini-stat-label">
                                        Active Deals
                                    </span>

                                    <span class="mini-stat-value">
                                        {{ $data['deals']['active'] }}
                                    </span>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>


                {{-- RIGHT --}}

                <div class="overview-col">

                    {{-- ACCOUNTS --}}

                    <div class="card">

                        <div class="card-body">

                            <h3 class="card-body-title">
                                <x-heroicon-o-building-library class="section-icon" />
                                Payment Accounts
                            </h3>

                            <div class="account-list">

                                @forelse($data['accounts'] as $account)

                                    <div class="account-row">

                                        <div>

                                            <div style="font-weight:600;color:#1f2937;">
                                                {{ $account->name }}
                                            </div>

                                            @if($account->bank_name)

                                                <small style="color:#6b7280;">
                                                    {{ $account->bank_name }}
                                                    -
                                                    {{ $account->account_number }}
                                                </small>

                                            @endif

                                        </div>

                                        <div class="text-right">

                                            <div class="account-balance">
                                                Rp {{ number_format($account->available_balance, 0, ',', '.') }}
                                            </div>

                                        </div>

                                    </div>

                                @empty

                                    <div class="empty-state">
                                        <div class="empty-state-text">
                                            No active accounts
                                        </div>
                                    </div>

                                @endforelse

                            </div>

                        </div>

                    </div>


                    {{-- RECENT ADVANCES --}}

                    <div class="card">

                        <div class="card-header">

                            <h3>
                                <x-heroicon-o-credit-card class="stat-icon" />
                                Recent Advances
                            </h3>

                            <span class="badge-count">
                                {{ $data['advances']['recent']->count() }} items
                            </span>

                        </div>

                        <div class="card-body" style="padding:0;">

                            <div class="table-wrapper">

                                <table class="data-table">

                                    <thead>

                                        <tr>

                                            <th>Date</th>
                                            <th>User</th>
                                            <th>Amount</th>
                                            <th>Status</th>

                                        </tr>

                                    </thead>

                                    <tbody>

                                        @forelse($data['advances']['recent'] as $advance)

                                            <tr>

                                                <td>
                                                    {{ $advance->submission_date?->format('d/m/Y') ?? '-' }}
                                                </td>

                                                <td>
                                                    {{ $advance->user?->name ?? '-' }}
                                                </td>

                                                <td>
                                                    Rp {{ number_format($advance->amount, 0, ',', '.') }}
                                                </td>

                                                <td>

                                                    @switch($advance->status)

                                                        @case('Pending')
                                                            <span class="badge badge-warning">
                                                                Pending
                                                            </span>
                                                            @break

                                                        @case('Disetujui')
                                                            <span class="badge badge-info">
                                                                Approved
                                                            </span>
                                                            @break

                                                        @case('Selesai')
                                                            <span class="badge badge-success">
                                                                Done
                                                            </span>
                                                            @break

                                                        @default
                                                            <span class="badge badge-gray">
                                                                {{ $advance->status }}
                                                            </span>

                                                    @endswitch

                                                </td>

                                            </tr>

                                        @empty

                                            <tr>

                                                <td colspan="4">

                                                    <div class="empty-state">

                                                        <div class="empty-state-text">
                                                            No advances found
                                                        </div>

                                                    </div>

                                                </td>

                                            </tr>

                                        @endforelse

                                    </tbody>

                                </table>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        @endif


        {{-- =====================================================
            REVENUE
        ====================================================== --}}

        @if($activeTab === 'revenue')

            @php
                $data = $this->getRevenueData();
            @endphp


            <div class="top-stats-grid">

                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-banknotes class="stat-icon" />
                            Total Revenue
                        </span>

                        <span class="stat-mini-value text-success">
                            Rp {{ number_format($data['stats']->total_revenue ?? 0, 0, ',', '.') }}
                        </span>

                    </div>

                </div>


                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-calendar class="stat-icon" />
                            This Month
                        </span>

                        <span class="stat-mini-value">
                            Rp {{ number_format($data['stats']->current_month_revenue ?? 0, 0, ',', '.') }}
                        </span>

                    </div>

                </div>


                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-clock class="stat-icon" />
                            Pending
                        </span>

                        <span class="stat-mini-value text-warning">

                            {{ $data['stats']->pending_count ?? 0 }}

                            <small style="color:#9ca3af;">
                                (Rp {{ number_format($data['stats']->pending_total ?? 0, 0, ',', '.') }})
                            </small>

                        </span>

                    </div>

                </div>


                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-exclamation-triangle class="stat-icon" />
                            Overdue
                        </span>

                        <span class="stat-mini-value text-danger">

                            {{ $data['stats']->overdue_count ?? 0 }}

                            <small style="color:#9ca3af;">
                                (Rp {{ number_format($data['stats']->overdue_total ?? 0, 0, ',', '.') }})
                            </small>

                        </span>

                    </div>

                </div>

            </div>


            <div class="grid-2">

                <div class="card">

                    <div class="card-body">

                        <h3 class="card-body-title">
                            <x-heroicon-o-chart-pie class="section-icon" />
                            Status Distribution
                        </h3>

                        <div class="mini-stats">

                            <div class="mini-stat">

                                <span class="mini-stat-label">
                                    <x-heroicon-o-check-circle class="stat-icon" />
                                    Paid
                                </span>

                                <span class="mini-stat-value text-success">

                                    {{ $data['distribution']->paid_count }}

                                    <small style="color:#9ca3af;">
                                        (Rp {{ number_format($data['distribution']->paid, 0, ',', '.') }})
                                    </small>

                                </span>

                            </div>


                            <div class="mini-stat">

                                <span class="mini-stat-label">
                                    <x-heroicon-o-clock class="stat-icon" />
                                    Pending
                                </span>

                                <span class="mini-stat-value text-warning">

                                    {{ $data['distribution']->pending_count }}

                                    <small style="color:#9ca3af;">
                                        (Rp {{ number_format($data['distribution']->pending, 0, ',', '.') }})
                                    </small>

                                </span>

                            </div>


                            <div class="mini-stat">

                                <span class="mini-stat-label">
                                    <x-heroicon-o-x-circle class="stat-icon" />
                                    Cancelled
                                </span>

                                <span class="mini-stat-value text-danger">

                                    {{ $data['distribution']->cancelled_count }}

                                    <small style="color:#9ca3af;">
                                        (Rp {{ number_format($data['distribution']->cancelled, 0, ',', '.') }})
                                    </small>

                                </span>

                            </div>


                            <div class="mini-stat">

                                <span class="mini-stat-label">
                                    <x-heroicon-o-pencil-square class="stat-icon" />
                                    Draft
                                </span>

                                <span class="mini-stat-value">

                                    {{ $data['distribution']->draft_count }}

                                    <small style="color:#9ca3af;">
                                        (Rp {{ number_format($data['distribution']->draft, 0, ',', '.') }})
                                    </small>

                                </span>

                            </div>

                        </div>

                    </div>

                </div>


                <div class="card">

                    <div class="card-header">

                        <h3>
                            <x-heroicon-o-document-text class="stat-icon" />
                            Recent Invoices
                        </h3>

                    </div>

                    <div class="card-body" style="padding:0;">

                        <div class="table-wrapper">

                            <table class="data-table">

                                <thead>

                                    <tr>

                                        <th>Invoice #</th>
                                        <th>Customer</th>
                                        <th style="text-align:right;">Total</th>
                                        <th>Status</th>
                                        <th>Due</th>

                                    </tr>

                                </thead>

                                <tbody>

                                    @forelse($data['recent'] as $invoice)

                                        <tr>

                                            <td>
                                                <strong>{{ $invoice->uuid }}</strong>
                                            </td>

                                            <td>
                                                {{ Str::limit($invoice->nama_perusahaan, 20) }}
                                            </td>

                                            <td style="text-align:right;">
                                                Rp {{ number_format($invoice->grand_total, 0, ',', '.') }}
                                            </td>

                                            <td>

                                                @switch($invoice->status)

                                                    @case('paid')
                                                        <span class="badge badge-success">
                                                            Paid
                                                        </span>
                                                        @break

                                                    @case('pending')
                                                        <span class="badge badge-warning">
                                                            Pending
                                                        </span>
                                                        @break

                                                    @case('cancelled')
                                                        <span class="badge badge-danger">
                                                            Cancelled
                                                        </span>
                                                        @break

                                                    @default
                                                        <span class="badge badge-gray">
                                                            Draft
                                                        </span>

                                                @endswitch

                                            </td>

                                            <td>
                                                {{ $invoice->due_date?->format('d/m') ?? '-' }}
                                            </td>

                                        </tr>

                                    @empty

                                        <tr>

                                            <td colspan="5">

                                                <div class="empty-state">

                                                    <div class="empty-state-text">
                                                        No invoices found
                                                    </div>

                                                </div>

                                            </td>

                                        </tr>

                                    @endforelse

                                </tbody>

                            </table>

                        </div>

                    </div>

                </div>

            </div>

        @endif


        {{-- =====================================================
            EXPENSES
        ====================================================== --}}

        @if($activeTab === 'expenses')

            @php
                $data = $this->getExpensesData();
            @endphp


            {{-- KPI STATS --}}
            <div class="top-stats-grid">

                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">
                            <x-heroicon-o-credit-card class="stat-icon" />
                            Total Expenses
                        </span>
                        <span class="stat-mini-value text-danger">
                            Rp {{ number_format($data['total_expenses'], 0, ',', '.') }}
                        </span>
                    </div>
                </div>

                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">
                            <x-heroicon-o-clock class="stat-icon" />
                            Pending
                        </span>
                        <span class="stat-mini-value text-warning">
                            {{ $data['pending_expenses_count'] }}
                        </span>
                    </div>
                </div>

                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">
                            <x-heroicon-o-user class="stat-icon" />
                            Pending Amount
                        </span>
                        <span class="stat-mini-value text-warning">
                            Rp {{ number_format($data['pending_expenses'], 0, ',', '.') }}
                        </span>
                    </div>
                </div>

            </div>


            {{-- MAIN CONTENT GRID --}}
            <div class="grid-2">

                {{-- LEFT: Monthly Trend + Top Items --}}
                <div class="overview-col">

                    {{-- TREND BULANAN --}}
                    <div class="card">
                        <div class="card-header">
                            <h3>
                                <x-heroicon-o-calendar class="stat-icon" />
                                Trend Bulanan
                            </h3>
                        </div>
                        <div class="card-body" style="padding:0;">
                            <div class="table-wrapper">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Bulan</th>
                                            <th style="text-align:right;">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($data['monthly_trend'] as $trend)
                                            <tr>
                                                <td><strong>{{ $trend['month'] }}</strong></td>
                                                <td style="text-align:right;font-weight:600;">
                                                    Rp {{ number_format($trend['expenses'], 0, ',', '.') }}
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="2">
                                                    <div class="empty-state">
                                                        <div class="empty-state-text">No trend data available</div>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    {{-- TOP ITEMS --}}
                    <div class="card">
                        <div class="card-header">
                            <h3>
                                <x-heroicon-o-cube class="stat-icon" />
                                Top Expense Items
                            </h3>
                            <span class="badge-count">By Amount</span>
                        </div>
                        <div class="card-body" style="padding:0;">
                            <div class="table-wrapper">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th style="text-align:center;">Qty</th>
                                            <th style="text-align:right;">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($data['top_items'] as $item)
                                            <tr>
                                                <td>{{ Str::limit($item['name'], 25) }}</td>
                                                <td style="text-align:center;">
                                                    <span class="badge badge-gray">{{ $item['qty'] }}</span>
                                                </td>
                                                <td style="text-align:right;">
                                                    Rp {{ number_format($item['total'], 0, ',', '.') }}
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="3">
                                                    <div class="empty-state">
                                                        <div class="empty-state-text">No data</div>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>

                {{-- RIGHT: By User + By Category --}}
                <div class="overview-col">

                    {{-- BY USER --}}
                    <div class="card">
                        <div class="card-header">
                            <h3>
                                <x-heroicon-o-user class="stat-icon" />
                                Expenses by User
                            </h3>
                            <span class="badge-count">Top 10</span>
                        </div>
                        <div class="card-body" style="padding:0;">
                            <div class="table-wrapper">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>User</th>
                                            <th style="text-align:center;">Count</th>
                                            <th style="text-align:right;">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($data['by_user']['expenses'] as $item)
                                            <tr>
                                                <td>{{ $item['user'] }}</td>
                                                <td style="text-align:center;">
                                                    <span class="badge badge-gray">{{ $item['count'] }}</span>
                                                </td>
                                                <td style="text-align:right;">
                                                    Rp {{ number_format($item['total'], 0, ',', '.') }}
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="3">
                                                    <div class="empty-state">
                                                        <div class="empty-state-text">No data</div>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    {{-- BY CATEGORY --}}
                    <div class="card">
                        <div class="card-header">
                            <h3>
                                <x-heroicon-o-tag class="stat-icon" />
                                Expenses by Kategori
                            </h3>
                            <span class="badge-count">Top Kategori</span>
                        </div>
                        <div class="card-body" style="padding:0;">
                            <div class="table-wrapper">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Kategori</th>
                                            <th style="text-align:center;">Count</th>
                                            <th style="text-align:right;">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($data['by_category']['expenses'] as $item)
                                            <tr>
                                                <td>
                                                    <span class="badge badge-info">{{ ucfirst($item['category']) }}</span>
                                                </td>
                                                <td style="text-align:center;">
                                                    <span class="badge badge-gray">{{ $item['count'] }}</span>
                                                </td>
                                                <td style="text-align:right;">
                                                    Rp {{ number_format($item['total'], 0, ',', '.') }}
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="3">
                                                    <div class="empty-state">
                                                        <div class="empty-state-text">No data</div>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>

            </div>


            {{-- RECENT EXPENSES --}}
            <div class="card">
                <div class="card-header">
                    <h3>
                        <x-heroicon-o-credit-card class="stat-icon" />
                        Recent Expenses
                    </h3>
                    <span class="badge-count">{{ $data['expenses']->count() }} items</span>
                </div>
                <div class="card-body" style="padding:0;">
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>User</th>
                                    <th style="text-align:right;">Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($data['expenses'] as $expense)
                                    <tr>
                                        <td>{{ $expense->submission_date?->format('d/m') ?? '-' }}</td>
                                        <td>{{ $expense->user?->name ?? '-' }}</td>
                                        <td style="text-align:right;">
                                            Rp {{ number_format($expense->amount, 0, ',', '.') }}
                                        </td>
                                        <td>
                                            @switch($expense->status)
                                                @case('Pending')
                                                    <span class="badge badge-warning">Pending</span>
                                                    @break
                                                @case('Disetujui')
                                                    <span class="badge badge-info">Approved</span>
                                                    @break
                                                @case('Selesai')
                                                    <span class="badge badge-success">Done</span>
                                                    @break
                                                @case('Ditolak')
                                                    <span class="badge badge-danger">Rejected</span>
                                                    @break
                                                @default
                                                    <span class="badge badge-gray">{{ $expense->status }}</span>
                                            @endswitch
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4">
                                            <div class="empty-state">
                                                <div class="empty-state-text">No expenses found</div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        @endif


        {{-- =====================================================
            ADVANCES
        ====================================================== --}}

        @if($activeTab === 'advances')

            @php
                $data = $this->getAdvancesData();
            @endphp


            <div class="top-stats-grid">

                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-check-circle class="stat-icon" />
                            Total Approved
                        </span>

                        <span class="stat-mini-value text-success">
                            Rp {{ number_format($data['total_all_time'], 0, ',', '.') }}
                        </span>

                    </div>

                </div>


                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-clock class="stat-icon" />
                            Pending
                        </span>

                        <span class="stat-mini-value text-warning">

                            {{ $data['total_pending_count'] }}

                            <small style="color:#9ca3af;">
                                (Rp {{ number_format($data['total_pending'], 0, ',', '.') }})
                            </small>

                        </span>

                    </div>

                </div>


                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-check-circle class="stat-icon" />
                            Disetujui
                        </span>

                        <span class="stat-mini-value text-info">
                            {{ $data['count_by_status']['Disetujui'] }}
                        </span>

                    </div>

                </div>


                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-flag class="stat-icon" />
                            Selesai
                        </span>

                        <span class="stat-mini-value text-success">
                            {{ $data['count_by_status']['Selesai'] }}
                        </span>

                    </div>

                </div>

            </div>


            <div class="grid-2">

                <div class="card">

                    <div class="card-body">

                        <h3 class="card-body-title">
                            <x-heroicon-o-chart-pie class="section-icon" />
                            Status Overview
                        </h3>

                        <div class="mini-stats">

                            <div class="mini-stat">

                                <span class="mini-stat-label">
                                    <x-heroicon-o-clock class="stat-icon" />
                                    Pending
                                </span>

                                <span class="mini-stat-value text-warning">
                                    {{ $data['count_by_status']['Pending'] }}
                                </span>

                            </div>


                            <div class="mini-stat">

                                <span class="mini-stat-label">
                                    <x-heroicon-o-check-circle class="stat-icon" />
                                    Disetujui
                                </span>

                                <span class="mini-stat-value text-info">
                                    {{ $data['count_by_status']['Disetujui'] }}
                                </span>

                            </div>


                            <div class="mini-stat">

                                <span class="mini-stat-label">
                                    <x-heroicon-o-banknotes class="stat-icon" />
                                    Dibayarkan
                                </span>

                                <span class="mini-stat-value">
                                    {{ $data['count_by_status']['Dibayarkan'] }}
                                </span>

                            </div>


                            <div class="mini-stat">

                                <span class="mini-stat-label">
                                    <x-heroicon-o-flag class="stat-icon" />
                                    Selesai
                                </span>

                                <span class="mini-stat-value text-success">
                                    {{ $data['count_by_status']['Selesai'] }}
                                </span>

                            </div>


                            <div class="mini-stat">

                                <span class="mini-stat-label">
                                    <x-heroicon-o-x-circle class="stat-icon" />
                                    Ditolak
                                </span>

                                <span class="mini-stat-value text-danger">
                                    {{ $data['count_by_status']['Ditolak'] }}
                                </span>

                            </div>

                        </div>

                    </div>

                </div>


                <div class="card">

                    <div class="card-header">

                        <h3>
                            <x-heroicon-o-clipboard-document-list class="stat-icon" />
                            All Advances
                        </h3>

                        <span class="badge-count">
                            {{ $data['all']->total() }} total
                        </span>

                    </div>

                    <div class="card-body" style="padding:0;">

                        <div class="table-wrapper">

                            <table class="data-table">

                                <thead>

                                    <tr>

                                        <th>Date</th>
                                        <th>User</th>
                                        <th>Amount</th>
                                        <th>Status</th>

                                    </tr>

                                </thead>

                                <tbody>

                                    @forelse($data['all'] as $advance)

                                        <tr>

                                            <td>
                                                {{ $advance->submission_date?->format('d/m/Y') ?? '-' }}
                                            </td>

                                            <td>
                                                {{ $advance->user?->name ?? '-' }}
                                            </td>

                                            <td>
                                                Rp {{ number_format($advance->amount, 0, ',', '.') }}
                                            </td>

                                            <td>

                                                @switch($advance->status)

                                                    @case('Pending')
                                                        <span class="badge badge-warning">
                                                            Pending
                                                        </span>
                                                        @break

                                                    @case('Disetujui')
                                                        <span class="badge badge-info">
                                                            Approved
                                                        </span>
                                                        @break

                                                    @case('Dibayarkan')
                                                        <span class="badge badge-purple">
                                                            Paid
                                                        </span>
                                                        @break

                                                    @case('Selesai')
                                                        <span class="badge badge-success">
                                                            Done
                                                        </span>
                                                        @break

                                                    @case('Ditolak')
                                                        <span class="badge badge-danger">
                                                            Rejected
                                                        </span>
                                                        @break

                                                    @default
                                                        <span class="badge badge-gray">
                                                            {{ $advance->status }}
                                                        </span>

                                                @endswitch

                                            </td>

                                        </tr>

                                    @empty

                                        <tr>

                                            <td colspan="4">

                                                <div class="empty-state">
                                                    <div class="empty-state-text">
                                                        No advances found
                                                    </div>
                                                </div>

                                            </td>

                                        </tr>

                                    @endforelse

                                </tbody>

                            </table>

                        </div>


                        {{-- PAGINATION --}}

                        @if($data['all']->hasPages())

                            <div style="padding:1rem;">

                                <div class="pagination">

                                    @if($data['all']->onFirstPage())

                                        <span class="pagination-btn disabled">
                                            &laquo;
                                        </span>

                                    @else

                                        <button
                                            type="button"
                                            wire:click="previousPage('advances_page')"
                                            class="pagination-btn"
                                        >
                                            &laquo;
                                        </button>

                                    @endif


                                    @php

                                        $currentPage = $data['all']->currentPage();

                                        $lastPage = $data['all']->lastPage();

                                        $start = max(1, $currentPage - 2);

                                        $end = min($lastPage, $currentPage + 2);

                                        if ($end - $start < 4) {

                                            if ($start == 1) {

                                                $end = min($lastPage, 5);

                                            } else {

                                                $start = max(1, $lastPage - 4);

                                            }

                                        }

                                    @endphp


                                    @for($i = $start; $i <= $end; $i++)

                                        @if($i == $currentPage)

                                            <span class="pagination-btn active">
                                                {{ $i }}
                                            </span>

                                        @else

                                            <button
                                                type="button"
                                                wire:click="gotoPage({{ $i }}, 'advances_page')"
                                                class="pagination-btn"
                                            >
                                                {{ $i }}
                                            </button>

                                        @endif

                                    @endfor


                                    @if($data['all']->hasMorePages())

                                        <button
                                            type="button"
                                            wire:click="nextPage('advances_page')"
                                            class="pagination-btn"
                                        >
                                            &raquo;
                                        </button>

                                    @else

                                        <span class="pagination-btn disabled">
                                            &raquo;
                                        </span>

                                    @endif

                                </div>

                            </div>

                        @endif

                    </div>

                </div>

            </div>

        @endif


        {{-- =====================================================
            TRANSACTIONS
        ====================================================== --}}

        @if($activeTab === 'transactions')

            @php
                $data = $this->getTransactionsData();
            @endphp


            <div class="top-stats-grid three-stats">

                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-arrow-up class="stat-icon" />
                            Total Inflow
                        </span>

                        <span class="stat-mini-value text-success">
                            Rp {{ number_format($data['summary']['total_in'], 0, ',', '.') }}
                        </span>

                    </div>

                </div>


                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-arrow-down class="stat-icon" />
                            Total Outflow
                        </span>

                        <span class="stat-mini-value text-danger">
                            Rp {{ number_format($data['summary']['total_out'], 0, ',', '.') }}
                        </span>

                    </div>

                </div>


                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-scale class="stat-icon" />
                            Net
                        </span>

                        <span class="stat-mini-value {{ $data['summary']['net'] >= 0 ? 'text-success' : 'text-danger' }}">
                            Rp {{ number_format($data['summary']['net'], 0, ',', '.') }}
                        </span>

                    </div>

                </div>

            </div>


            <div class="grid-2">

                {{-- ACCOUNTS --}}

                <div class="card">

                    <div class="card-body">

                        <h3 class="card-body-title">
                            <x-heroicon-o-building-library class="section-icon" />
                            Payment Accounts
                        </h3>

                        <div class="account-list">

                            @forelse($data['accounts'] as $account)

                                <div class="account-row">

                                    <div>

                                        <div style="font-weight:600;color:#1f2937;">
                                            {{ $account->name }}
                                        </div>

                                        @if($account->bank_name)

                                            <small style="color:#6b7280;">
                                                {{ $account->bank_name }}
                                                -
                                                {{ $account->account_number }}
                                            </small>

                                        @endif

                                    </div>

                                    <div class="text-right">

                                        <div class="account-balance">
                                            Rp {{ number_format($account->getAvailableBalance(), 0, ',', '.') }}
                                        </div>

                                    </div>

                                </div>

                            @empty

                                <div class="empty-state">

                                    <div class="empty-state-text">
                                        No active accounts
                                    </div>

                                </div>

                            @endforelse

                        </div>

                    </div>

                </div>


                {{-- TRANSACTIONS --}}

                <div class="card">

                    <div class="card-header">

                        <h3>
                            <x-heroicon-o-document-text class="stat-icon" />
                            Recent Transactions
                        </h3>

                    </div>

                    <div class="card-body" style="padding:0;">

                        <div class="table-wrapper">

                            <table class="data-table">

                                <thead>

                                    <tr>

                                        <th>Date</th>
                                        <th>Account</th>
                                        <th style="text-align:right;">Amount</th>

                                    </tr>

                                </thead>

                                <tbody>

                                    @forelse($data['transactions'] as $transaction)

                                        <tr>

                                            <td>
                                                {{ $transaction->transaction_date?->format('d/m') ?? '-' }}
                                            </td>

                                            <td>
                                                {{ $transaction->paymentAccount?->name ?? '-' }}
                                            </td>

                                            <td
                                                style="text-align:right;"
                                                class="{{ $transaction->type === 'in' ? 'text-success' : 'text-danger' }}"
                                            >

                                                {{ $transaction->type === 'in' ? '+' : '-' }}

                                                Rp {{ number_format($transaction->amount, 0, ',', '.') }}

                                            </td>

                                        </tr>

                                    @empty

                                        <tr>

                                            <td colspan="3">

                                                <div class="empty-state">

                                                    <div class="empty-state-text">
                                                        No transactions found
                                                    </div>

                                                </div>

                                            </td>

                                        </tr>

                                    @endforelse

                                </tbody>

                            </table>

                        </div>

                    </div>

                </div>

            </div>

        @endif


        {{-- =====================================================
            PIPELINE
        ====================================================== --}}

        @if($activeTab === 'pipeline')

            @php
                $data = $this->getPipelineData();
            @endphp


            <div class="top-stats-grid">

                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-flag class="stat-icon" />
                            Total Pipeline
                        </span>

                        <span class="stat-mini-value text-primary">
                            Rp {{ number_format($data['total_pipeline'], 0, ',', '.') }}
                        </span>

                    </div>

                </div>


                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-chat-bubble-left-right class="stat-icon" />
                            Negosiasi
                        </span>

                        <span class="stat-mini-value text-warning">

                            {{ $data['stages']['Negosiasi']['count'] }}

                            <small style="color:#9ca3af;">
                                ({{ number_format($data['stages']['Negosiasi']['value'], 0, ',', '.') }})
                            </small>

                        </span>

                    </div>

                </div>


                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-clock class="stat-icon" />
                            Menunggu Bayar
                        </span>

                        <span class="stat-mini-value text-info">

                            {{ $data['stages']['Menunggu Pembayaran']['count'] }}

                            <small style="color:#9ca3af;">
                                ({{ number_format($data['stages']['Menunggu Pembayaran']['value'], 0, ',', '.') }})
                            </small>

                        </span>

                    </div>

                </div>


                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-folder class="stat-icon" />
                            In Project
                        </span>

                        <span class="stat-mini-value text-success">

                            {{ $data['stages']['Dalam Tahap Project']['count'] }}

                            <small style="color:#9ca3af;">
                                ({{ number_format($data['stages']['Dalam Tahap Project']['value'], 0, ',', '.') }})
                            </small>

                        </span>

                    </div>

                </div>

            </div>


            <div class="grid-2">

                <div class="card">

                    <div class="card-body">

                        <h3 class="card-body-title">
                            <x-heroicon-o-users class="section-icon" />
                            Client Metrics
                        </h3>

                        <div class="mini-stats">

                            <div class="mini-stat">

                                <span class="mini-stat-label">
                                    Total Clients
                                </span>

                                <span class="mini-stat-value">
                                    {{ $data['clients'] }}
                                </span>

                            </div>


                            <div class="mini-stat">

                                <span class="mini-stat-label">
                                    Total Revenue
                                </span>

                                <span class="mini-stat-value text-success">
                                    Rp {{ number_format($data['revenue'], 0, ',', '.') }}
                                </span>

                            </div>


                            <div class="mini-stat">

                                <span class="mini-stat-label">
                                    Avg Client Value
                                </span>

                                <span class="mini-stat-value">
                                    Rp {{ number_format($data['avg_client'], 0, ',', '.') }}
                                </span>

                            </div>


                            <div class="mini-stat">

                                <span class="mini-stat-label">
                                    Quotations
                                </span>

                                <span class="mini-stat-value">

                                    {{ $data['pending_quotations'] + $data['approved_quotations'] }}

                                    <small style="color:#9ca3af;">
                                        ({{ $data['pending_quotations'] }} pending)
                                    </small>

                                </span>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        @endif


        {{-- =====================================================
            ASSETS
        ====================================================== --}}

        @if($activeTab === 'assets')

            @php
                $data = $this->getAssetsData();
            @endphp


            <div class="top-stats-grid">

                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-building-office class="stat-icon" />
                            Total Assets
                        </span>

                        <span class="stat-mini-value text-primary">
                            {{ $data['total'] }}
                        </span>

                    </div>

                </div>


                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-banknotes class="stat-icon" />
                            Total Value
                        </span>

                        <span class="stat-mini-value text-success">
                            Rp {{ number_format($data['total_value'], 0, ',', '.') }}
                        </span>

                    </div>

                </div>


                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-check-circle class="stat-icon" />
                            Baik
                        </span>

                        <span class="stat-mini-value text-success">
                            {{ $data['by_condition']['Baik'] }}
                        </span>

                    </div>

                </div>


                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-exclamation-triangle class="stat-icon" />
                            Rusak
                        </span>

                        <span class="stat-mini-value text-danger">

                            {{ $data['by_condition']['Rusak Ringan'] + $data['by_condition']['Rusak Berat'] }}

                        </span>

                    </div>

                </div>

            </div>


            <div class="grid-2">

                {{-- CONDITION --}}

                <div class="card">

                    <div class="card-body">

                        <h3 class="card-body-title">
                            <x-heroicon-o-chart-pie class="section-icon" />
                            Condition Overview
                        </h3>

                        <div class="mini-stats">

                            <div class="mini-stat">

                                <span class="mini-stat-label">
                                    <x-heroicon-o-check-circle class="stat-icon" />
                                    Baik
                                </span>

                                <span class="mini-stat-value text-success">
                                    {{ $data['by_condition']['Baik'] }}
                                </span>

                            </div>


                            <div class="mini-stat">

                                <span class="mini-stat-label">
                                    <x-heroicon-o-exclamation-triangle class="stat-icon" />
                                    Rusak Ringan
                                </span>

                                <span class="mini-stat-value text-warning">
                                    {{ $data['by_condition']['Rusak Ringan'] }}
                                </span>

                            </div>


                            <div class="mini-stat">

                                <span class="mini-stat-label">
                                    <x-heroicon-o-x-circle class="stat-icon" />
                                    Rusak Berat
                                </span>

                                <span class="mini-stat-value text-danger">
                                    {{ $data['by_condition']['Rusak Berat'] }}
                                </span>

                            </div>

                        </div>

                    </div>

                </div>


                {{-- ASSETS TABLE --}}

                <div class="card">

                    <div class="card-header">

                        <h3>
                            <x-heroicon-o-clipboard-document-list class="stat-icon" />
                            All Assets
                        </h3>

                        <span class="badge-count">
                            {{ $data['all']->total() }} total
                        </span>

                    </div>

                    <div class="card-body" style="padding:0;">

                        <div class="table-wrapper">

                            <table class="data-table">

                                <thead>

                                    <tr>

                                        <th>Name</th>
                                        <th>Purchase Date</th>
                                        <th>Condition</th>
                                        <th style="text-align:right;">Value</th>
                                        <th>Responsible</th>

                                    </tr>

                                </thead>

                                <tbody>

                                    @forelse($data['all'] as $asset)

                                        <tr>

                                            <td>
                                                <strong>{{ $asset->nama_barang }}</strong>
                                            </td>

                                            <td>
                                                {{ $asset->tanggal_pembelian?->format('d/m/Y') ?? '-' }}
                                            </td>

                                            <td>

                                                @switch($asset->kondisi)

                                                    @case('Baik')
                                                        <span class="badge badge-success">
                                                            Baik
                                                        </span>
                                                        @break

                                                    @case('Rusak Ringan')
                                                        <span class="badge badge-warning">
                                                            Rusak Ringan
                                                        </span>
                                                        @break

                                                    @case('Rusak Berat')
                                                        <span class="badge badge-danger">
                                                            Rusak Berat
                                                        </span>
                                                        @break

                                                    @default
                                                        <span class="badge badge-gray">
                                                            {{ $asset->kondisi }}
                                                        </span>

                                                @endswitch

                                            </td>

                                            <td style="text-align:right;">
                                                Rp {{ number_format($asset->harga_barang, 0, ',', '.') }}
                                            </td>

                                            <td>
                                                {{ $asset->user?->name ?? '-' }}
                                            </td>

                                        </tr>

                                    @empty

                                        <tr>

                                            <td colspan="5">

                                                <div class="empty-state">

                                                    <div class="empty-state-text">
                                                        No assets found
                                                    </div>

                                                </div>

                                            </td>

                                        </tr>

                                    @endforelse

                                </tbody>

                            </table>

                        </div>


                        @if($data['all']->hasPages())

                            <div style="padding:1rem;">

                                <div class="pagination">

                                    @if($data['all']->onFirstPage())

                                        <span class="pagination-btn disabled">
                                            &laquo;
                                        </span>

                                    @else

                                        <button
                                            type="button"
                                            wire:click="previousPage('assets_page')"
                                            class="pagination-btn"
                                        >
                                            &laquo;
                                        </button>

                                    @endif


                                    @php

                                        $currentPage = $data['all']->currentPage();

                                        $lastPage = $data['all']->lastPage();

                                        $start = max(1, $currentPage - 2);

                                        $end = min($lastPage, $currentPage + 2);

                                    @endphp


                                    @for($i = $start; $i <= $end; $i++)

                                        @if($i == $currentPage)

                                            <span class="pagination-btn active">
                                                {{ $i }}
                                            </span>

                                        @else

                                            <button
                                                type="button"
                                                wire:click="gotoPage({{ $i }}, 'assets_page')"
                                                class="pagination-btn"
                                            >
                                                {{ $i }}
                                            </button>

                                        @endif

                                    @endfor


                                    @if($data['all']->hasMorePages())

                                        <button
                                            type="button"
                                            wire:click="nextPage('assets_page')"
                                            class="pagination-btn"
                                        >
                                            &raquo;
                                        </button>

                                    @else

                                        <span class="pagination-btn disabled">
                                            &raquo;
                                        </span>

                                    @endif

                                </div>

                            </div>

                        @endif

                    </div>

                </div>

            </div>

        @endif


        {{-- =====================================================
            PROJECTS
        ====================================================== --}}

        @if($activeTab === 'projects')

            @php
                $data = $this->getProjectsData();
            @endphp


            <div class="top-stats-grid three-stats projects-stats">

                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-folder class="stat-icon" />
                            Total Projects
                        </span>

                        <span class="stat-mini-value text-primary">
                            {{ $data['total'] }}
                        </span>

                    </div>

                </div>


                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-clock class="stat-icon" />
                            Active
                        </span>

                        <span class="stat-mini-value text-warning">
                            {{ $data['active'] }}
                        </span>

                    </div>

                </div>


                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-check-circle class="stat-icon" />
                            Completed
                        </span>

                        <span class="stat-mini-value text-success">
                            {{ $data['completed'] }}
                        </span>

                    </div>

                </div>

            </div>


            <div class="grid-2">

                {{-- STATUS --}}

                <div class="card">

                    <div class="card-body">

                        <h3 class="card-body-title">
                            <x-heroicon-o-chart-pie class="section-icon" />
                            Status Overview
                        </h3>

                        <div class="mini-stats">

                            <div class="mini-stat">

                                <span class="mini-stat-label">
                                    <x-heroicon-o-pause-circle class="stat-icon" />
                                    Not Started
                                </span>

                                <span class="mini-stat-value">
                                    {{ $data['by_status']['Not Started'] }}
                                </span>

                            </div>


                            <div class="mini-stat">

                                <span class="mini-stat-label">
                                    <x-heroicon-o-arrow-path class="stat-icon" />
                                    On Progress
                                </span>

                                <span class="mini-stat-value text-warning">
                                    {{ $data['by_status']['On Progress'] }}
                                </span>

                            </div>


                            <div class="mini-stat">

                                <span class="mini-stat-label">
                                    <x-heroicon-o-check-circle class="stat-icon" />
                                    Completed
                                </span>

                                <span class="mini-stat-value text-success">
                                    {{ $data['by_status']['Completed'] }}
                                </span>

                            </div>


                            <div class="mini-stat">

                                <span class="mini-stat-label">
                                    <x-heroicon-o-x-circle class="stat-icon" />
                                    Cancelled
                                </span>

                                <span class="mini-stat-value text-danger">
                                    {{ $data['by_status']['Cancelled'] }}
                                </span>

                            </div>

                        </div>

                    </div>

                </div>


                {{-- PROJECT TABLE --}}

                <div class="card">

                    <div class="card-header">

                        <h3>
                            <x-heroicon-o-clipboard-document-list class="stat-icon" />
                            All Projects
                        </h3>

                        <span class="badge-count">
                            {{ $data['all']->total() }} total
                        </span>

                    </div>

                    <div class="card-body" style="padding:0;">

                        <div class="table-wrapper">

                            <table class="data-table">

                                <thead>

                                    <tr>

                                        <th>Project Name</th>
                                        <th>Client</th>
                                        <th>Start</th>
                                        <th>End</th>
                                        <th>Status</th>

                                    </tr>

                                </thead>

                                <tbody>

                                    @forelse($data['all'] as $project)

                                        <tr>

                                            <td>
                                                <strong>
                                                    {{ Str::limit($project->nama_project, 25) }}
                                                </strong>
                                            </td>

                                            <td>
                                                {{ Str::limit($project->Deal?->nama_perusahaan ?? '-', 15) }}
                                            </td>

                                            <td>
                                                {{ $project->timeline_mulai?->format('d/m/Y') ?? '-' }}
                                            </td>

                                            <td>
                                                {{ $project->timeline_selesai?->format('d/m/Y') ?? '-' }}
                                            </td>

                                            <td>

                                                @switch($project->status)

                                                    @case('Not Started')
                                                        <span class="badge badge-gray">
                                                            Not Started
                                                        </span>
                                                        @break

                                                    @case('On Progress')
                                                        <span class="badge badge-warning">
                                                            On Progress
                                                        </span>
                                                        @break

                                                    @case('Completed')
                                                        <span class="badge badge-success">
                                                            Completed
                                                        </span>
                                                        @break

                                                    @case('Cancelled')
                                                        <span class="badge badge-danger">
                                                            Cancelled
                                                        </span>
                                                        @break

                                                    @default
                                                        <span class="badge badge-info">
                                                            {{ $project->status }}
                                                        </span>

                                                @endswitch

                                            </td>

                                        </tr>

                                    @empty

                                        <tr>

                                            <td colspan="5">

                                                <div class="empty-state">

                                                    <div class="empty-state-text">
                                                        No projects found
                                                    </div>

                                                </div>

                                            </td>

                                        </tr>

                                    @endforelse

                                </tbody>

                            </table>

                        </div>


                        @if($data['all']->hasPages())

                            <div style="padding:1rem;">

                                <div class="pagination">

                                    @if($data['all']->onFirstPage())

                                        <span class="pagination-btn disabled">
                                            &laquo;
                                        </span>

                                    @else

                                        <button
                                            type="button"
                                            wire:click="previousPage('projects_page')"
                                            class="pagination-btn"
                                        >
                                            &laquo;
                                        </button>

                                    @endif


                                    @php

                                        $currentPage = $data['all']->currentPage();

                                        $lastPage = $data['all']->lastPage();

                                        $start = max(1, $currentPage - 2);

                                        $end = min($lastPage, $currentPage + 2);

                                    @endphp


                                    @for($i = $start; $i <= $end; $i++)

                                        @if($i == $currentPage)

                                            <span class="pagination-btn active">
                                                {{ $i }}
                                            </span>

                                        @else

                                            <button
                                                type="button"
                                                wire:click="gotoPage({{ $i }}, 'projects_page')"
                                                class="pagination-btn"
                                            >
                                                {{ $i }}
                                            </button>

                                        @endif

                                    @endfor


                                    @if($data['all']->hasMorePages())

                                        <button
                                            type="button"
                                            wire:click="nextPage('projects_page')"
                                            class="pagination-btn"
                                        >
                                            &raquo;
                                        </button>

                                    @else

                                        <span class="pagination-btn disabled">
                                            &raquo;
                                        </span>

                                    @endif

                                </div>

                            </div>

                        @endif

                    </div>

                </div>

            </div>

        @endif


        {{-- =====================================================
            ADVANCE ANALYSIS
        ====================================================== --}}

        @if($activeTab === 'advance_analysis')

            @php
                $data = $this->getAdvanceAnalysisData();
            @endphp


            <div class="top-stats-grid">

                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-banknotes class="stat-icon" />
                            Total Paid
                        </span>

                        <span class="stat-mini-value text-primary">
                            Rp {{ number_format($data['summary']['total_paid'], 0, ',', '.') }}
                        </span>

                    </div>

                </div>


                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-clipboard-document-list class="stat-icon" />
                            Total Realisasi
                        </span>

                        <span class="stat-mini-value text-success">
                            Rp {{ number_format($data['summary']['total_realisasi'], 0, ',', '.') }}
                        </span>

                    </div>

                </div>


                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-folder class="stat-icon" />
                            Project Count
                        </span>

                        <span class="stat-mini-value">
                            {{ $data['summary']['project_count'] }}
                        </span>

                    </div>

                </div>


                <div class="card stat-mini">

                    <div class="stat-mini-content">

                        <span class="stat-mini-label">
                            <x-heroicon-o-clock class="stat-icon" />
                            Pending Approval
                        </span>

                        <span class="stat-mini-value text-warning">

                            {{ $data['summary']['pending_count'] }}

                            <small style="color:#9ca3af;">
                                ({{ number_format($data['summary']['pending_amount'], 0, ',', '.') }})
                            </small>

                        </span>

                    </div>

                </div>

            </div>


            <div class="grid-2">

                {{-- PROJECT --}}

                <div class="card">

                    <div class="card-header">

                        <h3>
                            <x-heroicon-o-trophy class="stat-icon" />
                            Top Project by Advance
                        </h3>

                    </div>

                    <div class="card-body" style="padding:0;">

                        <div class="table-wrapper">

                            <table class="data-table">

                                <thead>

                                    <tr>

                                        <th>Project</th>
                                        <th>Client</th>
                                        <th style="text-align:right;">Paid</th>
                                        <th style="text-align:right;">Realisasi</th>
                                        <th style="text-align:right;">Sisa</th>

                                    </tr>

                                </thead>

                                <tbody>

                                    @forelse($data['by_project'] as $project)

                                        <tr>

                                            <td>
                                                <strong>
                                                    {{ Str::limit($project['project_name'], 20) }}
                                                </strong>
                                            </td>

                                            <td>
                                                {{ Str::limit($project['client_name'], 12) }}
                                            </td>

                                            <td style="text-align:right;">
                                                Rp {{ number_format($project['total_paid'], 0, ',', '.') }}
                                            </td>

                                            <td style="text-align:right;">
                                                Rp {{ number_format($project['total_realisasi'], 0, ',', '.') }}
                                            </td>

                                            <td style="text-align:right;">

                                                @if($project['sisa'] < 0)

                                                    <span class="text-danger">
                                                        Rp {{ number_format(abs($project['sisa']), 0, ',', '.') }}
                                                    </span>

                                                @else

                                                    <span class="text-success">
                                                        Rp {{ number_format($project['sisa'], 0, ',', '.') }}
                                                    </span>

                                                @endif

                                            </td>

                                        </tr>

                                    @empty

                                        <tr>

                                            <td colspan="5">

                                                <div class="empty-state">

                                                    <div class="empty-state-text">
                                                        No project data
                                                    </div>

                                                </div>

                                            </td>

                                        </tr>

                                    @endforelse

                                </tbody>

                            </table>

                        </div>

                    </div>

                </div>


                {{-- ITEMS --}}

                <div class="card">

                    <div class="card-header">

                        <h3>
                            <x-heroicon-o-cube class="stat-icon" />
                            Top Item Realisasi
                        </h3>

                    </div>

                    <div class="card-body" style="padding:0;">

                        <div class="table-wrapper">

                            <table class="data-table">

                                <thead>

                                    <tr>

                                        <th>Item Name</th>
                                        <th style="text-align:center;">Qty</th>
                                        <th style="text-align:right;">Total</th>
                                        <th style="text-align:right;">Avg</th>

                                    </tr>

                                </thead>

                                <tbody>

                                    @forelse($data['top_items'] as $item)

                                        <tr>

                                            <td>
                                                <strong>
                                                    {{ Str::limit($item['name'], 20) }}
                                                </strong>
                                            </td>

                                            <td style="text-align:center;">

                                                <span class="badge badge-gray">
                                                    {{ $item['total_qty'] }}
                                                </span>

                                            </td>

                                            <td style="text-align:right;">
                                                Rp {{ number_format($item['total_amount'], 0, ',', '.') }}
                                            </td>

                                            <td style="text-align:right;">
                                                Rp {{ number_format($item['avg_price'], 0, ',', '.') }}
                                            </td>

                                        </tr>

                                    @empty

                                        <tr>

                                            <td colspan="4">

                                                <div class="empty-state">

                                                    <div class="empty-state-text">
                                                        No items found
                                                    </div>

                                                </div>

                                            </td>

                                        </tr>

                                    @endforelse

                                </tbody>

                            </table>

                        </div>

                    </div>

                </div>

            </div>

        @endif

    </div>

</x-filament-panels::page>