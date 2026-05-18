<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="application-name" content="{{ config('app.name') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#8b5cf6">

    <title>{{ config('app.name') }}</title>

    {{-- PWA --}}
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="ERP Krafty">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/svg+xml" href="/icons/icon-512x512.svg">
    <link rel="apple-touch-icon" href="/icons/icon-512x512.svg">

    <style>
        [x-cloak] { display: none !important; }
    </style>

    @filamentStyles
    @vite('resources/css/app.css')
    @vite('resources/css/clickup.css')
    @vite('resources/css/pwa.css')

    {{-- Dark mode initialization --}}
    <script>
        if (document.cookie.includes('dark_mode=1')) {
            document.documentElement.classList.add('dark');
        }
    </script>
</head>
<body class="antialiased">

{{-- Command Palette --}}
<x-clickup.command-palette>
    <div class="cu-command-input-wrap">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
        </svg>
        <input type="text" id="command-input" class="cu-command-input" placeholder="Type a command or search..." autocomplete="off">
        <kbd>ESC</kbd>
    </div>
    <div class="cu-command-results" id="command-results">
        @include('clickup-commands')
    </div>
</x-clickup.command-palette>

{{-- Main Layout --}}
<div class="cu-layout">
    {{-- Sidebar --}}
    <x-clickup.sidebar>
        @include('clickup-sidebar')
    </x-clickup.sidebar>

    {{-- Main Content --}}
    <main class="cu-main">
        {{-- Top Bar --}}
        <header class="cu-topbar">
            <div class="cu-topbar-left">
                <button class="cu-mobile-menu-btn" onclick="toggleSidebar()">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                </button>
                <h1 class="cu-page-title">{{ $title ?? 'Dashboard' }}</h1>
            </div>
            <div class="cu-topbar-right">
                <button class="cu-topbar-btn" onclick="openCommandPalette()">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </button>
                <button class="cu-topbar-btn">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                    </svg>
                </button>
                <div class="cu-user-menu">
                    <img src="{{ auth()->user()->getFilamentAvatarUrl() ?? 'https://ui-avatars.com/api/?name=' . urlencode(auth()->user()->name) }}"
                         alt="{{ auth()->user()->name }}"
                         class="cu-avatar">
                </div>
            </div>
        </header>

        {{-- Content --}}
        <div class="cu-content">
            {{ $slot }}
        </div>
    </main>
</div>

{{-- Notification Bell --}}
<livewire:notification-bell />
@livewire('database-notifications')

@filamentScripts
@vite('resources/js/app.js')
@vite('resources/js/pwa.js')

<script>
    // Command Palette
    function openCommandPalette() {
        document.getElementById('command-palette').classList.add('active');
        document.getElementById('command-input').focus();
    }

    function closeCommandPalette(e) {
        if (e && e.target !== e.currentTarget) return;
        document.getElementById('command-palette').classList.remove('active');
    }

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
            e.preventDefault();
            openCommandPalette();
        }
        if (e.key === 'Escape') {
            closeCommandPalette();
        }
    });

    // Search in command palette
    document.getElementById('command-input')?.addEventListener('input', function(e) {
        const query = e.target.value.toLowerCase();
        document.querySelectorAll('.cu-command-item').forEach(item => {
            const label = (item.dataset.label || '').toLowerCase();
            item.style.display = label.includes(query) ? 'flex' : 'none';
        });
    });

    // Sidebar toggle for mobile
    function toggleSidebar() {
        document.getElementById('app-sidebar').classList.toggle('open');
    }

    // Dark mode
    function toggleDarkMode() {
        const isDark = document.documentElement.classList.toggle('dark');
        document.cookie = `dark_mode=${isDark ? 1 : 0}; path=/; max-age=${60*60*24*365}`;
    }

    // PWA Install
    let deferredPrompt;
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
    });

    async function installPWA() {
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        const { outcome } = await deferredPrompt.userChoice;
        deferredPrompt = null;
    }
</script>

</body>
</html>