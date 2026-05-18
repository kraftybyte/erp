<?php

namespace App\View\Components\Clickup;

use Illuminate\View\Component;
use Illuminate\Support\Facades\Route;

class Sidebar extends Component
{
    public array $navigation = [];
    public array $favorites = [];

    public function __construct()
    {
        $this->navigation = $this->getNavigation();
        $this->favorites = $this->getFavorites();
    }

    protected function getNavigation(): array
    {
        return [
            'main' => [
                ['label' => 'Dashboard', 'icon' => 'home', 'url' => '/admin', 'badge' => null],
                ['label' => 'Inbox', 'icon' => 'inbox', 'url' => '/admin/inbox', 'badge' => 3],
            ],
            'workspace' => [
                'label' => 'Workspace',
                'items' => [
                    ['label' => 'My Tasks', 'icon' => 'check-square', 'url' => '/admin/my-tasks', 'badge' => 5],
                    ['label' => 'Projects', 'icon' => 'folder', 'url' => '/admin/projects', 'badge' => null],
                    ['label' => 'Clients', 'icon' => 'users', 'url' => '/admin/clients', 'badge' => null],
                    ['label' => 'Leads', 'icon' => 'target', 'url' => '/admin/leads', 'badge' => 12],
                ],
            ],
            'tools' => [
                'label' => 'Tools',
                'items' => [
                    ['label' => 'Calendar', 'icon' => 'calendar', 'url' => '/admin/events', 'badge' => null],
                    ['label' => 'Analytics', 'icon' => 'bar-chart', 'url' => '/admin/financial-analytics', 'badge' => null],
                ],
            ],
            'finance' => [
                'label' => 'Finance',
                'items' => [
                    ['label' => 'Expenses', 'icon' => 'dollar-sign', 'url' => '/admin/expenses', 'badge' => null],
                    ['label' => 'Advances', 'icon' => 'credit-card', 'url' => '/admin/advances', 'badge' => null],
                    ['label' => 'Budgets', 'icon' => 'pie-chart', 'url' => '/admin/budgets', 'badge' => null],
                ],
            ],
            'hr' => [
                'label' => 'HR',
                'items' => [
                    ['label' => 'Employees', 'icon' => 'user', 'url' => '/admin/employees', 'badge' => null],
                    ['label' => 'Absences', 'icon' => 'calendar-x', 'url' => '/admin/absences', 'badge' => 4],
                    ['label' => 'Announcements', 'icon' => 'megaphone', 'url' => '/admin/announcements', 'badge' => null],
                ],
            ],
            'management' => [
                'label' => 'Management',
                'items' => [
                    ['label' => 'Assets', 'icon' => 'package', 'url' => '/admin/assets', 'badge' => null],
                    ['label' => 'Purchase Requests', 'icon' => 'shopping-cart', 'url' => '/admin/purchase-requests', 'badge' => 2],
                ],
            ],
        ];
    }

    protected function getFavorites(): array
    {
        return [
            ['label' => 'Quick Create', 'icon' => 'plus-circle', 'url' => '#', 'color' => 'primary'],
            ['label' => 'Time Tracking', 'icon' => 'clock', 'url' => '#', 'color' => 'success'],
        ];
    }

    public function isActive(string $url): bool
    {
        return request()->is(ltrim($url, '/'));
    }

    public function render(): \Illuminate\Contracts\View\View|string
    {
        return <<<'HTML'
        <aside class="cu-sidebar">
            <!-- Logo -->
            <div class="cu-sidebar-header">
                <a href="/admin" class="cu-logo">
                    <div class="cu-logo-icon">
                        <svg width="24" height="24" viewBox="0 0 512 512" fill="none">
                            <rect width="512" height="512" rx="100" fill="url(#grad)"/>
                            <text x="256" y="300" font-family="Arial" font-size="200" font-weight="bold" fill="white" text-anchor="middle">K</text>
                            <defs>
                                <linearGradient id="grad" x1="0%" y1="0%" x2="100%" y2="100%">
                                    <stop offset="0%" style="stop-color:#6366f1"/>
                                    <stop offset="100%" style="stop-color:#8b5cf6"/>
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
                @foreach($navigation as $section)
                    @if(isset($section['items']))
                        <div class="cu-nav-group">
                            <span class="cu-nav-label">{{ $section['label'] }}</span>
                            @foreach($section['items'] as $item)
                                <a href="{{ $item['url'] }}"
                                   class="cu-nav-item {{ $isActive($item['url']) ? 'active' : '' }}">
                                    <span class="cu-nav-icon">{!! $this->getIcon($item['icon']) !!}</span>
                                    <span class="cu-nav-text">{{ $item['label'] }}</span>
                                    @if($item['badge'])
                                        <span class="cu-nav-badge">{{ $item['badge'] }}</span>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    @else
                        <a href="{{ $section['url'] }}"
                           class="cu-nav-item {{ $isActive($section['url']) ? 'active' : '' }}">
                            <span class="cu-nav-icon">{!! $this->getIcon($section['icon']) !!}</span>
                            <span class="cu-nav-text">{{ $section['label'] }}</span>
                            @if($section['badge'])
                                <span class="cu-nav-badge">{{ $section['badge'] }}</span>
                            @endif
                        </a>
                    @endif
                @endforeach
            </nav>

            <!-- Favorites -->
            <div class="cu-sidebar-favorites">
                <span class="cu-fav-label">Quick Access</span>
                @foreach($favorites as $fav)
                    <a href="{{ $fav['url'] }}" class="cu-fav-item" style="--fav-color: {{ $fav['color'] }}">
                        <span class="cu-fav-dot" style="background: var(--fav-color)"></span>
                        {{ $fav['label'] }}
                    </a>
                @endforeach
            </div>

            <!-- User -->
            <div class="cu-sidebar-footer">
                <x-clickup.theme-toggle />
                <div class="cu-user">
                    <img src="{{ auth()->user()->getFilamentAvatarUrl() ?? 'https://ui-avatars.com/api/?name=' . urlencode(auth()->user()->name) }}"
                         alt="{{ auth()->user()->name }}"
                         class="cu-user-avatar">
                    <div class="cu-user-info">
                        <span class="cu-user-name">{{ auth()->user()->name }}</span>
                        <span class="cu-user-role">{{ auth()->user()->roles->first()?->name ?? 'User' }}</span>
                    </div>
                    <button class="cu-user-menu">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </aside>
        HTML;
    }

    protected function getIcon(string $name): string
    {
        $icons = [
            'home' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>',
            'inbox' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path></svg>',
            'check-square' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>',
            'folder' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path></svg>',
            'users' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>',
            'target' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path></svg>',
            'calendar' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>',
            'bar-chart' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>',
            'dollar-sign' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>',
            'credit-card' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path></svg>',
            'pie-chart' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z"></path></svg>',
            'user' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>',
            'calendar-x' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path></svg>',
            'megaphone' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-2.147a1.75 1.75 0 01-.592-3.417V5.882a1.75 1.75 0 01.592-3.417l2.147-2.147a1.75 1.75 0 013.417.592z"></path></svg>',
            'package' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>',
            'shopping-cart' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>',
            'plus-circle' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>',
            'clock' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>',
        ];

        return $icons[$name] ?? '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke-width="2"/></svg>';
    }
}