{{-- ClickUp Sidebar Content --}}

<!-- Logo -->
<div class="cu-sidebar-header">
    <a href="/admin" class="cu-logo">
        <div class="cu-logo-icon">
            <svg width="32" height="32" viewBox="0 0 512 512" fill="none">
                <rect width="512" height="512" rx="100" fill="url(#grad)"/>
                <text x="256" y="300" font-family="Arial" font-size="200" font-weight="bold" fill="white" text-anchor="middle">K</text>
                <defs>
                    <linearGradient id="grad" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" style="stop-color:#8b5cf6"/>
                        <stop offset="100%" style="stop-color:#6366f1"/>
                    </linearGradient>
                </defs>
            </svg>
        </div>
        <span class="cu-logo-text">Krafty ERP</span>
    </a>
</div>

<!-- Search -->
<div class="cu-sidebar-search">
    <button class="cu-search-btn" onclick="openCommandPalette()">
        <svg class="cu-icon-sm" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
        </svg>
        <span>Search...</span>
        <kbd>⌘K</kbd>
    </button>
</div>

<!-- Navigation -->
<nav class="cu-sidebar-nav">
    {{-- Main --}}
    <a href="/admin" class="cu-nav-item {{ request()->is('admin') && !request()->segment(2) ? 'active' : '' }}">
        <span class="cu-nav-icon">@include('clickup-icons::home')</span>
        <span class="cu-nav-text">Dashboard</span>
    </a>
    <a href="/admin/inbox" class="cu-nav-item {{ request()->is('admin/inbox') ? 'active' : '' }}">
        <span class="cu-nav-icon">@include('clickup-icons::inbox')</span>
        <span class="cu-nav-text">Inbox</span>
        <span class="cu-nav-badge">3</span>
    </a>

    {{-- Workspace --}}
    <div class="cu-nav-group">
        <span class="cu-nav-label">Workspace</span>
        <a href="/admin/my-tasks" class="cu-nav-item {{ request()->is('admin/my-tasks') ? 'active' : '' }}">
            <span class="cu-nav-icon">@include('clickup-icons::check')</span>
            <span class="cu-nav-text">My Tasks</span>
            <span class="cu-nav-badge">5</span>
        </a>
        <a href="/admin/projects" class="cu-nav-item {{ request()->is('admin/projects') ? 'active' : '' }}">
            <span class="cu-nav-icon">@include('clickup-icons::folder')</span>
            <span class="cu-nav-text">Projects</span>
        </a>
        <a href="/admin/clients" class="cu-nav-item {{ request()->is('admin/clients') ? 'active' : '' }}">
            <span class="cu-nav-icon">@include('clickup-icons::users')</span>
            <span class="cu-nav-text">Clients</span>
        </a>
        <a href="/admin/leads" class="cu-nav-item {{ request()->is('admin/leads') ? 'active' : '' }}">
            <span class="cu-nav-icon">@include('clickup-icons::target')</span>
            <span class="cu-nav-text">Leads</span>
            <span class="cu-nav-badge">12</span>
        </a>
    </div>

    {{-- Finance --}}
    <div class="cu-nav-group">
        <span class="cu-nav-label">Finance</span>
        <a href="/admin/expenses" class="cu-nav-item {{ request()->is('admin/expenses') ? 'active' : '' }}">
            <span class="cu-nav-icon">@include('clickup-icons::dollar')</span>
            <span class="cu-nav-text">Expenses</span>
        </a>
        <a href="/admin/advances" class="cu-nav-item {{ request()->is('admin/advances') ? 'active' : '' }}">
            <span class="cu-nav-icon">@include('clickup-icons::card')</span>
            <span class="cu-nav-text">Advances</span>
        </a>
        <a href="/admin/budgets" class="cu-nav-item {{ request()->is('admin/budgets') ? 'active' : '' }}">
            <span class="cu-nav-icon">@include('clickup-icons::pie')</span>
            <span class="cu-nav-text">Budgets</span>
        </a>
    </div>

    {{-- HR --}}
    <div class="cu-nav-group">
        <span class="cu-nav-label">HR</span>
        <a href="/admin/employees" class="cu-nav-item {{ request()->is('admin/employees') ? 'active' : '' }}">
            <span class="cu-nav-icon">@include('clickup-icons::user')</span>
            <span class="cu-nav-text">Employees</span>
        </a>
        <a href="/admin/absences" class="cu-nav-item {{ request()->is('admin/absences') ? 'active' : '' }}">
            <span class="cu-nav-icon">@include('clickup-icons::calendar-x')</span>
            <span class="cu-nav-text">Absences</span>
            <span class="cu-nav-badge">4</span>
        </a>
        <a href="/admin/events" class="cu-nav-item {{ request()->is('admin/events') ? 'active' : '' }}">
            <span class="cu-nav-icon">@include('clickup-icons::calendar')</span>
            <span class="cu-nav-text">Calendar</span>
        </a>
    </div>

    {{-- Tools --}}
    <div class="cu-nav-group">
        <span class="cu-nav-label">Tools</span>
        <a href="/admin/financial-analytics" class="cu-nav-item {{ request()->is('admin/financial-analytics') ? 'active' : '' }}">
            <span class="cu-nav-icon">@include('clickup-icons::chart')</span>
            <span class="cu-nav-text">Analytics</span>
        </a>
        <a href="/admin/social-accounts" class="cu-nav-item {{ request()->is('admin/social-accounts') ? 'active' : '' }}">
            <span class="cu-nav-icon">@include('clickup-icons::share')</span>
            <span class="cu-nav-text">Social Accounts</span>
        </a>
    </div>
</nav>

<!-- Footer -->
<div class="cu-sidebar-footer">
    <div class="cu-theme-toggle" onclick="toggleDarkMode()">
        <span class="cu-theme-toggle-label">
            <svg style="width: 16px; height: 16px; margin-right: 8px; vertical-align: middle;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
            </svg>
            Dark Mode
        </span>
        <div class="cu-toggle-switch" id="theme-toggle"></div>
    </div>

    <div class="cu-user">
        <img src="{{ auth()->user()->getFilamentAvatarUrl() ?? 'https://ui-avatars.com/api/?name=' . urlencode(auth()->user()->name) }}"
             alt="{{ auth()->user()->name }}"
             class="cu-user-avatar">
        <div class="cu-user-info">
            <span class="cu-user-name">{{ auth()->user()->name }}</span>
            <span class="cu-user-role">{{ auth()->user()->roles->first()?->name ?? 'User' }}</span>
        </div>
    </div>
</div>