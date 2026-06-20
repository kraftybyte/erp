<x-filament-panels::page>
    @php
        // Force recalculate totalBalance from accounts in blade to ensure accuracy
        $totalBalance = $accounts->sum('current_balance');

        try {
            $settings = app(\App\Settings\GeneralSettings::class);
        } catch (\Exception $e) {
            $settings = null;
        }

        // Helper to strip # prefix if present
        $stripHash = fn($v) => ltrim($v ?? '', '#');

        // Load theme from GeneralSettings site_theme JSON
        $themeArray = is_array($settings?->site_theme) ? $settings->site_theme : [];

        // Default theme colors (fallback if not set in settings)
        $themeDefaults = [
            'primary' => '90c646',
            'primary_dark' => '6fa335',
            'success' => '10b981',
            'warning' => 'f59e0b',
            'danger' => 'ef4444',
            'info' => '3b82f6',
        ];

        // Merge defaults, then site_theme (site_theme overrides defaults)
        $theme = array_merge($themeDefaults, $themeArray);
        // Flatten: strip # from any value that has it
        $theme = array_map($stripHash, $theme);

        // Get colors from settings with proper fallback
        // Priority: GeneralSettings field > site_theme > defaults
        $primaryColor   = '#' . ($stripHash($settings?->primary_gradient_from) ?? $theme['primary']);
        $secondaryColor = '#' . ($stripHash($settings?->primary_gradient_to) ?? $theme['primary_dark']);

        // Semantic colors
        $successColor  = '#' . $theme['success'];
        $warningColor  = '#' . $theme['warning'];
        $dangerColor   = '#' . $theme['danger'];
        $infoColor     = '#' . $theme['info'];

        // Hex to rgba helper - handles hex with or without #
        $rgba = function($hex, $opacity) {
            $hex = ltrim($hex ?? '#000000', '#');
            // Expand 3-char hex to 6-char
            if (strlen($hex) === 3) {
                $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
            }
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
            return "rgba({$r}, {$g}, {$b}, {$opacity})";
        };

        // Count pending
        $pendingExpense = \App\Models\Expense::whereIn('status', ['Pending', 'Disetujui'])->count();
        $pendingAdvance = \App\Models\Advance::whereIn('status', ['Pending', 'Disetujui'])->count();
    @endphp

    <style>
        /* Card container - Filament standard */
        .fi-section-inner-ctn {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
        }

        /* Card header */
        .card-header {
            padding: 16px 20px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .card-header-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
        }
        .card-header-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Stat cards */
        .stat-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            transition: all 0.2s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.08);
        }

        /* Table styles */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        .data-table thead tr {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-bottom: 2px solid #e2e8f0;
        }
        .data-table thead th {
            padding: 12px 16px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #64748b;
        }
        .data-table tbody tr {
border-bottom: 1px solid #f1f5f9;
        }
        .data-table tbody tr:hover {
            background: #f8fafc;
        }
        .data-table tbody td {
            padding: 12px 16px;
            font-size: 12px;
            color: #334155;
            vertical-align: middle;
        }

        /* Badge */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 600;
            padding: 4px 10px;
            letter-spacing: 0.2px;
        }

        /* Quick action cards */
        .quick-action {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px 20px;
            border-radius: 12px;
            text-decoration: none;
            transition: all 0.2s ease;
            color: white;
        }
        .quick-action:hover {
            transform: translateY(-2px);
            opacity: 0.95;
        }
        .quick-action-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: rgba(255,255,255,0.2);
        }

        /* Scrollbar */
        .scroll-thin::-webkit-scrollbar { width: 4px; }
        .scroll-thin::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 99px; }
        .scroll-thin::-webkit-scrollbar-track { background: transparent; }

        /* Table scroll container */
        .table-scroll-container {
            height: 400px;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 #f1f5f9;
        }
        .table-scroll-container::-webkit-scrollbar {
            width: 8px;
        }
        .table-scroll-container::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        .table-scroll-container::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
        .table-scroll-container::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* Primary stat card (gradient background) */
        .stat-card-primary {
            background-color: var(--color-primary);
            border: none;
            border-radius: 12px;
            padding: 20px;
        }
        .stat-card-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.15);
        }

        /* Dynamic color utilities */
        .bg-primary { background-color: var(--color-primary); }
        .bg-success { background-color: var(--color-success); }
        .bg-warning { background-color: var(--color-warning); }
        .bg-danger { background-color: var(--color-danger); }
        .bg-info { background-color: var(--color-info); }
        .text-primary { color: var(--color-primary); }
        .text-success { color: var(--color-success); }
        .text-warning { color: var(--color-warning); }
        .text-danger { color: var(--color-danger); }
        .text-info { color: var(--color-info); }
        .border-primary { border-color: var(--color-primary); }
        .bg-primary-light { background-color: var(--color-primary-rgba); }
        .bg-success-light { background-color: var(--color-success-rgba); }
        .bg-warning-light { background-color: var(--color-warning-rgba); }
        .bg-danger-light { background-color: var(--color-danger-rgba); }
        .bg-info-light { background-color: var(--color-info-rgba); }

        /* CSS Custom Properties for dynamic theming */
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

    {{-- ── 4-Stat Header ──────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">

        {{-- Total Saldo (Primary color) --}}
        <div class="stat-card-primary">
            <div class="flex items-start justify-between">
                <div class="flex-1">
                    <p class="text-white text-[10px] font-semibold uppercase tracking-widest mb-2">Total Saldo</p>
                    <p class="text-white text-lg font-extrabold leading-tight">
                        Rp {{ number_format($totalBalance, 0, ',', '.') }}
                    </p>
                    <p class="text-white text-[11px] mt-2">{{ $accounts->count() }} rekening aktif</p>
                </div>
                <div class="w-10 h-10 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z"/>
                    </svg>
                </div>
            </div>
        </div>

        {{-- Pemasukan --}}
        <div class="stat-card">
            <div class="flex items-start justify-between">
                <div class="flex-1">
                    <p class="text-gray-400 text-[10px] font-semibold uppercase tracking-widest mb-2">Pemasukan</p>
                    <p class="text-gray-900 text-lg font-extrabold leading-tight">
                        Rp {{ number_format($totalInThisMonth, 0, ',', '.') }}
                    </p>
                    <p class="text-gray-400 text-[11px] mt-2">Bulan ini</p>
                </div>
                <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 bg-success-light">
                    <svg class="w-5 h-5 text-success" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                    </svg>
                </div>
            </div>
        </div>

        {{-- Pengeluaran --}}
        <div class="stat-card">
            <div class="flex items-start justify-between">
                <div class="flex-1">
                    <p class="text-gray-400 text-[10px] font-semibold uppercase tracking-widest mb-2">Pengeluaran</p>
                    <p class="text-gray-900 text-lg font-extrabold leading-tight">
                        Rp {{ number_format($totalOutThisMonth, 0, ',', '.') }}
                    </p>
                    <p class="text-gray-400 text-[11px] mt-2">Bulan ini</p>
                </div>
                <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 bg-danger-light">
                    <svg class="w-5 h-5 text-danger" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14"/>
                    </svg>
                </div>
            </div>
        </div>

        {{-- Menunggu Persetujuan --}}
        <div class="stat-card">
            <div class="flex items-start justify-between">
                <div class="flex-1">
                    <p class="text-gray-400 text-[10px] font-semibold uppercase tracking-widest mb-2">Menunggu</p>
                    <p class="text-gray-900 text-lg font-extrabold leading-tight">{{ $pendingPayments }}</p>
                    <p class="text-gray-400 text-[11px] mt-2">Expense & Advance</p>
                </div>
                <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 bg-warning-light">
                    <svg class="w-5 h-5 text-warning" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                    </svg>
                </div>
            </div>
        </div>

    </div>

    {{-- ── Main Grid: Rekening + Transaksi ────────────────────────────────── --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">

        {{-- Daftar Rekening --}}
        <div class="fi-section-inner-ctn">
            <div class="card-header">
                <div class="card-header-title">
                    <div class="card-header-icon bg-primary">
                        <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z"/>
                        </svg>
                    </div>
                    <span>Daftar Rekening</span>
                </div>
                <a href="{{ url('/admin/payment-accounts') }}" class="text-[11px] font-semibold hover:opacity-75 transition-opacity text-primary">
                    Kelola →
                </a>
            </div>

            <div class="p-4 space-y-3 max-h-[420px] overflow-y-auto scroll-thin">
                @forelse($accounts as $account)
                    <div class="group p-4 rounded-xl bg-gray-50 border border-gray-100 hover:bg-white transition-all">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg bg-white border border-gray-200 group-hover:border-gray-300 transition-colors flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z"/>
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-gray-800 truncate">{{ $account->name }}</p>
                                @if($account->bank_name)
                                    <p class="text-[11px] text-gray-400">{{ $account->bank_name }}</p>
                                @endif
                            </div>
                        </div>
                        <div class="flex items-center justify-between mt-3 pt-3 border-t border-gray-100">
                            <span class="text-[10px] text-gray-400 font-medium uppercase tracking-wide">Saldo</span>
                            <span class="text-sm font-bold text-primary">
                                Rp {{ number_format($account->available_balance, 0, ',', '.') }}
                            </span>
                        </div>
                    </div>
                @empty
                    <div class="flex flex-col items-center justify-center py-12 text-center">
                        <div class="w-14 h-14 rounded-[10px] flex items-center justify-center mb-4 bg-primary-light">
                            <svg class="w-6 h-6 text-primary" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                            </svg>
                        </div>
                        <p class="text-sm text-gray-500 mb-4">Belum ada rekening</p>
                        <a href="{{ url('/admin/payment-accounts/create') }}"
                           class="inline-flex items-center gap-2 px-4 py-2 text-xs font-semibold rounded-lg transition-opacity bg-primary text-white">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                            </svg>
                            Tambah Rekening
                        </a>
                    </div>
                @endforelse
            </div>
        </div>

        {{-- Transaksi Terbaru --}}
        <div class="lg:col-span-2 fi-section-inner-ctn flex flex-col h-full">
            <div class="card-header shrink-0">
                <div class="card-header-title">
                    <div class="card-header-icon bg-primary">
                        <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 12h.007v.008H3.75V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm-.375 5.25h.007v.008H3.75v-.008Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/>
                        </svg>
                    </div>
                    <span>Transaksi Terbaru</span>
                    <span class="badge ml-2 bg-primary-light text-primary">
                        {{ $totalTransactions }} transaksi
                    </span>
                </div>
            </div>

            {{-- Filters --}}
            <div class="px-4 py-3 border-b border-gray-100 bg-gray-50 shrink-0 overflow-hidden">
                <div class="flex items-center gap-2 flex-wrap -m-1">

                    {{-- Filter Icon & Label --}}
                    <div class="flex items-center gap-1.5 flex-shrink-0 px-1 py-1">
                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                        </svg>
                        <span class="text-xs font-semibold text-gray-600 whitespace-nowrap">Filter:</span>
                    </div>

                    {{-- Dropdowns --}}
                    <select
                        wire:model="filterAccount"
                        class="h-9 text-sm border border-gray-200 rounded-lg px-3 bg-white focus:ring-2 focus:ring-blue-500 flex-shrink-0 whitespace-nowrap cursor-pointer min-w-[140px] appearance-none">
                        <option value="">Semua Rekening</option>
                        @foreach($accounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                        @endforeach
                    </select>

                    <select
                        wire:model="filterType"
                        class="h-9 text-sm border border-gray-200 rounded-lg px-3 bg-white focus:ring-2 focus:ring-blue-500 flex-shrink-0 whitespace-nowrap cursor-pointer min-w-[130px] appearance-none">
                        <option value="">Semua Jenis</option>
                        <option value="in">Masuk</option>
                        <option value="out">Keluar</option>
                    </select>

                    <select
                        wire:model="filterCategory"
                        class="h-9 text-sm border border-gray-200 rounded-lg px-3 bg-white focus:ring-2 focus:ring-blue-500 flex-shrink-0 whitespace-nowrap cursor-pointer min-w-[150px] appearance-none">
                        <option value="">Semua Kategori</option>
                        <option value="invoice">Invoice</option>
                        <option value="deposit">Setoran</option>
                        <option value="operational">Operasional</option>
                        <option value="withdrawal">Penarikan</option>
                        <option value="payment">Pembayaran</option>
                        <option value="expense">Biaya</option>
                        <option value="other">Lainnya</option>
                    </select>

                    {{-- Clear Button --}}
                    @if($filterAccount || $filterType || $filterCategory)
                        <button
                            wire:click="clearFilters()"
                            class="h-9 text-xs px-3 rounded-lg bg-red-50 hover:bg-red-100 text-red-600 font-medium flex items-center gap-1.5 flex-shrink-0 whitespace-nowrap">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                            Hapus
                        </button>
                    @endif

                    {{-- Spacer --}}
                    <div class="flex-1 min-w-[20px]"></div>

                    {{-- Refresh Button --}}
                    <button
                        wire:click="refreshData()"
                        class="h-9 text-xs px-4 rounded-lg flex items-center gap-1.5 font-medium flex-shrink-0 whitespace-nowrap bg-primary text-white">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        Refresh
                    </button>
                </div>
            </div>

            {{-- Table with scroll --}}
            <div class="table-scroll-container">
                <table class="data-table">
                    <thead class="sticky top-0 bg-gray-50 z-10">
                        <tr>
                            <th style="width: 40px; text-align: center; white-space: nowrap;">#</th>
                            <th style="white-space: nowrap;">Tanggal</th>
                            <th style="white-space: nowrap;">Rekening</th>
                            <th>Keterangan</th>
                            <th style="white-space: nowrap;">Kategori</th>
                            <th style="text-align: right; white-space: nowrap;">Jumlah</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentTransactions as $index => $transaction)
                            <tr class="hover:bg-gray-50">
                                {{-- Tipe --}}
                                <td style="text-align: center; width: 50px;">
                                    <div class="w-8 h-8 rounded-lg inline-flex items-center justify-center flex-shrink-0 {{ $transaction->type === 'in' ? 'bg-success-light' : 'bg-danger-light' }}">
                                        @if($transaction->type === 'in')
                                            <svg class="w-4 h-4 text-success" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                            </svg>
                                        @else
                                            <svg class="w-4 h-4 text-danger" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 5.25l-7.5 7.5-7.5-7.5"/>
                                            </svg>
                                        @endif
                                    </div>
                                </td>
                                {{-- Tanggal --}}
                                <td style="white-space: nowrap;">
                                    <span class="text-xs text-gray-500">{{ $transaction->transaction_date?->format('d M Y') ?? '-' }}</span>
                                </td>
                                {{-- Rekening --}}
                                <td>
                                    <span class="text-xs font-medium text-gray-700 whitespace-nowrap">{{ $transaction->paymentAccount->name ?? '-' }}</span>
                                </td>
                                {{-- Keterangan --}}
                                <td>
                                    <span class="text-xs text-gray-800 whitespace-normal">{{ Str::limit($transaction->description ?? '-', 50) }}</span>
                                </td>
                                {{-- Kategori --}}
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
                                            'other' => 'Lainnya',
                                        ];
                                    @endphp
                                    <span class="badge bg-gray-100 text-gray-600 text-xs whitespace-nowrap">
                                        {{ $categoryLabels[$transaction->category] ?? $transaction->category ?? '-' }}
                                    </span>
                                </td>
                                {{-- Jumlah --}}
                                <td style="text-align: right; white-space: nowrap;">
                                    <span class="text-xs font-semibold whitespace-nowrap {{ $transaction->type === 'in' ? 'text-success' : 'text-danger' }}">
                                        {{ $transaction->type === 'in' ? '+' : '-' }}Rp {{ number_format((float) $transaction->amount, 0, ',', '.') }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-12 text-gray-400">
                                    <div class="flex flex-col items-center">
                                        <svg class="w-10 h-10 text-gray-300 mb-3" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.6M3 3h18m0 0v1.35A2.25 2.25 0 0118.75 6H21M3 3v1.35A2.25 2.25 0 015.25 6H6m12.75 12H21V3"/>
                                        </svg>
                                        <span class="text-sm">Belum ada transaksi</span>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Load More Button --}}
            <div class="px-4 py-4 border-t border-gray-100 bg-gray-50 shrink-0 flex items-center justify-center">
                @if($hasMoreTransactions)
                    <button
                        wire:click="loadMoreTransactions()"
                        wire:loading.attr="disabled"
                        class="px-6 py-2.5 rounded-full text-sm font-semibold flex items-center gap-2 shadow-sm hover:shadow-md transition-all bg-primary text-white">
                        <span wire:loading.remove wire:target="loadMoreTransactions" class="flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                            Lihat Lebih Banyak
                        </span>
                        <span wire:loading wire:target="loadMoreTransactions" class="flex items-center gap-2 opacity-75">
                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </span>
                    </button>
                @elseif($recentTransactions->count() > 0)
                    <div class="flex items-center gap-2 text-xs text-gray-400">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        Menampilkan {{ $recentTransactions->count() }} dari {{ $totalTransactions }} transaksi
                    </div>
                @endif
            </div>
        </div>

    </div>

    {{-- ── Statistik Bulanan ───────────────────────────────────────────────── --}}
    <div class="fi-section-inner-ctn mb-6">
        <div class="card-header">
            <div class="card-header-title">
                <div class="card-header-icon bg-info">
                    <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6a7.5 7.5 0 107.5 7.5h-7.5V6z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5H21A7.5 7.5 0 0013.5 3v7.5z"/>
                    </svg>
                </div>
                <span>Statistik Bulanan</span>
            </div>
        </div>

        <div class="overflow-auto max-h-[300px] scroll-thin">
            <table class="data-table">
                <thead class="sticky top-0 bg-gray-50 z-10">
                    <tr>
                        <th style="text-align: left;">Bulan</th>
                        <th style="text-align: right;">Pemasukan</th>
                        <th style="text-align: right;">Pengeluaran</th>
                        <th style="text-align: right;">Net Saldo</th>
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
                            <td style="text-align: right; font-weight: 600;" class="text-success">
                                +Rp {{ number_format($stat['in'], 0, ',', '.') }}
                            </td>
                            <td style="text-align: right; font-weight: 600;" class="text-danger">
                                -Rp {{ number_format($stat['out'], 0, ',', '.') }}
                            </td>
                            <td style="text-align: right;">
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

    {{-- ── Quick Actions ───────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">

        {{-- Tambah Rekening --}}
        <a href="{{ url('/admin/payment-accounts/create') }}"
           class="quick-action bg-primary">
            <div class="quick-action-icon">
                <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                </svg>
            </div>
            <div>
                <p class="font-semibold text-sm">Tambah Rekening</p>
                <p class="opacity-60 text-[11px] mt-0.5">Bank atau Cash</p>
            </div>
        </a>

        {{-- Bayar Expense --}}
        <a href="{{ url('/admin/expenses') }}"
           class="quick-action bg-danger">
            <div class="quick-action-icon">
                <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"/>
                </svg>
            </div>
            <div class="flex-1">
                <p class="font-semibold text-sm">Bayar Expense</p>
                <p class="opacity-60 text-[11px] mt-0.5">
                    {{ $pendingExpense > 0 ? $pendingExpense . ' menunggu' : 'Tidak ada' }}
                </p>
            </div>
            @if($pendingExpense > 0)
                <span class="shrink-0 w-7 h-7 rounded-full flex items-center justify-center text-[11px] font-bold bg-white/20">
                    {{ $pendingExpense }}
                </span>
            @endif
        </a>

        {{-- Bayar Advance --}}
        <a href="{{ url('/admin/advances') }}"
           class="quick-action bg-warning">
            <div class="quick-action-icon">
                <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                </svg>
            </div>
            <div class="flex-1">
                <p class="font-semibold text-sm">Bayar Advance</p>
                <p class="opacity-60 text-[11px] mt-0.5">
                    {{ $pendingAdvance > 0 ? $pendingAdvance . ' menunggu' : 'Tidak ada' }}
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
