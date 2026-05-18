<div>
    <!-- ROOT WAJIB LIVEWIRE -->

    <div x-data="{
        open: false,
        top: 0,
        right: 0,
        toggle(el) {
            const r = el.getBoundingClientRect();
            this.top = r.bottom + 6;
            this.right = Math.max(12, window.innerWidth - r.right);
            this.open = !this.open;
        }
    }" @click.outside="open = false">

        {{-- Bell --}}
        <button @click="toggle($el)"
            class="relative flex items-center justify-center w-9 h-9 rounded-xl
                   text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white
                   hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors duration-150">
            
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
            </svg>

            @if($unreadCount > 0)
                <span class="absolute top-0 right-0 w-2.5 h-2.5 rounded-full bg-red-500
                             ring-2 ring-white dark:ring-gray-900 pointer-events-none"></span>
            @endif
        </button>

        {{-- DROPDOWN --}}
        <template x-teleport="body">
            <div x-show="open"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 scale-95"
                 x-transition:enter-end="opacity-100 scale-100"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0 scale-95"
                 :style="window.innerWidth < 640 
                    ? `position:fixed; top:${top}px; left:12px; right:12px; width:auto; z-index:9999` 
                    : `position:fixed; top:${top}px; right:${right}px; width:440px; z-index:9999`"
                 @click.outside="open = false"
                 style="display:none">

                <div class="rounded-2xl bg-white dark:bg-gray-900 shadow-2xl ring-1 ring-black/5 dark:ring-white/10 overflow-hidden">

                    {{-- HEADER --}}
                    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-800">
                        <div class="flex items-center gap-2">
                            <span class="font-bold text-[13px] sm:text-[15px] text-gray-900 dark:text-white pl-1">
                                Notifikasi
                            </span>

                            @if($unreadCount > 0)
                                <span class="px-1.5 py-0.5 text-[10px] sm:text-xs font-bold rounded-full 
                                    bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300">
                                    {{ $unreadCount }} baru
                                </span>
                            @endif
                        </div>

                        <div class="flex items-center gap-3 pr-1">
                            @if($unreadCount > 0)
                                <button wire:click="markAllRead"
                                    class="text-[10px] sm:text-xs font-semibold text-blue-600 dark:text-blue-400 hover:text-blue-800">
                                    Tandai dibaca
                                </button>
                            @endif

                            <button wire:click="clearAll"
                                class="text-[10px] sm:text-xs font-medium text-gray-400 hover:text-red-500">
                                Hapus semua
                            </button>
                        </div>
                    </div>

                    {{-- LIST --}}
                    <div class="overflow-y-auto divide-y divide-gray-50 dark:divide-gray-800/50" style="max-height:420px">

                        @forelse($notifications as $notif)
                            @php
                                $data     = $notif->data;
                                $title    = $data['title'] ?? 'Notifikasi';
                                $body     = $data['body'] ?? '';
                                $icon     = $data['icon'] ?? 'heroicon-o-megaphone'; // FIX
                                $color    = $data['iconColor'] ?? $data['color'] ?? 'gray';
                                $actions  = $data['actions'] ?? [];
                                $rawUrl   = !empty($actions) ? ($actions[0]['url'] ?? null) : null;
                                $url      = $rawUrl ? url($rawUrl) : null;
                                $isUnread = is_null($notif->read_at);

                                $validIcons = [
                                    'heroicon-o-bell',
                                    'heroicon-o-megaphone',
                                    'heroicon-o-check-circle',
                                    'heroicon-o-x-circle',
                                    'heroicon-o-exclamation-triangle',
                                    'heroicon-o-information-circle'
                                ];

                                $iconSafe = in_array($icon, $validIcons) ? $icon : 'heroicon-o-bell';

                                $colorMap = [
                                    'success' => ['bg'=>'bg-green-100 dark:bg-green-900/30','ic'=>'text-green-600 dark:text-green-400'],
                                    'danger'  => ['bg'=>'bg-red-100 dark:bg-red-900/30','ic'=>'text-red-500 dark:text-red-400'],
                                    'warning' => ['bg'=>'bg-amber-100 dark:bg-amber-900/30','ic'=>'text-amber-600 dark:text-amber-400'],
                                    'info'    => ['bg'=>'bg-blue-100 dark:bg-blue-900/30','ic'=>'text-blue-600 dark:text-blue-400'],
                                    'gray'    => ['bg'=>'bg-gray-100 dark:bg-gray-800','ic'=>'text-gray-500 dark:text-gray-400'],
                                ];

                                $c = $colorMap[$color] ?? $colorMap['gray'];
                            @endphp

                            <div @click="
                                    $wire.markRead('{{ $notif->id }}').then(() => {
                                        @if($url) window.location.href = '{{ $url }}'; @endif
                                    })"
                                 class="flex items-start gap-4 px-6 py-4 cursor-pointer
                                        {{ $isUnread ? 'bg-blue-50/50 dark:bg-blue-950/20' : 'hover:bg-gray-50 dark:hover:bg-gray-800/40' }}">

                                <div class="flex-shrink-0 w-10 h-10 rounded-xl flex items-center justify-center {{ $c['bg'] }}">
                                    @svg($iconSafe, 'w-5 h-5 ' . $c['ic'])
                                </div>

                                <div class="flex-1 min-w-0">
                                    <div class="flex items-start justify-between gap-2">
                                        <p class="text-sm {{ $isUnread ? 'font-semibold text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-300' }}">
                                            {{ $title }}
                                        </p>

                                        <span class="text-[11px] text-gray-400 whitespace-nowrap">
                                            {{ $notif->created_at->diffForHumans(short: true) }}
                                        </span>
                                    </div>

                                    @if($body)
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 line-clamp-2">
                                            {{ strip_tags($body) }}
                                        </p>
                                    @endif
                                </div>

                                @if($isUnread)
                                    <button wire:click.stop="markRead('{{ $notif->id }}')"
                                        class="w-2 h-2 rounded-full bg-blue-500 mt-2">
                                    </button>
                                @endif
                            </div>

                        @empty
                            <div class="flex flex-col items-center justify-center py-14 text-center">
                                <p class="text-sm font-semibold text-gray-600 dark:text-gray-400">
                                    Tidak ada notifikasi
                                </p>
                                <p class="text-xs text-gray-400 mt-1">
                                    Semua sudah dibaca
                                </p>
                            </div>
                        @endforelse

                    </div>
                </div>
            </div>
        </template>
    </div>
</div>