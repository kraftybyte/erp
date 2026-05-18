<?php

namespace App\View\Components\Clickup;

use Illuminate\View\Component;

class ThemeToggle extends Component
{
    public bool $isDark = false;

    public function __construct()
    {
        $this->isDark = session()->get('dark_mode', false) ||
            (request()->hasCookie('dark_mode') && request()->cookie('dark_mode') === '1');
    }

    public function render(): \Illuminate\Contracts\View\View|string
    {
        return <<<'HTML'
        <div class="cu-theme-toggle" onclick="toggleDarkMode()">
            <span class="cu-theme-toggle-label">
                <svg style="width: 16px; height: 16px; margin-right: 8px; vertical-align: middle;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
                </svg>
                Dark Mode
            </span>
            <div class="cu-toggle-switch {{ $isDark ? 'active' : '' }}" id="theme-toggle"></div>
        </div>

        <script>
            function toggleDarkMode() {
                const isDark = document.documentElement.classList.toggle('dark');
                document.cookie = `dark_mode=${isDark ? 1 : 0}; path=/; max-age=${60*60*24*365}`;
                document.getElementById('theme-toggle')?.classList.toggle('active', isDark);
            }

            // Initialize on load
            document.addEventListener('DOMContentLoaded', function() {
                const isDark = document.cookie.includes('dark_mode=1');
                if (isDark) {
                    document.documentElement.classList.add('dark');
                }
            });
        </script>
        HTML;
    }
}