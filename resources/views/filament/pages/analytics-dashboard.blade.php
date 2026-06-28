<x-filament-panels::page>

    <style>
        .analytics-page {
            padding: 1.5rem 2rem 3rem;
            max-width: 1800px;
            margin: 0 auto;
        }
        /* Top Stats Grid */
        .top-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
            justify-content: center;
        }
        @media (max-width: 1024px) {
            .top-stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 640px) {
            .top-stats-grid {
                grid-template-columns: 1fr;
            }
        }
        .grid-responsive {
            display: grid;
            gap: 1.5rem;
            grid-template-columns: repeat(1, 1fr);
        }
        @media (min-width: 640px) {
            .grid-2 { grid-template-columns: repeat(2, 1fr); }
            .grid-3 { grid-template-columns: repeat(3, 1fr); }
        }
        @media (min-width: 1024px) {
            .grid-4 { grid-template-columns: repeat(4, 1fr); }
            .grid-3 { grid-template-columns: repeat(3, 1fr); }
        }
        @media (min-width: 1280px) {
            .grid-4 { grid-template-columns: repeat(4, 1fr); }
        }
        .card {
            background: white;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }
        .card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #f3f4f6;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .card-header h3 {
            font-size: 0.875rem;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
        }
        .card-body {
            padding: 1.25rem 1.5rem;
        }
        .stat-card {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1f2937;
            line-height: 1.2;
        }
        .stat-value.text-success { color: #10b981; }
        .stat-value.text-danger { color: #ef4444; }
        .stat-value.text-warning { color: #f59e0b; }
        .stat-value.text-primary { color: #90c646; }
        .stat-label {
            font-size: 0.75rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-desc {
            font-size: 0.8125rem;
            color: #9ca3af;
        }
        .tab-container {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 2rem;
            padding-bottom: 0.5rem;
        }
        .tab-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.875rem 1.5rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            color: #6b7280;
            background: white;
            border: 1px solid #e5e7eb;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        .tab-btn:hover {
            background: #f3f4f6;
            color: #374151;
            border-color: #d1d5db;
        }
        .tab-btn.active {
            background: #90c646;
            color: white;
            border-color: #90c646;
        }
        .section {
            margin-bottom: 2rem;
        }
        .section-title {
            font-size: 1rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .section-title::after {
            content: "";
            flex: 1;
            height: 1px;
            background: #e5e7eb;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8125rem;
        }
        .data-table th {
            text-align: left;
            padding: 0.875rem 1rem;
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
        }
        .data-table td {
            padding: 0.875rem 1rem;
            border-bottom: 1px solid #f3f4f6;
            color: #374151;
        }
        .data-table tr:last-child td {
            border-bottom: none;
        }
        .data-table tr:hover td {
            background: #fafafa;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        .badge-gray { background: #f3f4f6; color: #475569; }
        .filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            margin-bottom: 2rem;
            padding: 1.25rem 1.5rem;
            background: #f9fafb;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .filter-label {
            font-size: 0.75rem;
            font-weight: 500;
            color: #6b7280;
        }
        .filter-input {
            padding: 0.625rem 1rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.875rem;
            min-width: 160px;
        }
        .quick-filters {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .quick-btn {
            padding: 0.5rem 1rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            background: white;
            font-size: 0.8125rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .quick-btn:hover {
            background: #f3f4f6;
        }
        .quick-btn.active {
            background: #90c646;
            color: white;
            border-color: #90c646;
        }
        .account-card {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            padding: 1rem;
            background: #f9fafb;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        .account-name {
            font-weight: 600;
            color: #1f2937;
            font-size: 0.875rem;
        }
        .account-detail {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            color: #6b7280;
        }
        .account-balance {
            font-weight: 700;
            color: #10b981;
            font-size: 1rem;
        }
        /* Compact Stats */
        .stat-mini {
            padding: 1rem 1.25rem;
        }
        .stat-mini-content {
            display: flex;
            flex-direction: column;
            gap: 0.375rem;
        }
        .stat-mini-label {
            font-size: 0.75rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-mini-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1f2937;
        }
        /* Overview Grid */
        .overview-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }
        @media (max-width: 1024px) {
            .overview-grid {
                grid-template-columns: 1fr;
            }
        }
        .overview-col {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        /* Transactions Grid - Accounts narrower, Data wider */
        .transactions-grid {
            display: flex;
            gap: 1.5rem;
        }
        .transactions-grid .overview-col:first-child {
            flex: 0 0 280px;
        }
        .transactions-grid .overview-col:last-child {
            flex: 1;
            min-width: 0;
        }
        @media (max-width: 1024px) {
            .transactions-grid {
                flex-direction: column;
            }
            .transactions-grid .overview-col:first-child,
            .transactions-grid .overview-col:last-child {
                flex: 0 0 100%;
            }
        }
        /* Advances Grid - Status Overview narrower */
        .advances-grid {
            display: flex;
            gap: 1.5rem;
        }
        .advances-grid .overview-col:first-child {
            flex: 0 0 220px;
        }
        .advances-grid .overview-col:last-child {
            flex: 1;
            min-width: 0;
        }
        @media (max-width: 1024px) {
            .advances-grid,
            .transactions-grid,
            .projects-grid {
                flex-direction: column;
            }
            .advances-grid .overview-col:first-child,
            .advances-grid .overview-col:last-child,
            .transactions-grid .overview-col:first-child,
            .transactions-grid .overview-col:last-child,
            .projects-grid .overview-col:first-child,
            .projects-grid .overview-col:last-child {
                flex: 0 0 100%;
            }
        }
        /* Projects Grid - Status Overview narrower */
        .projects-grid {
            display: flex;
            gap: 1.5rem;
        }
        .projects-grid .overview-col:first-child {
            flex: 0 0 220px;
        }
        .projects-grid .overview-col:last-child {
            flex: 1;
            min-width: 0;
        }
        /* Mini Stats */
        .mini-stats {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .mini-stat {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f3f4f6;
        }
        .mini-stat:last-child {
            border-bottom: none;
        }
        .mini-stat-label {
            font-size: 0.875rem;
            color: #6b7280;
        }
        .mini-stat-value {
            font-size: 0.9375rem;
            font-weight: 600;
            color: #1f2937;
        }
        /* Account List */
        .account-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .account-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            background: #f9fafb;
            border-radius: 8px;
        }
        .text-right {
            text-align: right;
        }
        .text-gray-500 {
            color: #6b7280;
        }
        .text-sm {
            font-size: 0.875rem;
        }
        /* Pagination */
        .pagination {
            display: flex;
            gap: 0.375rem;
            align-items: center;
        }
        .pagination-btn {
            padding: 0.375rem 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            background: white;
            font-size: 0.8125rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .pagination-btn:hover:not(.disabled) {
            background: #f3f4f6;
            border-color: #9ca3af;
        }
        .pagination-btn.active {
            background: #90c646;
            color: white;
            border-color: #90c646;
        }
        .pagination-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
    </style>

    <div class="analytics-page">
        {{-- FILTER BAR --}}
        <div class="filter-bar">
            <div class="filter-group">
                <span class="filter-label">Quick Filters</span>
                <div class="quick-filters">
                    <button wire:click="setDateRange('today')" class="quick-btn {{ $dateRange === 'today' ? 'active' : '' }}">Today</button>
                    <button wire:click="setDateRange('this_week')" class="quick-btn {{ $dateRange === 'this_week' ? 'active' : '' }}">This Week</button>
                    <button wire:click="setDateRange('this_month')" class="quick-btn {{ $dateRange === 'this_month' ? 'active' : '' }}">This Month</button>
                    <button wire:click="setDateRange('last_month')" class="quick-btn {{ $dateRange === 'last_month' ? 'active' : '' }}">Last Month</button>
                    <button wire:click="setDateRange('this_quarter')" class="quick-btn {{ $dateRange === 'this_quarter' ? 'active' : '' }}">This Quarter</button>
                    <button wire:click="setDateRange('this_year')" class="quick-btn {{ $dateRange === 'this_year' ? 'active' : '' }}">This Year</button>
                </div>
            </div>
            <div class="filter-group">
                <span class="filter-label">From</span>
                <input type="date" wire:model.live="dateFrom" class="filter-input" />
            </div>
            <div class="filter-group">
                <span class="filter-label">To</span>
                <input type="date" wire:model.live="dateTo" class="filter-input" />
            </div>
        </div>

        {{-- TABS NAVIGATION --}}
        <div class="tab-container" style="flex-wrap: wrap; justify-content: flex-start;">
            @foreach($this->getTabs() as $tabKey => $tab)
                <button wire:click="$set('activeTab', '{{ $tabKey }}')" class="tab-btn @if($activeTab === $tabKey) active @endif">
                    {{ $tab['label'] }}
                </button>
            @endforeach
        </div>

        {{-- ============================================
            OVERVIEW TAB
            ============================================ --}}
        @if($activeTab === 'overview')
            @php $data = $this->getOverviewData(); @endphp

            {{-- Top Stats Row --}}
            <div class="top-stats-grid">
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">Total Revenue</span>
                        <span class="stat-mini-value text-success">Rp {{ number_format($data['revenue']['all_time'] / 1000000, 1) }}M</span>
                    </div>
                </div>
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">Total Expenses</span>
                        <span class="stat-mini-value text-danger">Rp {{ number_format($data['expenses']['all_time'] / 1000000, 1) }}M</span>
                    </div>
                </div>
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">Total Advances</span>
                        <span class="stat-mini-value text-warning">Rp {{ number_format($data['advances']['all_time'] / 1000000, 1) }}M</span>
                    </div>
                </div>
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">Balance</span>
                        <span class="stat-mini-value text-primary">Rp {{ number_format($data['balance']['total'] / 1000000, 1) }}M</span>
                    </div>
                </div>
            </div>

            {{-- Two Column Layout --}}
            <div class="overview-grid">
                {{-- Left Column: Cash Flow & Pending --}}
                <div class="overview-col">
                    <div class="card">
                        <div class="card-body">
                            <h3 style="font-size: 0.875rem; font-weight: 600; margin-bottom: 0.75rem; color: #1f2937;">Cash Flow</h3>
                            <div class="mini-stats">
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Inflow</span>
                                    <span class="mini-stat-value text-success">Rp {{ number_format($data['balance']['monthly_in'] / 1000000, 1) }}M</span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Outflow</span>
                                    <span class="mini-stat-value text-danger">Rp {{ number_format($data['balance']['monthly_out'] / 1000000, 1) }}M</span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Net</span>
                                    <span class="mini-stat-value {{ ($data['balance']['monthly_in'] - $data['balance']['monthly_out']) >= 0 ? 'text-success' : 'text-danger' }}">
                                        Rp {{ number_format(($data['balance']['monthly_in'] - $data['balance']['monthly_out']) / 1000000, 1) }}M
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <h3 style="font-size: 0.875rem; font-weight: 600; margin-bottom: 0.75rem; color: #1f2937;">Pending Approvals</h3>
                            <div class="mini-stats">
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Expenses</span>
                                    <span class="mini-stat-value">{{ $data['expenses']['pending_count'] }} <small>({{ number_format($data['expenses']['pending'] / 1000000, 1) }}M)</small></span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Advances</span>
                                    <span class="mini-stat-value">{{ $data['advances']['pending_count'] }} <small>({{ number_format($data['advances']['pending'] / 1000000, 1) }}M)</small></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <h3 style="font-size: 0.875rem; font-weight: 600; margin-bottom: 0.75rem; color: #1f2937;">Leads & Deals</h3>
                            <div class="mini-stats">
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Total Leads</span>
                                    <span class="mini-stat-value">{{ $data['leads']['total'] }}</span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Converted</span>
                                    <span class="mini-stat-value text-success">{{ $data['leads']['converted'] }} ({{ $data['leads']['conversion_rate'] }}%)</span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Active Deals</span>
                                    <span class="mini-stat-value">{{ $data['deals']['active'] }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Right Column: Accounts & Advances Table --}}
                <div class="overview-col">
                    <div class="card">
                        <div class="card-body">
                            <h3 style="font-size: 0.875rem; font-weight: 600; margin-bottom: 0.75rem; color: #1f2937;">Payment Accounts</h3>
                            <div class="account-list">
                                @forelse($data['accounts'] as $account)
                                    <div class="account-row">
                                        <div>
                                            <div style="font-weight: 600; color: #1f2937;">{{ $account->name }}</div>
                                            @if($account->bank_name)
                                                <small style="color: #6b7280;">{{ $account->bank_name }} - {{ $account->account_number }}</small>
                                            @endif
                                        </div>
                                        <div class="text-right">
                                            <div class="account-balance">Rp {{ number_format($account->available_balance, 0, ',', '.') }}</div>
                                        </div>
                                    </div>
                                @empty
                                    <p class="text-gray-500 text-sm">No active accounts</p>
                                @endforelse
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <h3 style="font-size: 0.875rem; font-weight: 600; margin-bottom: 0.75rem; color: #1f2937;">
                                Recent Advances
                                <span class="text-gray-400 font-normal" style="font-size: 0.75rem;"> ({{ $data['advances']['recent']->count() }} items)</span>
                            </h3>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>User</th>
                                        <th>Description</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($data['advances']['recent'] as $advance)
                                        <tr>
                                            <td>{{ $advance->submission_date?->format('d/m/Y') ?? '-' }}</td>
                                            <td>{{ $advance->user?->name ?? '-' }}</td>
                                            <td style="max-width: 200px;">
                                                <span title="{{ $advance->keterangan_advance }}">{{ Str::limit($advance->keterangan_advance, 30) }}</span>
                                            </td>
                                            <td>Rp {{ number_format($advance->paid ?? $advance->amount, 0, ',', '.') }}</td>
                                            <td>
                                                @switch($advance->status)
                                                    @case('Pending')
                                                        <span class="badge badge-warning">Pending</span>
                                                        @break
                                                    @case('Disetujui')
                                                        <span class="badge badge-info">Approved</span>
                                                        @break
                                                    @case('Selesai')
                                                        <span class="badge badge-success">Done</span>
                                                        @break
                                                    @default
                                                        <span class="badge badge-gray">{{ $advance->status }}</span>
                                                @endswitch
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="text-center text-gray-400">No advances found</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- ============================================
            REVENUE TAB
            ============================================ --}}
        @if($activeTab === 'revenue')
            @php $data = $this->getRevenueData(); @endphp

            <div class="top-stats-grid">
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">Total Revenue</span>
                        <span class="stat-mini-value text-success">Rp {{ number_format(($data['stats']->total_revenue ?? 0) / 1000000, 1) }}M</span>
                    </div>
                </div>
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">This Month</span>
                        <span class="stat-mini-value">Rp {{ number_format(($data['stats']->current_month_revenue ?? 0) / 1000000, 1) }}M</span>
                    </div>
                </div>
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">Pending</span>
                        <span class="stat-mini-value text-warning">{{ $data['stats']->pending_count ?? 0 }} <small>({{ number_format(($data['stats']->pending_total ?? 0) / 1000000, 1) }}M)</small></span>
                    </div>
                </div>
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">Overdue</span>
                        <span class="stat-mini-value text-danger">{{ $data['stats']->overdue_count ?? 0 }} <small>({{ number_format(($data['stats']->overdue_total ?? 0) / 1000000, 1) }}M)</small></span>
                    </div>
                </div>
            </div>

            <div class="overview-grid">
                <div class="overview-col">
                    <div class="card">
                        <div class="card-body">
                            <h3 style="font-size: 0.875rem; font-weight: 600; margin-bottom: 0.75rem; color: #1f2937;">Status Distribution</h3>
                            <div class="mini-stats">
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Paid</span>
                                    <span class="mini-stat-value text-success">{{ $data['distribution']->paid_count }} (Rp {{ number_format($data['distribution']->paid / 1000000, 1) }}M)</span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Pending</span>
                                    <span class="mini-stat-value text-warning">{{ $data['distribution']->pending_count }} (Rp {{ number_format($data['distribution']->pending / 1000000, 1) }}M)</span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Cancelled</span>
                                    <span class="mini-stat-value text-danger">{{ $data['distribution']->cancelled_count }} (Rp {{ number_format($data['distribution']->cancelled / 1000000, 1) }}M)</span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Draft</span>
                                    <span class="mini-stat-value">{{ $data['distribution']->draft_count }} (Rp {{ number_format($data['distribution']->draft / 1000000, 1) }}M)</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="overview-col">
                    <div class="card">
                        <div class="card-body" style="padding: 0;">
                            <table class="data-table" style="font-size: 0.75rem;">
                                <thead>
                                    <tr>
                                        <th>Invoice #</th>
                                        <th>Customer</th>
                                        <th>Total</th>
                                        <th>Status</th>
                                        <th>Due</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($data['recent'] as $invoice)
                                        <tr>
                                            <td>{{ $invoice->uuid }}</td>
                                            <td>{{ $invoice->nama_perusahaan }}</td>
                                            <td>Rp {{ number_format($invoice->grand_total, 0, ',', '.') }}</td>
                                            <td>
                                                @switch($invoice->status)
                                                    @case('paid')
                                                        <span class="badge badge-success">Paid</span>
                                                        @break
                                                    @case('pending')
                                                        <span class="badge badge-warning">Pending</span>
                                                        @break
                                                    @case('cancelled')
                                                        <span class="badge badge-danger">Cancelled</span>
                                                        @break
                                                    @default
                                                        <span class="badge badge-gray">Draft</span>
                                                @endswitch
                                            </td>
                                            <td>{{ $invoice->due_date?->format('d/m') ?? '-' }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="text-center text-gray-400">No invoices found</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- ============================================
            EXPENSES TAB
            ============================================ --}}
        @if($activeTab === 'expenses')
            @php $data = $this->getExpensesData(); @endphp

            <div class="top-stats-grid">
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">Total Expenses</span>
                        <span class="stat-mini-value text-danger">Rp {{ number_format($data['total_expenses'] / 1000000, 1) }}M</span>
                    </div>
                </div>
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">Total Advances</span>
                        <span class="stat-mini-value text-warning">Rp {{ number_format($data['total_advances'] / 1000000, 1) }}M</span>
                    </div>
                </div>
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">Pending Expenses</span>
                        <span class="stat-mini-value text-warning">{{ $data['pending_expenses_count'] }} <small>({{ number_format($data['pending_expenses'] / 1000000, 1) }}M)</small></span>
                    </div>
                </div>
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">Pending Advances</span>
                        <span class="stat-mini-value text-warning">{{ $data['pending_advances_count'] }} <small>({{ number_format($data['pending_advances'] / 1000000, 1) }}M)</small></span>
                    </div>
                </div>
            </div>

            <div class="overview-grid">
                <div class="overview-col">
                    <div class="card">
                        <div class="card-body" style="padding: 0;">
                            <table class="data-table" style="font-size: 0.75rem;">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>User</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($data['expenses'] as $expense)
                                        <tr>
                                            <td>{{ $expense->submission_date?->format('d/m') ?? '-' }}</td>
                                            <td>{{ $expense->user?->name ?? '-' }}</td>
                                            <td>Rp {{ number_format($expense->paid ?? $expense->amount, 0, ',', '.') }}</td>
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
                                                    @default
                                                        <span class="badge badge-gray">{{ $expense->status }}</span>
                                                @endswitch
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="text-center text-gray-400">No expenses found</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="overview-col">
                    <div class="card">
                        <div class="card-body" style="padding: 0;">
                            <table class="data-table" style="font-size: 0.75rem;">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>User</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($data['advances'] as $advance)
                                        <tr>
                                            <td>{{ $advance->submission_date?->format('d/m') ?? '-' }}</td>
                                            <td>{{ $advance->user?->name ?? '-' }}</td>
                                            <td>Rp {{ number_format($advance->paid ?? $advance->amount, 0, ',', '.') }}</td>
                                            <td>
                                                @switch($advance->status)
                                                    @case('Pending')
                                                        <span class="badge badge-warning">Pending</span>
                                                        @break
                                                    @case('Disetujui')
                                                        <span class="badge badge-info">Approved</span>
                                                        @break
                                                    @case('Selesai')
                                                        <span class="badge badge-success">Done</span>
                                                        @break
                                                    @default
                                                        <span class="badge badge-gray">{{ $advance->status }}</span>
                                                @endswitch
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="text-center text-gray-400">No advances found</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- ============================================
            ADVANCES TAB
            ============================================ --}}
        @if($activeTab === 'advances')
            @php $data = $this->getAdvancesData(); @endphp

            <div class="top-stats-grid">
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">Total Approved</span>
                        <span class="stat-mini-value text-success">Rp {{ number_format($data['total_all_time'] / 1000000, 1) }}M</span>
                    </div>
                </div>
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">Pending</span>
                        <span class="stat-mini-value text-warning">{{ $data['total_pending_count'] }} <small>(Rp {{ number_format($data['total_pending'] / 1000000, 1) }}M)</small></span>
                    </div>
                </div>
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">Disetujui</span>
                        <span class="stat-mini-value text-info">{{ $data['count_by_status']['Disetujui'] }}</span>
                    </div>
                </div>
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">Selesai</span>
                        <span class="stat-mini-value text-success">{{ $data['count_by_status']['Selesai'] }}</span>
                    </div>
                </div>
            </div>

            <div class="overview-grid advances-grid">
                <div class="overview-col">
                    <div class="card">
                        <div class="card-body">
                            <h3 style="font-size: 0.875rem; font-weight: 600; margin-bottom: 0.75rem; color: #1f2937;">Status Overview</h3>
                            <div class="mini-stats">
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Pending</span>
                                    <span class="mini-stat-value text-warning">{{ $data['count_by_status']['Pending'] }}</span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Disetujui</span>
                                    <span class="mini-stat-value text-info">{{ $data['count_by_status']['Disetujui'] }}</span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Dibayarkan</span>
                                    <span class="mini-stat-value">{{ $data['count_by_status']['Dibayarkan'] }}</span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Selesai</span>
                                    <span class="mini-stat-value text-success">{{ $data['count_by_status']['Selesai'] }}</span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Ditolak</span>
                                    <span class="mini-stat-value text-danger">{{ $data['count_by_status']['Ditolak'] }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="overview-col">
                    <div class="card">
                        <div class="card-body">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                                <h3 style="font-size: 0.875rem; font-weight: 600; color: #1f2937; margin: 0;">
                                    All Advances
                                </h3>
                                <span class="text-gray-400" style="font-size: 0.75rem;">
                                    Showing {{ $data['all']->firstItem() ?? 0 }}-{{ $data['all']->lastItem() ?? 0 }} of {{ $data['all']->total() }}
                                </span>
                            </div>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>User</th>
                                        <th>Description</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($data['all'] as $advance)
                                        <tr>
                                            <td>{{ $advance->submission_date?->format('d/m/Y') ?? '-' }}</td>
                                            <td>{{ $advance->user?->name ?? '-' }}</td>
                                            <td style="max-width: 200px;">
                                                <span title="{{ $advance->keterangan_advance }}">{{ Str::limit($advance->keterangan_advance, 30) }}</span>
                                            </td>
                                            <td>Rp {{ number_format($advance->paid ?? $advance->amount, 0, ',', '.') }}</td>
                                            <td>
                                                @switch($advance->status)
                                                    @case('Pending')
                                                        <span class="badge badge-warning">Pending</span>
                                                        @break
                                                    @case('Disetujui')
                                                        <span class="badge badge-info">Approved</span>
                                                        @break
                                                    @case('Dibayarkan')
                                                        <span class="badge badge-success">Paid</span>
                                                        @break
                                                    @case('Selesai')
                                                        <span class="badge badge-success">Done</span>
                                                        @break
                                                    @case('Ditolak')
                                                        <span class="badge badge-danger">Rejected</span>
                                                        @break
                                                    @default
                                                        <span class="badge badge-gray">{{ $advance->status }}</span>
                                                @endswitch
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="text-center text-gray-400">No advances found</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                            <div style="margin-top: 1rem; display: flex; justify-content: center;">
                                <div class="pagination">
                                    @if($data['all']->onFirstPage())
                                        <span class="pagination-btn disabled">&laquo;</span>
                                    @else
                                        <button wire:click="previousPage" class="pagination-btn">&laquo;</button>
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

                                    @for ($i = $start; $i <= $end; $i++)
                                        @if($i == $currentPage)
                                            <span class="pagination-btn active">{{ $i }}</span>
                                        @else
                                            <button wire:click="gotoPage({{ $i }})" class="pagination-btn">{{ $i }}</button>
                                        @endif
                                    @endfor

                                    @if($data['all']->hasMorePages())
                                        <button wire:click="nextPage" class="pagination-btn">&raquo;</button>
                                    @else
                                        <span class="pagination-btn disabled">&raquo;</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- ============================================
            TRANSACTIONS TAB
            ============================================ --}}
        @if($activeTab === 'transactions')
            @php $data = $this->getTransactionsData(); @endphp

            <div class="top-stats-grid" style="grid-template-columns: repeat(3, 1fr);">
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">Total Inflow</span>
                        <span class="stat-mini-value text-success">Rp {{ number_format($data['summary']['total_in'] / 1000000, 1) }}M</span>
                    </div>
                </div>
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">Total Outflow</span>
                        <span class="stat-mini-value text-danger">Rp {{ number_format($data['summary']['total_out'] / 1000000, 1) }}M</span>
                    </div>
                </div>
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">Net</span>
                        <span class="stat-mini-value {{ $data['summary']['net'] >= 0 ? 'text-success' : 'text-danger' }}">Rp {{ number_format($data['summary']['net'] / 1000000, 1) }}M</span>
                    </div>
                </div>
            </div>

            <div class="transactions-grid">
                <div class="overview-col">
                    <div class="card">
                        <div class="card-body">
                            <h3 style="font-size: 0.875rem; font-weight: 600; margin-bottom: 0.75rem; color: #1f2937;">Payment Accounts</h3>
                            <div class="account-list">
                                @forelse($data['accounts'] as $account)
                                    <div class="account-row">
                                        <div>
                                            <div style="font-weight: 600; color: #1f2937;">{{ $account->name }}</div>
                                            @if($account->bank_name)
                                                <small style="color: #6b7280;">{{ $account->bank_name }} - {{ $account->account_number }}</small>
                                            @endif
                                        </div>
                                        <div class="text-right">
                                            <div class="account-balance">Rp {{ number_format($account->getAvailableBalance(), 0, ',', '.') }}</div>
                                        </div>
                                    </div>
                                @empty
                                    <p class="text-gray-500 text-sm">No active accounts</p>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>
                <div class="overview-col">
                    <div class="card">
                        <div class="card-body" style="padding: 0;">
                            <table class="data-table" style="font-size: 0.75rem;">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Account</th>
                                        <th>Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($data['transactions'] as $transaction)
                                        <tr>
                                            <td>{{ $transaction->transaction_date?->format('d/m') ?? '-' }}</td>
                                            <td>{{ $transaction->paymentAccount?->name ?? '-' }}</td>
                                            <td class="{{ $transaction->type === 'in' ? 'text-success' : 'text-danger' }}">
                                                {{ $transaction->type === 'in' ? '+' : '-' }}Rp {{ number_format($transaction->amount, 0, ',', '.') }}
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="text-center text-gray-400">No transactions found</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- ============================================
            PIPELINE TAB
            ============================================ --}}
        @if($activeTab === 'pipeline')
            @php $data = $this->getPipelineData(); @endphp

            <div class="top-stats-grid">
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">Total Pipeline</span>
                        <span class="stat-mini-value text-primary">Rp {{ number_format($data['total_pipeline'] / 1000000, 1) }}M</span>
                    </div>
                </div>
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">Negosiasi</span>
                        <span class="stat-mini-value text-warning">{{ $data['stages']['Negosiasi']['count'] }} <small>({{ number_format($data['stages']['Negosiasi']['value'] / 1000000, 1) }}M)</small></span>
                    </div>
                </div>
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">Menunggu Bayar</span>
                        <span class="stat-mini-value text-info">{{ $data['stages']['Menunggu Pembayaran']['count'] }} <small>({{ number_format($data['stages']['Menunggu Pembayaran']['value'] / 1000000, 1) }}M)</small></span>
                    </div>
                </div>
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">In Project</span>
                        <span class="stat-mini-value text-success">{{ $data['stages']['Dalam Tahap Project']['count'] }} <small>({{ number_format($data['stages']['Dalam Tahap Project']['value'] / 1000000, 1) }}M)</small></span>
                    </div>
                </div>
            </div>

            <div class="overview-grid">
                <div class="overview-col">
                    <div class="card">
                        <div class="card-body">
                            <h3 style="font-size: 0.875rem; font-weight: 600; margin-bottom: 0.75rem; color: #1f2937;">Client Metrics</h3>
                            <div class="mini-stats">
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Total Clients</span>
                                    <span class="mini-stat-value">{{ $data['clients'] }}</span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Total Revenue</span>
                                    <span class="mini-stat-value text-success">Rp {{ number_format($data['revenue'] / 1000000, 1) }}M</span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Avg Client Value</span>
                                    <span class="mini-stat-value">Rp {{ number_format($data['avg_client'] / 1000000, 1) }}M</span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Quotations</span>
                                    <span class="mini-stat-value">{{ $data['pending_quotations'] + $data['approved_quotations'] }} <small>({{ $data['pending_quotations'] }} pending)</small></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- ============================================
            ASSETS TAB
            ============================================ --}}
        @if($activeTab === 'assets')
            @php $data = $this->getAssetsData(); @endphp

            <div class="top-stats-grid">
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">Total Assets</span>
                        <span class="stat-mini-value text-primary">{{ $data['total'] }}</span>
                    </div>
                </div>
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">Total Value</span>
                        <span class="stat-mini-value text-success">Rp {{ number_format($data['total_value'] / 1000000, 1) }}M</span>
                    </div>
                </div>
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">Baik</span>
                        <span class="stat-mini-value text-success">{{ $data['by_condition']['Baik'] }}</span>
                    </div>
                </div>
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">Rusak</span>
                        <span class="stat-mini-value text-danger">{{ $data['by_condition']['Rusak Ringan'] + $data['by_condition']['Rusak Berat'] }}</span>
                    </div>
                </div>
            </div>

            <div class="overview-grid">
                <div class="overview-col">
                    <div class="card">
                        <div class="card-body">
                            <h3 style="font-size: 0.875rem; font-weight: 600; margin-bottom: 0.75rem; color: #1f2937;">Condition Overview</h3>
                            <div class="mini-stats">
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Baik</span>
                                    <span class="mini-stat-value text-success">{{ $data['by_condition']['Baik'] }}</span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Rusak Ringan</span>
                                    <span class="mini-stat-value text-warning">{{ $data['by_condition']['Rusak Ringan'] }}</span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Rusak Berat</span>
                                    <span class="mini-stat-value text-danger">{{ $data['by_condition']['Rusak Berat'] }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="overview-col">
                    <div class="card">
                        <div class="card-body">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                                <h3 style="font-size: 0.875rem; font-weight: 600; color: #1f2937; margin: 0;">All Assets</h3>
                                <span class="text-gray-400" style="font-size: 0.75rem;">
                                    Showing {{ $data['all']->firstItem() ?? 0 }}-{{ $data['all']->lastItem() ?? 0 }} of {{ $data['all']->total() }}
                                </span>
                            </div>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Purchase Date</th>
                                        <th>Condition</th>
                                        <th>Value</th>
                                        <th>Responsible</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($data['all'] as $asset)
                                        <tr>
                                            <td>{{ $asset->nama_barang }}</td>
                                            <td>{{ $asset->tanggal_pembelian?->format('d/m/Y') ?? '-' }}</td>
                                            <td>
                                                @switch($asset->kondisi)
                                                    @case('Baik')
                                                        <span class="badge badge-success">Baik</span>
                                                        @break
                                                    @case('Rusak Ringan')
                                                        <span class="badge badge-warning">Rusak Ringan</span>
                                                        @break
                                                    @case('Rusak Berat')
                                                        <span class="badge badge-danger">Rusak Berat</span>
                                                        @break
                                                    @default
                                                        <span class="badge badge-gray">{{ $asset->kondisi }}</span>
                                                @endswitch
                                            </td>
                                            <td>Rp {{ number_format($asset->harga_barang, 0, ',', '.') }}</td>
                                            <td>{{ $asset->user?->name ?? '-' }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="text-center text-gray-400">No assets found</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                            <div style="margin-top: 1rem; display: flex; justify-content: center;">
                                <div class="pagination">
                                    @if($data['all']->onFirstPage())
                                        <span class="pagination-btn disabled">&laquo;</span>
                                    @else
                                        <button wire:click="previousPage" class="pagination-btn">&laquo;</button>
                                    @endif
                                    @php
                                        $currentPage = $data['all']->currentPage();
                                        $lastPage = $data['all']->lastPage();
                                        $start = max(1, $currentPage - 2);
                                        $end = min($lastPage, $currentPage + 2);
                                    @endphp
                                    @for ($i = $start; $i <= $end; $i++)
                                        @if($i == $currentPage)
                                            <span class="pagination-btn active">{{ $i }}</span>
                                        @else
                                            <button wire:click="gotoPage({{ $i }})" class="pagination-btn">{{ $i }}</button>
                                        @endif
                                    @endfor
                                    @if($data['all']->hasMorePages())
                                        <button wire:click="nextPage" class="pagination-btn">&raquo;</button>
                                    @else
                                        <span class="pagination-btn disabled">&raquo;</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- ============================================
            PROJECTS TAB
            ============================================ --}}
        @if($activeTab === 'projects')
            @php $data = $this->getProjectsData(); @endphp

            <div class="top-stats-grid" style="grid-template-columns: repeat(3, 1fr); max-width: 900px; margin-left: auto; margin-right: auto;">
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">Total Projects</span>
                        <span class="stat-mini-value text-primary">{{ $data['total'] }}</span>
                    </div>
                </div>
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">Active</span>
                        <span class="stat-mini-value text-warning">{{ $data['active'] }}</span>
                    </div>
                </div>
                <div class="card stat-mini">
                    <div class="stat-mini-content">
                        <span class="stat-mini-label">Completed</span>
                        <span class="stat-mini-value text-success">{{ $data['completed'] }}</span>
                    </div>
                </div>
            </div>

            <div class="projects-grid">
                <div class="overview-col">
                    <div class="card">
                        <div class="card-body">
                            <h3 style="font-size: 0.875rem; font-weight: 600; margin-bottom: 0.75rem; color: #1f2937;">Status Overview</h3>
                            <div class="mini-stats">
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Not Started</span>
                                    <span class="mini-stat-value">{{ $data['by_status']['Not Started'] }}</span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">On Progress</span>
                                    <span class="mini-stat-value text-warning">{{ $data['by_status']['On Progress'] }}</span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Completed</span>
                                    <span class="mini-stat-value text-success">{{ $data['by_status']['Completed'] }}</span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Cancelled</span>
                                    <span class="mini-stat-value text-danger">{{ $data['by_status']['Cancelled'] }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="overview-col">
                    <div class="card">
                        <div class="card-body">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                                <h3 style="font-size: 0.875rem; font-weight: 600; color: #1f2937; margin: 0;">All Projects</h3>
                                <span class="text-gray-400" style="font-size: 0.75rem;">
                                    Showing {{ $data['all']->firstItem() ?? 0 }}-{{ $data['all']->lastItem() ?? 0 }} of {{ $data['all']->total() }}
                                </span>
                            </div>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Project Name</th>
                                        <th>Client</th>
                                        <th>Start Date</th>
                                        <th>End Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($data['all'] as $project)
                                        <tr>
                                            <td>{{ $project->nama_project }}</td>
                                            <td>{{ $project->Deal?->nama_perusahaan ?? '-' }}</td>
                                            <td>{{ $project->timeline_mulai?->format('d/m/Y') ?? '-' }}</td>
                                            <td>{{ $project->timeline_selesai?->format('d/m/Y') ?? '-' }}</td>
                                            <td>
                                                @switch($project->status)
                                                    @case('Not Started')
                                                        <span class="badge badge-gray">Not Started</span>
                                                        @break
                                                    @case('On Progress')
                                                        <span class="badge badge-warning">On Progress</span>
                                                        @break
                                                    @case('Completed')
                                                        <span class="badge badge-success">Completed</span>
                                                        @break
                                                    @case('Cancelled')
                                                        <span class="badge badge-danger">Cancelled</span>
                                                        @break
                                                    @default
                                                        <span class="badge badge-info">{{ $project->status }}</span>
                                                @endswitch
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="text-center text-gray-400">No projects found</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                            <div style="margin-top: 1rem; display: flex; justify-content: center;">
                                <div class="pagination">
                                    @if($data['all']->onFirstPage())
                                        <span class="pagination-btn disabled">&laquo;</span>
                                    @else
                                        <button wire:click="previousPage" class="pagination-btn">&laquo;</button>
                                    @endif
                                    @php
                                        $currentPage = $data['all']->currentPage();
                                        $lastPage = $data['all']->lastPage();
                                        $start = max(1, $currentPage - 2);
                                        $end = min($lastPage, $currentPage + 2);
                                    @endphp
                                    @for ($i = $start; $i <= $end; $i++)
                                        @if($i == $currentPage)
                                            <span class="pagination-btn active">{{ $i }}</span>
                                        @else
                                            <button wire:click="gotoPage({{ $i }})" class="pagination-btn">{{ $i }}</button>
                                        @endif
                                    @endfor
                                    @if($data['all']->hasMorePages())
                                        <button wire:click="nextPage" class="pagination-btn">&raquo;</button>
                                    @else
                                        <span class="pagination-btn disabled">&raquo;</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @elseif($activeTab === 'advance_analysis')
            @php $data = $this->getAdvanceAnalysisData(); @endphp

            {{-- Summary Cards --}}
            <div class="top-stats-grid">
                <div class="card">
                    <div class="card-body">
                        <div class="mini-stat">
                            <span class="mini-stat-label">Total Paid</span>
                            <span class="stat-value text-primary">Rp {{ number_format($data['summary']['total_paid'] / 1000000, 1) }}M</span>
                        </div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-body">
                        <div class="mini-stat">
                            <span class="mini-stat-label">Total Realisasi</span>
                            <span class="stat-value text-success">Rp {{ number_format($data['summary']['total_realisasi'] / 1000000, 1) }}M</span>
                        </div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-body">
                        <div class="mini-stat">
                            <span class="mini-stat-label">Project Count</span>
                            <span class="stat-value">{{ $data['summary']['project_count'] }}</span>
                        </div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-body">
                        <div class="mini-stat">
                            <span class="mini-stat-label">Pending Approval</span>
                            <span class="stat-value text-warning">{{ $data['summary']['pending_count'] }} ({{ number_format($data['summary']['pending_amount'] / 1000000, 1) }}M)</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Content Grid --}}
            <div class="grid-2" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                {{-- Left: By Project --}}
                <div class="card">
                    <div class="card-body">
                        <h3 style="font-size: 1rem; font-weight: 600; margin-bottom: 1rem;">Top Project by Advance</h3>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Project</th>
                                    <th>Client</th>
                                    <th style="text-align: right;">Paid</th>
                                    <th style="text-align: right;">Realisasi</th>
                                    <th style="text-align: right;">Sisa</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($data['by_project'] as $project)
                                    <tr>
                                        <td>{{ $project['project_name'] }}</td>
                                        <td>{{ $project['client_name'] }}</td>
                                        <td style="text-align: right;">Rp {{ number_format($project['total_paid'], 0, ',', '.') }}</td>
                                        <td style="text-align: right;">Rp {{ number_format($project['total_realisasi'], 0, ',', '.') }}</td>
                                        <td style="text-align: right;">
                                            @if($project['sisa'] < 0)
                                                <span class="text-danger">Rp {{ number_format(abs($project['sisa']), 0, ',', '.') }}</span>
                                            @else
                                                <span class="text-success">Rp {{ number_format($project['sisa'], 0, ',', '.') }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-gray-400">No project data</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Right: Top Items --}}
                <div class="card">
                    <div class="card-body">
                        <h3 style="font-size: 1rem; font-weight: 600; margin-bottom: 1rem;">Top Item Realisasi</h3>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Item Name</th>
                                    <th style="text-align: center;">Qty</th>
                                    <th style="text-align: right;">Total</th>
                                    <th style="text-align: right;">Avg</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($data['top_items'] as $item)
                                    <tr>
                                        <td>{{ $item['name'] }}</td>
                                        <td style="text-align: center;">{{ $item['total_qty'] }}</td>
                                        <td style="text-align: right;">Rp {{ number_format($item['total_amount'], 0, ',', '.') }}</td>
                                        <td style="text-align: right;">Rp {{ number_format($item['avg_price'], 0, ',', '.') }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                    <td colspan="4" class="text-center text-gray-400">No items found</td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif

    </div>

</x-filament-panels::page>
