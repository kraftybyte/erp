<x-filament-panels::page>
    @php
        // =========================================================
        // DATA
        // =========================================================

        $totalBalance = $accounts->sum('current_balance');

        try {
            $settings = app(\App\Settings\GeneralSettings::class);
        } catch (\Exception $e) {
            $settings = null;
        }

        // Helper
        $stripHash = fn ($v) => ltrim($v ?? '', '#');

        // =========================================================
        // THEME
        // =========================================================

        $themeArray = is_array($settings?->site_theme)
            ? $settings->site_theme
            : [];

        $themeDefaults = [
            'primary' => '90c646',
            'primary_dark' => '6fa335',
            'success' => '10b981',
            'warning' => 'f59e0b',
            'danger' => 'ef4444',
            'info' => '3b82f6',
        ];

        $theme = array_merge($themeDefaults, $themeArray);
        $theme = array_map($stripHash, $theme);

        $primaryColor = '#' . (
            $stripHash($settings?->primary_gradient_from)
            ?: $theme['primary']
        );

        $secondaryColor = '#' . (
            $stripHash($settings?->primary_gradient_to)
            ?: $theme['primary_dark']
        );

        $successColor = '#' . $theme['success'];
        $warningColor = '#' . $theme['warning'];
        $dangerColor = '#' . $theme['danger'];
        $infoColor = '#' . $theme['info'];

        // =========================================================
        // RGBA HELPER
        // =========================================================

        $rgba = function ($hex, $opacity) {
            $hex = ltrim($hex ?? '#000000', '#');

            if (strlen($hex) === 3) {
                $hex =
                    $hex[0] . $hex[0] .
                    $hex[1] . $hex[1] .
                    $hex[2] . $hex[2];
            }

            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));

            return "rgba({$r}, {$g}, {$b}, {$opacity})";
        };

        // =========================================================
        // PENDING
        // =========================================================

        $pendingExpense = \App\Models\Expense::whereIn(
            'status',
            ['Pending', 'Disetujui']
        )->count();

        $pendingAdvance = \App\Models\Advance::whereIn(
            'status',
            ['Pending', 'Disetujui']
        )->count();
    @endphp


    <style>
        /* =========================================================
           KASIRA FINANCE DASHBOARD
        ========================================================= */

        .fi-section-inner-ctn {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
        }

        /* =========================================================
           HEADER
        ========================================================= */

        .card-header {
            min-height: 68px;
            padding: 14px 20px;
            border-bottom: 1px solid #eef0f3;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .card-header-title {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
            font-size: 14px;
            font-weight: 700;
            color: #111827;
        }

        .card-header-icon {
            width: 34px;
            height: 34px;
            min-width: 34px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* =========================================================
           STAT CARDS
        ========================================================= */

        .stat-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 20px;
            min-height: 124px;
            transition:
                transform .2s ease,
                box-shadow .2s ease,
                border-color .2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            border-color: #dbe1e8;
            box-shadow: 0 10px 28px rgba(15, 23, 42, 0.07);
        }

        .stat-card-primary {
            background: var(--color-primary);
            border: 0;
            border-radius: 14px;
            padding: 20px;
            min-height: 124px;
            transition:
                transform .2s ease,
                box-shadow .2s ease;
        }

        .stat-card-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 28px rgba(15, 23, 42, 0.14);
        }

        /* =========================================================
           TABLE
        ========================================================= */

        .data-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .data-table thead tr {
            background: #f8fafc;
        }

        .data-table thead th {
            padding: 14px 18px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .55px;
            color: #64748b;
            border-bottom: 1px solid #e5e7eb;
        }

        .data-table tbody tr {
            border-bottom: 1px solid #f1f5f9;
            transition: background .15s ease;
        }

        .data-table tbody tr:last-child {
            border-bottom: 0;
        }

        .data-table tbody tr:hover {
            background: #fafafa;
        }

        .data-table tbody td {
            padding: 16px 20px;
            font-size: 12px;
            color: #334155;
            vertical-align: middle;
        }

        /* =========================================================
           BADGE
        ========================================================= */

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 600;
            padding: 4px 9px;
            line-height: 1.2;
            letter-spacing: .15px;
        }

        /* =========================================================
           QUICK ACTION
        ========================================================= */

        .quick-action {
            display: flex;
            align-items: center;
            gap: 14px;
            min-height: 90px;
            padding: 20px 24px;
            border-radius: 14px;
            text-decoration: none;
            color: white;
            transition:
                transform .2s ease,
                opacity .2s ease,
                box-shadow .2s ease;
        }

        .quick-action:hover {
            transform: translateY(-2px);
            opacity: .96;
            box-shadow: 0 8px 20px rgba(15, 23, 42, .10);
        }

        .quick-action-icon {
            width: 42px;
            height: 42px;
            min-width: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,.18);
        }

        /* =========================================================
           FILTER AREA
        ========================================================= */

        .transaction-filter {
            padding: 16px 20px;
            background: #fafafa;
            border-bottom: 1px solid #eef0f3;
        }

        .transaction-filter-inner {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .filter-label {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            height: 36px;
            padding: 0 4px;
            color: #64748b;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }

        .filter-control {
            position: relative;
            flex: 0 0 auto;
        }

        .filter-select {
            height: 36px !important;
            min-height: 36px !important;
            width: 100%;
            padding: 0 34px 0 12px !important;
            border: 1px solid #dfe3e8 !important;
            border-radius: 8px !important;
            background-color: #ffffff !important;
            color: #374151 !important;
            font-size: 11px !important;
            font-weight: 500 !important;
            line-height: 36px !important;
            outline: none !important;
            appearance: none !important;
            -webkit-appearance: none !important;
            -moz-appearance: none !important;
            cursor: pointer;
            box-shadow: none !important;
            transition:
                border-color .15s ease,
                box-shadow .15s ease,
                background-color .15s ease;
        }

        .filter-select:hover {
            border-color: #cbd5e1 !important;
            background-color: #ffffff !important;
        }

        .filter-select:focus {
            border-color: var(--color-primary) !important;
            box-shadow: 0 0 0 3px var(--color-primary-rgba) !important;
        }

        /* Custom arrow */
        .filter-arrow {
            position: absolute;
            top: 50%;
            right: 11px;
            width: 13px;
            height: 13px;
            transform: translateY(-50%);
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
            color: #94a3b8;
        }

        .filter-arrow svg {
            width: 13px;
            height: 13px;
            stroke-width: 2;
        }

        .filter-control-account {
            width: 155px;
        }

        .filter-control-type {
            width: 125px;
        }

        .filter-control-category {
            width: 145px;
        }

        .filter-spacer {
            flex: 1 1 auto;
            min-width: 8px;
        }

        .filter-button {
            height: 36px;
            min-height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 0 12px;
            border-radius: 8px;
            border: 1px solid transparent;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
            cursor: pointer;
            transition:
                background-color .15s ease,
                border-color .15s ease,
                box-shadow .15s ease,
                transform .15s ease;
        }

        .filter-button:active {
            transform: translateY(1px);
        }

        .filter-button-clear {
            background: #fff1f2;
            border-color: #ffe4e6;
            color: #e11d48;
        }

        .filter-button-clear:hover {
            background: #ffe4e6;
            border-color: #fecdd3;
        }

        .filter-button-refresh {
            background: var(--color-primary);
            color: #ffffff;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .05);
        }

        .filter-button-refresh:hover {
            opacity: .92;
            box-shadow: 0 4px 10px var(--color-primary-rgba);
        }

        /* =========================================================
           TABLE SCROLL
        ========================================================= */

        .table-scroll-container {
            height: 400px;
            overflow-y: auto;
            overflow-x: auto;
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 transparent;
        }

        .table-scroll-container::-webkit-scrollbar {
            width: 7px;
            height: 7px;
        }

        .table-scroll-container::-webkit-scrollbar-track {
            background: transparent;
        }

        .table-scroll-container::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 99px;
        }

        .table-scroll-container::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* =========================================================
           SCROLLBAR
        ========================================================= */

        .scroll-thin {
            scrollbar-width: thin;
            scrollbar-color: #d1d5db transparent;
        }

        .scroll-thin::-webkit-scrollbar {
            width: 5px;
            height: 5px;
        }

        .scroll-thin::-webkit-scrollbar-track {
            background: transparent;
        }

        .scroll-thin::-webkit-scrollbar-thumb {
            background: #d1d5db;
            border-radius: 99px;
        }

        /* =========================================================
           COLOR UTILITIES
        ========================================================= */

        .bg-primary {
            background-color: var(--color-primary);
        }

        .bg-success {
            background-color: var(--color-success);
        }

        .bg-warning {
            background-color: var(--color-warning);
        }

        .bg-danger {
            background-color: var(--color-danger);
        }

        .bg-info {
            background-color: var(--color-info);
        }

        .text-primary {
            color: var(--color-primary);
        }

        .text-success {
            color: var(--color-success);
        }

        .text-warning {
            color: var(--color-warning);
        }

        .text-danger {
            color: var(--color-danger);
        }

        .text-info {
            color: var(--color-info);
        }

        .border-primary {
            border-color: var(--color-primary);
        }

        .bg-primary-light {
            background-color: var(--color-primary-rgba);
        }

        .bg-success-light {
            background-color: var(--color-success-rgba);
        }

        .bg-warning-light {
            background-color: var(--color-warning-rgba);
        }

        .bg-danger-light {
            background-color: var(--color-danger-rgba);
        }

        .bg-info-light {
            background-color: var(--color-info-rgba);
        }

        /* =========================================================
           RESPONSIVE
        ========================================================= */

        @media (max-width: 900px) {
            .transaction-filter-inner {
                align-items: stretch;
            }

            .filter-label {
                width: 100%;
                height: 30px;
            }

            .filter-control-account,
            .filter-control-type,
            .filter-control-category {
                flex: 1 1 calc(33.333% - 8px);
                width: auto;
                min-width: 130px;
            }

            .filter-spacer {
                display: none;
            }

            .filter-button {
                flex: 0 0 auto;
            }
        }

        @media (max-width: 640px) {
            .card-header {
                padding: 13px 15px;
            }

            .transaction-filter {
                padding: 12px;
            }

            .filter-control-account,
            .filter-control-type,
            .filter-control-category {
                flex: 1 1 100%;
                width: 100%;
            }

            .filter-button-clear,
            .filter-button-refresh {
                flex: 1 1 calc(50% - 4px);
            }
        }


        /* =========================================================
           CSS VARIABLES
        ========================================================= */

        :root {
            --color-primary: {{ $primaryColor }};
            --color-secondary: {{ $secondaryColor }};

            --color-success: {{ $successColor }};
            --color-warning: {{ $warningColor }};
            --color-danger: {{ $dangerColor }};
            --color-info: {{ $infoColor }};

            --color-primary-rgba: {{ $rgba($primaryColor, 0.1) }};
            --color-success-rgba: {{ $rgba($successColor, 0.1) }};
            --color-warning-rgba: {{ $rgba($warningColor, 0.1) }};
            --color-danger-rgba: {{ $rgba($dangerColor, 0.1) }};
            --color-info-rgba: {{ $rgba($infoColor, 0.1) }};
        }
    </style>


    {{-- =========================================================
         STAT HEADER
    ========================================================== --}}

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-8 mb-10">

        {{-- TOTAL SALDO --}}
        <div class="stat-card-primary">
            <div class="flex items-start justify-between">
                <div class="flex-1 min-w-0">
                    <p class="text-white text-[10px] font-semibold uppercase tracking-widest mb-2">
                        Total Saldo
                    </p>

                    <p class="text-white text-lg font-extrabold leading-tight truncate">
                        Rp {{ number_format($totalBalance, 0, ',', '.') }}
                    </p>

                    <p class="text-white text-[11px] mt-2">
                        {{ $accounts->count() }} rekening aktif
                    </p>
                </div>

                <div class="w-10 h-10 rounded-xl bg-white/20 flex items-center justify-center shrink-0 ml-3">
                    <svg class="w-5 h-5 text-white"
                         fill="none"
                         viewBox="0 0 24 24"
                         stroke-width="1.8"
                         stroke="currentColor">
                        <path stroke-linecap="round"
                              stroke-linejoin="round"
                              d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z"/>
                    </svg>
                </div>
            </div>
        </div>


        {{-- PEMASUKAN --}}
        <div class="stat-card">
            <div class="flex items-start justify-between">
                <div class="flex-1 min-w-0">
                    <p class="text-gray-400 text-[10px] font-semibold uppercase tracking-widest mb-2">
                        Pemasukan
                    </p>

                    <p class="text-gray-900 text-lg font-extrabold leading-tight truncate">
                        Rp {{ number_format($totalInThisMonth, 0, ',', '.') }}
                    </p>

                    <p class="text-gray-400 text-[11px] mt-2">
                        Bulan ini
                    </p>
                </div>

                <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 ml-3 bg-success-light">
                    <svg class="w-5 h-5 text-success"
                         fill="none"
                         viewBox="0 0 24 24"
                         stroke-width="1.8"
                         stroke="currentColor">
                        <path stroke-linecap="round"
                              stroke-linejoin="round"
                              d="M12 4.5v15m7.5-7.5h-15"/>
                    </svg>
                </div>
            </div>
        </div>


        {{-- PENGELUARAN --}}
        <div class="stat-card">
            <div class="flex items-start justify-between">
                <div class="flex-1 min-w-0">
                    <p class="text-gray-400 text-[10px] font-semibold uppercase tracking-widest mb-2">
                        Pengeluaran
                    </p>

                    <p class="text-gray-900 text-lg font-extrabold leading-tight truncate">
                        Rp {{ number_format($totalOutThisMonth, 0, ',', '.') }}
                    </p>

                    <p class="text-gray-400 text-[11px] mt-2">
                        Bulan ini
                    </p>
                </div>

                <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 ml-3 bg-danger-light">
                    <svg class="w-5 h-5 text-danger"
                         fill="none"
                         viewBox="0 0 24 24"
                         stroke-width="1.8"
                         stroke="currentColor">
                        <path stroke-linecap="round"
                              stroke-linejoin="round"
                              d="M5 12h14"/>
                    </svg>
                </div>
            </div>
        </div>


        {{-- MENUNGGU --}}
        <div class="stat-card">
            <div class="flex items-start justify-between">
                <div class="flex-1 min-w-0">
                    <p class="text-gray-400 text-[10px] font-semibold uppercase tracking-widest mb-2">
                        Menunggu
                    </p>

                    <p class="text-gray-900 text-lg font-extrabold leading-tight">
                        {{ $pendingPayments }}
                    </p>

                    <p class="text-gray-400 text-[11px] mt-2">
                        Expense & Advance
                    </p>
                </div>

                <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 ml-3 bg-warning-light">
                    <svg class="w-5 h-5 text-warning"
                         fill="none"
                         viewBox="0 0 24 24"
                         stroke-width="1.8"
                         stroke="currentColor">
                        <path stroke-linecap="round"
                              stroke-linejoin="round"
                              d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                    </svg>
                </div>
            </div>
        </div>

    </div>


    {{-- =========================================================
         MAIN GRID
    ========================================================== --}}

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-10 mb-12">

        {{-- =====================================================
             REKENING
        ====================================================== --}}

<div class="fi-section-inner-ctn flex flex-col">

            <div class="card-header shrink-0">

                <div class="card-header-title">

                    <div class="card-header-icon bg-primary">
                        <svg class="w-4 h-4 text-white"
                             fill="none"
                             viewBox="0 0 24 24"
                             stroke-width="1.8"
                             stroke="currentColor">
                            <path stroke-linecap="round"
                                  stroke-linejoin="round"
                                  d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z"/>
                        </svg>
                    </div>

                    <span>Daftar Rekening</span>

                </div>

                <a href="{{ url('/admin/payment-accounts') }}"
                   class="text-[11px] font-semibold hover:opacity-75 transition-opacity text-primary whitespace-nowrap">
                    Kelola →
                </a>

            </div>


            <div class="p-4 space-y-3 flex-1 overflow-y-auto scroll-thin max-h-[350px] overflow-y-auto scroll-thin px-4">

                @forelse($accounts as $account)

                    <div class="group p-4 rounded-xl bg-gray-50 border border-gray-100 hover:bg-white hover:border-gray-200 transition-all">

                        <div class="flex items-center gap-3">

                            <div class="w-10 h-10 rounded-lg bg-white border border-gray-200 flex items-center justify-center shrink-0">

                                <svg class="w-4 h-4 text-gray-400"
                                     fill="none"
                                     viewBox="0 0 24 24"
                                     stroke-width="1.8"
                                     stroke="currentColor">
                                    <path stroke-linecap="round"
                                          stroke-linejoin="round"
                                          d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125 1.125V21M3 3h12m-.75 4.5H21"/>
                                </svg>

                            </div>

                            <div class="flex-1 min-w-0">

                                <p class="text-sm font-semibold text-gray-800 truncate">
                                    {{ $account->name }}
                                </p>

                                @if($account->bank_name)
                                    <p class="text-[11px] text-gray-400 truncate">
                                        {{ $account->bank_name }}
                                    </p>
                                @endif

                            </div>

                        </div>


                        <div class="flex items-center justify-between mt-3 pt-3 border-t border-gray-100">

                            <span class="text-[10px] text-gray-400 font-medium uppercase tracking-wide">
                                Saldo
                            </span>

                            <span class="text-sm font-bold text-primary">
                                Rp {{ number_format($account->available_balance, 0, ',', '.') }}
                            </span>

                        </div>

                    </div>

                @empty

                    <div class="flex flex-col items-center justify-center py-12 text-center">

                        <div class="w-14 h-14 rounded-xl flex items-center justify-center mb-4 bg-primary-light">
                            <svg class="w-6 h-6 text-primary"
                                 fill="none"
                                 viewBox="0 0 24 24"
                                 stroke-width="1.5"
                                 stroke="currentColor">
                                <path stroke-linecap="round"
                                      stroke-linejoin="round"
                                      d="M12 4.5v15m7.5-7.5h-15"/>
                            </svg>
                        </div>

                        <p class="text-sm text-gray-500 mb-4">
                            Belum ada rekening
                        </p>

                        <a href="{{ url('/admin/payment-accounts/create') }}"
                           class="inline-flex items-center gap-2 px-4 py-2 text-xs font-semibold rounded-lg bg-primary text-white hover:opacity-90 transition-opacity">

                            <svg class="w-4 h-4"
                                 fill="none"
                                 viewBox="0 0 24 24"
                                 stroke-width="2"
                                 stroke="currentColor">
                                <path stroke-linecap="round"
                                      stroke-linejoin="round"
                                      d="M12 4.5v15m7.5-7.5h-15"/>
                            </svg>

                            Tambah Rekening

                        </a>

                    </div>

                @endforelse

            </div>

        </div>


        {{-- =====================================================
             TRANSAKSI
        ====================================================== --}}

        <div class="lg:col-span-2 fi-section-inner-ctn flex flex-col min-h-0">

            {{-- HEADER --}}

            <div class="card-header shrink-0">

                <div class="card-header-title">

                    <div class="card-header-icon bg-primary">
                        <svg class="w-4 h-4 text-white"
                             fill="none"
                             viewBox="0 0 24 24"
                             stroke-width="1.8"
                             stroke="currentColor">
                            <path stroke-linecap="round"
                                  stroke-linejoin="round"
                                  d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75Zm0 5.25h.007v.008H3.75V12Zm0 5.25h.007v.008H3.75v-.008Z"/>
                        </svg>
                    </div>

                    <span class="truncate">
                        Transaksi Terbaru
                    </span>

                    <span class="badge ml-1 bg-primary-light text-primary">
                        {{ $totalTransactions }} transaksi
                    </span>

                </div>

            </div>


            {{-- =================================================
                 FILTER
            ================================================== --}}

            <div class="transaction-filter shrink-0">

                <div class="transaction-filter-inner">

                    {{-- LABEL --}}

                    <div class="filter-label">

                        <svg class="w-3.5 h-3.5 text-gray-400"
                             fill="none"
                             viewBox="0 0 24 24"
                             stroke="currentColor">
                            <path stroke-linecap="round"
                                  stroke-linejoin="round"
                                  stroke-width="2"
                                  d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                        </svg>

                        <span>
                            Filter
                        </span>

                    </div>


                    {{-- REKENING --}}

                    <div class="filter-control filter-control-account">

                        <select
                            wire:model="filterAccount"
                            class="filter-select">

                            <option value="">
                                Semua Rekening
                            </option>

                            @foreach($accounts as $account)

                                <option value="{{ $account->id }}">
                                    {{ $account->name }}
                                </option>

                            @endforeach

                        </select>

                        {{-- CUSTOM ARROW --}}
                        <span class="filter-arrow">

                            <svg fill="none"
                                 viewBox="0 0 24 24"
                                 stroke="currentColor">
                                <path stroke-linecap="round"
                                      stroke-linejoin="round"
                                      d="m6 9 6 6 6-6"/>
                            </svg>

                        </span>

                    </div>


                    {{-- TYPE --}}

                    <div class="filter-control filter-control-type">

                        <select
                            wire:model="filterType"
                            class="filter-select">

                            <option value="">
                                Semua Jenis
                            </option>

                            <option value="in">
                                Masuk
                            </option>

                            <option value="out">
                                Keluar
                            </option>

                        </select>

                        <span class="filter-arrow">

                            <svg fill="none"
                                 viewBox="0 0 24 24"
                                 stroke="currentColor">
                                <path stroke-linecap="round"
                                      stroke-linejoin="round"
                                      d="m6 9 6 6 6-6"/>
                            </svg>

                        </span>

                    </div>


                    {{-- CATEGORY --}}

                    <div class="filter-control filter-control-category">

                        <select
                            wire:model="filterCategory"
                            class="filter-select">

                            <option value="">
                                Semua Kategori
                            </option>

                            <option value="invoice">
                                Invoice
                            </option>

                            <option value="deposit">
                                Setoran
                            </option>

                            <option value="operational">
                                Operasional
                            </option>

                            <option value="withdrawal">
                                Penarikan
                            </option>

                            <option value="payment">
                                Pembayaran
                            </option>

                            <option value="expense">
                                Biaya
                            </option>

                            <option value="payroll">
                                Gaji
                            </option>

                            <option value="marketing">
                                Marketing
                            </option>

                            <option value="office">
                                Kantor
                            </option>

                            <option value="it">
                                IT
                            </option>

                            <option value="utility">
                                Utilitas
                            </option>

                            <option value="transfer">
                                Transfer
                            </option>

                            <option value="income">
                                Pendapatan
                            </option>

                            {{-- Kategori Expense/Advance --}}
                            <option value="transport">
                                Transport
                            </option>

                            <option value="komunikasi">
                                Komunikasi
                            </option>

                            <option value="software">
                                Software
                            </option>

                            <option value="entertainment">
                                Entertainment
                            </option>

                            <option value="project_support">
                                Project Support
                            </option>

                            <option value="biaya_lain">
                                Biaya Lain
                            </option>

                            <option value="other">
                                Lainnya
                            </option>

                        </select>

                        <span class="filter-arrow">

                            <svg fill="none"
                                 viewBox="0 0 24 24"
                                 stroke="currentColor">
                                <path stroke-linecap="round"
                                      stroke-linejoin="round"
                                      d="m6 9 6 6 6-6"/>
                            </svg>

                        </span>

                    </div>


                    {{-- SPACER --}}

                    <div class="filter-spacer"></div>


                    {{-- CLEAR --}}

                    @if($filterAccount || $filterType || $filterCategory)

                        <button
                            wire:click="clearFilters()"
                            type="button"
                            class="filter-button filter-button-clear">

                            <svg class="w-3.5 h-3.5"
                                 fill="none"
                                 viewBox="0 0 24 24"
                                 stroke="currentColor">
                                <path stroke-linecap="round"
                                      stroke-linejoin="round"
                                      stroke-width="2"
                                      d="M6 18L18 6M6 6l12 12"/>
                            </svg>

                            <span>
                                Hapus
                            </span>

                        </button>

                    @endif


                    {{-- REFRESH --}}

                    <button
                        wire:click="refreshData()"
                        type="button"
                        class="filter-button filter-button-refresh">

                        <svg class="w-3.5 h-3.5"
                             fill="none"
                             viewBox="0 0 24 24"
                             stroke="currentColor">
                            <path stroke-linecap="round"
                                  stroke-linejoin="round"
                                  d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>

                        <span>
                            Refresh
                        </span>

                    </button>

                </div>

            </div>


            {{-- =================================================
                 TABLE
            ================================================== --}}

            <div class="table-scroll-container">

                <table class="data-table">

                    <thead class="sticky top-0 z-10">

                        <tr>

                            <th style="width: 50px; text-align:center;">
                                #
                            </th>

                            <th style="white-space:nowrap;">
                                Tanggal
                            </th>

                            <th style="white-space:nowrap;">
                                Rekening
                            </th>

                            <th>
                                Keterangan
                            </th>

                            <th style="white-space:nowrap;">
                                Kategori
                            </th>

                            <th style="text-align:right; white-space:nowrap;">
                                Jumlah
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        @forelse($recentTransactions as $index => $transaction)

                            <tr>

                                {{-- TYPE --}}

                                <td style="text-align:center;">

                                    <div class="w-8 h-8 rounded-lg inline-flex items-center justify-center
                                        {{ $transaction->type === 'in'
                                            ? 'bg-success-light'
                                            : 'bg-danger-light' }}">

                                        @if($transaction->type === 'in')

                                            <svg class="w-4 h-4 text-success"
                                                 fill="none"
                                                 viewBox="0 0 24 24"
                                                 stroke-width="2.5"
                                                 stroke="currentColor">
                                                <path stroke-linecap="round"
                                                      stroke-linejoin="round"
                                                      d="M4.5 12.75l6 6 9-13.5"/>
                                            </svg>

                                        @else

                                            <svg class="w-4 h-4 text-danger"
                                                 fill="none"
                                                 viewBox="0 0 24 24"
                                                 stroke-width="2.5"
                                                 stroke="currentColor">
                                                <path stroke-linecap="round"
                                                      stroke-linejoin="round"
                                                      d="M19.5 5.25l-7.5 7.5-7.5-7.5"/>
                                            </svg>

                                        @endif

                                    </div>

                                </td>


                                {{-- DATE --}}

                                <td style="white-space:nowrap;">

                                    <span class="text-xs text-gray-500">
                                        {{ $transaction->transaction_date?->format('d M Y') ?? '-' }}
                                    </span>

                                </td>


                                {{-- ACCOUNT --}}

                                <td>

                                    <span class="text-xs font-medium text-gray-700 whitespace-nowrap">
                                        {{ $transaction->paymentAccount->name ?? '-' }}
                                    </span>

                                </td>


                                {{-- DESCRIPTION --}}

                                <td>

                                    <span class="text-xs text-gray-800">
                                        {{ Str::limit($transaction->description ?? '-', 50) }}
                                    </span>

                                </td>


                                {{-- CATEGORY --}}

                                <td>

                                    @php
                                        $categoryLabels = [
                                            'invoice' => 'Invoice',
                                            'deposit' => 'Setoran',
                                            'operational' => 'Operasional',
                                            'payroll' => 'Gaji',
                                            'marketing' => 'Marketing',
                                            'office' => 'Kantor',
                                            'it' => 'IT',
                                            'utility' => 'Utilitas',
                                            'withdrawal' => 'Penarikan',
                                            'transfer' => 'Transfer',
                                            'payment' => 'Bayar',
                                            'expense' => 'Biaya',
                                            'income' => 'Pendapatan',
                                            'transport' => 'Transport',
                                            'komunikasi' => 'Komunikasi',
                                            'software' => 'Software',
                                            'entertainment' => 'Entertainment',
                                            'project_support' => 'Project Support',
                                            'biaya_lain' => 'Biaya Lain',
                                            'other' => 'Lainnya',
                                        ];
                                    @endphp

                                    <span class="badge bg-gray-100 text-gray-600 whitespace-nowrap">
                                        {{ $categoryLabels[$transaction->category] ?? $transaction->category ?? '-' }}
                                    </span>

                                </td>


                                {{-- AMOUNT --}}

                                <td style="text-align:right; white-space:nowrap;">

                                    <span
                                        class="text-xs font-semibold
                                        {{ $transaction->type === 'in'
                                            ? 'text-success'
                                            : 'text-danger' }}">

                                        {{ $transaction->type === 'in' ? '+' : '-' }}
                                        Rp {{ number_format((float) $transaction->amount, 0, ',', '.') }}

                                    </span>

                                </td>

                            </tr>

                        @empty

                            <tr>

                                <td colspan="6"
                                    class="text-center py-12 text-gray-400">

                                    <div class="flex flex-col items-center">

                                        <svg class="w-10 h-10 text-gray-300 mb-3"
                                             fill="none"
                                             viewBox="0 0 24 24"
                                             stroke-width="1.5"
                                             stroke="currentColor">
                                            <path stroke-linecap="round"
                                                  stroke-linejoin="round"
                                                  d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.6M3 3h18"/>
                                        </svg>

                                        <span class="text-sm">
                                            Belum ada transaksi
                                        </span>

                                    </div>

                                </td>

                            </tr>

                        @endforelse

                    </tbody>

                </table>

            </div>


            {{-- =================================================
                 LOAD MORE
            ================================================== --}}

            <div class="px-4 py-4 border-t border-gray-100 bg-gray-50 shrink-0 flex items-center justify-center">

                @if($hasMoreTransactions)

                    <button
                        wire:click="loadMoreTransactions()"
                        wire:loading.attr="disabled"
                        class="px-6 py-2.5 rounded-full text-sm font-semibold flex items-center gap-2 shadow-sm hover:shadow-md transition-all bg-primary text-white">

                        <span
                            wire:loading.remove
                            wire:target="loadMoreTransactions"
                            class="flex items-center gap-2">

                            <svg class="w-4 h-4"
                                 fill="none"
                                 viewBox="0 0 24 24"
                                 stroke="currentColor">
                                <path stroke-linecap="round"
                                      stroke-linejoin="round"
                                      stroke-width="2"
                                      d="M19 9l-7 7-7-7"/>
                            </svg>

                            Lihat Lebih Banyak

                        </span>

                        <span
                            wire:loading
                            wire:target="loadMoreTransactions"
                            class="flex items-center gap-2 opacity-75">

                            <svg class="w-4 h-4 animate-spin"
                                 fill="none"
                                 viewBox="0 0 24 24">

                                <circle
                                    class="opacity-25"
                                    cx="12"
                                    cy="12"
                                    r="10"
                                    stroke="currentColor"
                                    stroke-width="4">
                                </circle>

                                <path
                                    class="opacity-75"
                                    fill="currentColor"
                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z">
                                </path>

                            </svg>

                        </span>

                    </button>

                @elseif($recentTransactions->count() > 0)

                    <div class="flex items-center gap-2 text-xs text-gray-400">

                        <svg class="w-4 h-4"
                             fill="none"
                             viewBox="0 0 24 24"
                             stroke="currentColor">
                            <path stroke-linecap="round"
                                  stroke-linejoin="round"
                                  stroke-width="2"
                                  d="M5 13l4 4L19 7"/>
                        </svg>

                        Menampilkan
                        {{ $recentTransactions->count() }}
                        dari
                        {{ $totalTransactions }}
                        transaksi

                    </div>

                @endif

            </div>

        </div>

    </div>


    {{-- =========================================================
         STATISTIK BULANAN
    ========================================================== --}}

    <div class="fi-section-inner-ctn mb-6">

        <div class="card-header shrink-0">

            <div class="card-header-title">

                <div class="card-header-icon bg-info">

                    <svg class="w-4 h-4 text-white"
                         fill="none"
                         viewBox="0 0 24 24"
                         stroke-width="1.8"
                         stroke="currentColor">

                        <path stroke-linecap="round"
                              stroke-linejoin="round"
                              d="M10.5 6a7.5 7.5 0 107.5 7.5h-7.5V6z"/>

                        <path stroke-linecap="round"
                              stroke-linejoin="round"
                              d="M13.5 10.5H21A7.5 7.5 0 0013.5 3v7.5z"/>

                    </svg>

                </div>

                <span>
                    Statistik Bulanan
                </span>

            </div>

        </div>


        <div class="overflow-auto max-h-[300px] scroll-thin">

            <table class="data-table">

                <thead class="sticky top-0 z-10">

                    <tr>

                        <th style="text-align:left;">
                            Bulan
                        </th>

                        <th style="text-align:right;">
                            Pemasukan
                        </th>

                        <th style="text-align:right;">
                            Pengeluaran
                        </th>

                        <th style="text-align:right;">
                            Net Saldo
                        </th>

                    </tr>

                </thead>

                <tbody>

                    @foreach($monthlyStats as $index => $stat)

                        <tr>

                            <td>

                                @if($index === 0)

                                    <span class="badge bg-primary-light text-primary">

                                        <span class="w-1.5 h-1.5 rounded-full bg-primary"></span>

                                        {{ $stat['month'] }}

                                    </span>

                                @else

                                    <span class="badge bg-gray-100 text-gray-500">
                                        {{ $stat['month'] }}
                                    </span>

                                @endif

                            </td>


                            <td style="text-align:right; font-weight:600;"
                                class="text-success">

                                +Rp {{ number_format($stat['in'], 0, ',', '.') }}

                            </td>


                            <td style="text-align:right; font-weight:600;"
                                class="text-danger">

                                -Rp {{ number_format($stat['out'], 0, ',', '.') }}

                            </td>


                            <td style="text-align:right;">

                                @if($stat['net'] >= 0)

                                    <span class="badge bg-success-light text-success">
                                        +Rp {{ number_format($stat['net'], 0, ',', '.') }}
                                    </span>

                                @else

                                    <span class="badge bg-danger-light text-danger">
                                        -Rp {{ number_format(abs($stat['net']), 0, ',', '.') }}
                                    </span>

                                @endif

                            </td>

                        </tr>

                    @endforeach

                </tbody>

            </table>

        </div>

    </div>


    {{-- =========================================================
         QUICK ACTIONS
    ========================================================== --}}

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">

        {{-- TAMBAH REKENING --}}

        <a href="{{ url('/admin/payment-accounts/create') }}"
           class="quick-action bg-primary">

            <div class="quick-action-icon">

                <svg class="w-5 h-5 text-white"
                     fill="none"
                     viewBox="0 0 24 24"
                     stroke-width="2"
                     stroke="currentColor">

                    <path stroke-linecap="round"
                          stroke-linejoin="round"
                          d="M12 4.5v15m7.5-7.5h-15"/>

                </svg>

            </div>

            <div>

                <p class="font-semibold text-sm">
                    Tambah Rekening
                </p>

                <p class="opacity-60 text-[11px] mt-0.5">
                    Bank atau Cash
                </p>

            </div>

        </a>


        {{-- EXPENSE --}}

        <a href="{{ url('/admin/expenses/create') }}"
           class="quick-action bg-danger">

            <div class="quick-action-icon">

                <svg class="w-5 h-5 text-white"
                     fill="none"
                     viewBox="0 0 24 24"
                     stroke-width="2"
                     stroke="currentColor">

                    <path stroke-linecap="round"
                          stroke-linejoin="round"
                          d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"/>

                </svg>

            </div>

            <div class="flex-1">

                <p class="font-semibold text-sm">
                    Tambah Expense
                </p>

                <p class="opacity-60 text-[11px] mt-0.5">
                    Biaya operasional
                </p>

            </div>

            @if($pendingExpense > 0)

                <span class="shrink-0 w-7 h-7 rounded-full flex items-center justify-center text-[11px] font-bold bg-white/20">
                    {{ $pendingExpense }}
                </span>

            @endif

        </a>


        {{-- ADVANCE --}}

        <a href="{{ url('/admin/advances/create') }}"
           class="quick-action bg-warning">

            <div class="quick-action-icon">

                <svg class="w-5 h-5 text-white"
                     fill="none"
                     viewBox="0 0 24 24"
                     stroke-width="2"
                     stroke="currentColor">

                    <path stroke-linecap="round"
                          stroke-linejoin="round"
                          d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>

                </svg>

            </div>

            <div class="flex-1">

                <p class="font-semibold text-sm">
                    Tambah Advance
                </p>

                <p class="opacity-60 text-[11px] mt-0.5">
                    Dana muka project
                </p>

            </div>

            @if($pendingAdvance > 0)

                <span class="shrink-0 w-7 h-7 rounded-full flex items-center justify-center text-[11px] font-bold bg-white/20">
                    {{ $pendingAdvance }}
                </span>

            @endif

        </a>

    </div>

</x-filament-panels::page>