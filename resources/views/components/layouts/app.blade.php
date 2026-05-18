<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">

        <meta name="application-name" content="{{ config('app.name') }}">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

        <title>{{ config('app.name') }}</title>

        {{-- PWA Meta Tags --}}
        <meta name="theme-color" content="#6366f1">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="ERP Krafty">
        <link rel="manifest" href="/manifest.json">

        {{-- Icons --}}
        <link rel="icon" type="image/svg+xml" href="/icons/icon-512x512.svg">
        <link rel="apple-touch-icon" href="/icons/icon-512x512.svg">

        {{-- Search Engine Indexing Control --}}
        @php
            $generalSettings = app(\App\Settings\GeneralSettings::class);
            $allowIndexing = $generalSettings->search_engine_indexing ?? false;
        @endphp

        @if(!$allowIndexing)
            <meta name="robots" content="noindex, nofollow">
        @endif

        <style>
            [x-cloak] {
                display: none !important;
            }
        </style>

        @filamentStyles
        @vite('resources/css/app.css')
        @vite('resources/css/pwa.css')
    </head>

    <body class="antialiased">
        <div
    x-data="pageTransition"
    x-init="init"
    x-show="show"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-y-2"
    x-transition:enter-end="opacity-100 translate-y-0"
    class="min-h-screen"

      x-data="{ open: true }"
  x-show="open"
  x-transition:enter="transition ease-out duration-300"
  x-transition:enter-start="opacity-0 scale-95"
  x-transition:enter-end="opacity-100 scale-100"
>
    {{ $slot }}
</div>

        <livewire:notification-bell />
        @livewire('database-notifications')

        @filamentScripts
        @vite('resources/js/app.js')
        @vite('resources/js/pwa.js')

        {{-- PWA Install Prompt --}}
        <script>
            let deferredPrompt;
            window.addEventListener('beforeinstallprompt', (e) => {
                e.preventDefault();
                deferredPrompt = e;
                if (!localStorage.getItem('pwaInstallDismissed')) {
                    setTimeout(() => showInstallBanner(), 3000);
                }
            });

            function showInstallBanner() {
                const banner = document.createElement('div');
                banner.id = 'pwa-install-banner';
                banner.className = 'pwa-install-banner';
                banner.innerHTML = `
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <span style="font-size: 24px;">📱</span>
                        <div>
                            <strong style="font-size: 14px;">Pasang ERP Krafty</strong>
                            <p style="font-size: 12px; opacity: 0.9; margin: 0;">Akses cepat dari homescreen</p>
                        </div>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button onclick="installPWA()" style="background: white; color: #6366f1; border: none; padding: 8px 16px; border-radius: 8px; font-weight: 600; cursor: pointer;">Pasang</button>
                        <button onclick="dismissInstallBanner()" class="close-btn">✕</button>
                    </div>
                `;
                document.body.appendChild(banner);
            }

            async function installPWA() {
                if (!deferredPrompt) return;
                deferredPrompt.prompt();
                const { outcome } = await deferredPrompt.userChoice;
                deferredPrompt = null;
                dismissInstallBanner();
            }

            function dismissInstallBanner() {
                const banner = document.getElementById('pwa-install-banner');
                if (banner) banner.remove();
                localStorage.setItem('pwaInstallDismissed', Date.now().toString());
            }
        </script>
    </body>
</html>