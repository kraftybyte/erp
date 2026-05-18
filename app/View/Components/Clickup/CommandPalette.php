<?php

namespace App\View\Components\Clickup;

use Illuminate\View\Component;

class CommandPalette extends Component
{
    public array $commands = [];

    public function __construct()
    {
        $this->commands = $this->getCommands();
    }

    protected function getCommands(): array
    {
        return [
            'navigation' => [
                ['label' => 'Dashboard', 'desc' => 'Go to dashboard', 'url' => '/admin', 'icon' => 'home'],
                ['label' => 'Projects', 'desc' => 'View all projects', 'url' => '/admin/projects', 'icon' => 'folder'],
                ['label' => 'Clients', 'desc' => 'Manage clients', 'url' => '/admin/clients', 'icon' => 'users'],
                ['label' => 'Expenses', 'desc' => 'View expenses', 'url' => '/admin/expenses', 'icon' => 'dollar'],
                ['label' => 'Calendar', 'desc' => 'View calendar', 'url' => '/admin/events', 'icon' => 'calendar'],
            ],
            'actions' => [
                ['label' => 'Create Task', 'desc' => 'Create new task', 'url' => '/admin/tasks/create', 'icon' => 'plus'],
                ['label' => 'Create Project', 'desc' => 'Create new project', 'url' => '/admin/projects/create', 'icon' => 'plus'],
                ['label' => 'Submit Expense', 'desc' => 'Submit new expense', 'url' => '/admin/expenses/create', 'icon' => 'dollar'],
                ['label' => 'Request Leave', 'desc' => 'Submit absence request', 'url' => '/admin/absences/create', 'icon' => 'calendar'],
            ],
            'tools' => [
                ['label' => 'Settings', 'desc' => 'App settings', 'url' => '/admin/settings', 'icon' => 'settings'],
                ['label' => 'PWA Settings', 'desc' => 'Push notifications', 'url' => '/admin/pwa-settings', 'icon' => 'bell'],
                ['label' => 'Analytics', 'desc' => 'View analytics', 'url' => '/admin/financial-analytics', 'icon' => 'chart'],
            ],
        ];
    }

    public function render(): \Illuminate\Contracts\View\View|string
    {
        return <<<'HTML'
        <div id="command-palette" class="cu-command-overlay" onclick="closeCommandPalette(event)">
            <div class="cu-command-palette" onclick="event.stopPropagation()">
                <div class="cu-command-input-wrap">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    <input type="text"
                           id="command-input"
                           class="cu-command-input"
                           placeholder="Type a command or search..."
                           autocomplete="off">
                    <kbd style="font-size: 11px; padding: 2px 6px; background: #f4f4f5; border: 1px solid #e4e4e7; border-radius: 4px;">ESC</kbd>
                </div>
                <div class="cu-command-results">
                    @foreach($commands as $group => $items)
                        <div class="cu-command-group">
                            <div class="cu-command-group-label">{{ ucfirst($group) }}</div>
                            @foreach($items as $item)
                                <a href="{{ $item['url'] }}" class="cu-command-item" data-label="{{ $item['label'] }}">
                                    <div class="cu-command-item-icon">
                                        {!! $this->getIcon($item['icon']) !!}
                                    </div>
                                    <div class="cu-command-item-text">
                                        <div class="cu-command-item-title">{{ $item['label'] }}</div>
                                        <div class="cu-command-item-desc">{{ $item['desc'] }}</div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @endforeach
                </div>
                <div style="padding: 12px 16px; border-top: 1px solid #e4e4e7; display: flex; gap: 16px; font-size: 12px; color: #71717a;">
                    <span>↑↓ Navigate</span>
                    <span>↵ Select</span>
                    <span>ESC Close</span>
                </div>
            </div>
        </div>

        <script>
            // Open command palette
            function openCommandPalette() {
                document.getElementById('command-palette').classList.add('active');
                document.getElementById('command-input').focus();
            }

            // Close command palette
            function closeCommandPalette(e) {
                if (e && e.target !== e.currentTarget) return;
                document.getElementById('command-palette').classList.remove('active');
            }

            // Keyboard shortcut
            document.addEventListener('keydown', function(e) {
                if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                    e.preventDefault();
                    openCommandPalette();
                }
                if (e.key === 'Escape') {
                    closeCommandPalette();
                }
            });

            // Search functionality
            document.getElementById('command-input')?.addEventListener('input', function(e) {
                const query = e.target.value.toLowerCase();
                const items = document.querySelectorAll('.cu-command-item');

                items.forEach(item => {
                    const label = item.dataset.label.toLowerCase();
                    if (label.includes(query)) {
                        item.style.display = 'flex';
                    } else {
                        item.style.display = 'none';
                    }
                });
            });

            // Arrow key navigation
            document.addEventListener('keydown', function(e) {
                if (!document.getElementById('command-palette').classList.contains('active')) return;

                const items = Array.from(document.querySelectorAll('.cu-command-item:not([style*="display: none"])'));
                const current = items.findIndex(i => i.classList.contains('selected'));

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    items[current]?.classList.remove('selected');
                    const next = current === -1 ? 0 : (current + 1) % items.length;
                    items[next]?.classList.add('selected');
                    items[next]?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }

                if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    items[current]?.classList.remove('selected');
                    const prev = current <= 0 ? items.length - 1 : current - 1;
                    items[prev]?.classList.add('selected');
                    items[prev]?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }

                if (e.key === 'Enter') {
                    const selected = document.querySelector('.cu-command-item.selected');
                    if (selected) {
                        window.location.href = selected.href;
                    }
                }
            });
        </script>
        HTML;
    }

    protected function getIcon(string $name): string
    {
        $icons = [
            'home' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>',
            'folder' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path></svg>',
            'users' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>',
            'dollar' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>',
            'calendar' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>',
            'plus' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>',
            'settings' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>',
            'bell' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>',
            'chart' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>',
        ];

        return $icons[$name] ?? '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke-width="2"/></svg>';
    }
}