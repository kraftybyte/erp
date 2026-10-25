<x-filament-breezy::grid-section md=2 title="Telegram" description="Ajukan expense dan advance lewat chat Telegram, dan terima kabar saat pengajuanmu disetujui, ditolak, atau dibayarkan.">
    <x-filament::card>
        @if ($user->telegram_chat_id)
            <div class="space-y-4">
                <p class="text-sm">
                    Terhubung sejak {{ $user->telegram_linked_at?->locale('id')->translatedFormat('j F Y, H:i') }}.
                </p>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Buka bot di Telegram lalu ketik <code>/bantuan</code> untuk melihat cara pakai.
                </p>
                <x-filament::button
                    color="danger"
                    outlined
                    wire:click="disconnect"
                    wire:confirm="Putuskan akun Telegram dari ERP? Bot tidak akan bisa dipakai sampai dihubungkan lagi."
                >
                    Putuskan
                </x-filament::button>
            </div>
        @elseif ($linkUrl)
            {{-- Cek berkala sampai user menekan Start di Telegram. --}}
            <div class="space-y-4" wire:poll.5s>
                <p class="text-sm">
                    Tekan tombol di bawah, lalu tekan <b>Start</b> di Telegram.
                    Link hanya bisa dipakai sekali dan berlaku {{ $ttlMinutes }} menit.
                </p>
                <x-filament::button
                    tag="a"
                    :href="$linkUrl"
                    target="_blank"
                    rel="noopener"
                    icon="heroicon-o-arrow-top-right-on-square"
                >
                    Buka Telegram
                </x-filament::button>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Halaman ini berubah sendiri setelah akun terhubung.
                </p>
            </div>
        @else
            <div class="space-y-4">
                <p class="text-sm">Belum terhubung.</p>
                <x-filament::button wire:click="generateLink" icon="heroicon-o-link">
                    Hubungkan Telegram
                </x-filament::button>
            </div>
        @endif
    </x-filament::card>
</x-filament-breezy::grid-section>
