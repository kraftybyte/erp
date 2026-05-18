<?php

namespace App\View\Components;

use Illuminate\View\Component;

class PwaScripts extends Component
{
    public function render(): \Illuminate\Contracts\View\View|string
    {
        return <<<'HTML'
        <script>
            // PWA Installer Prompt
            let deferredPrompt;
            let isInstalled = false;

            window.addEventListener('beforeinstallprompt', (e) => {
                e.preventDefault();
                deferredPrompt = e;

                // Show install banner if not already installed
                if (!isInstalled && !localStorage.getItem('pwaInstallDismissed')) {
                    showInstallBanner();
                }
            });

            window.addEventListener('appinstalled', () => {
                isInstalled = true;
                hideInstallBanner();
                localStorage.setItem('pwaInstalled', 'true');
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

                if (outcome === 'accepted') {
                    console.log('[PWA] User accepted install');
                } else {
                    console.log('[PWA] User dismissed install');
                }

                deferredPrompt = null;
                hideInstallBanner();
            }

            function dismissInstallBanner() {
                const banner = document.getElementById('pwa-install-banner');
                if (banner) banner.remove();
                localStorage.setItem('pwaInstallDismissed', Date.now().toString());
            }

            function hideInstallBanner() {
                const banner = document.getElementById('pwa-install-banner');
                if (banner) banner.remove();
            }

            // Check if already installed
            if (window.matchMedia('(display-mode: standalone)').matches) {
                isInstalled = true;
            }

            // Check if dismissed recently (24 hours)
            const dismissed = localStorage.getItem('pwaInstallDismissed');
            if (dismissed && Date.now() - parseInt(dismissed) < 86400000) {
                // Less than 24 hours ago, don't show
            }
        </script>
        HTML;
    }
}