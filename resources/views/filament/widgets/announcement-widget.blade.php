<x-filament::widget>
    {{-- Warna dari variabel tema supaya terbaca di mode gelap (dulu kotak
         pengumuman tetap abu muda dengan teks putih). --}}
    <style>
        .an-item { padding: .75rem; border-radius: .5rem; font-size: .875rem; color: rgb(var(--gray-950)); background: rgba(var(--gray-950), .03); }
        .an-by, .an-empty { font-size: .8125rem; color: rgb(var(--gray-500)); }
        .dark .an-item { color: #fff; background: rgba(255, 255, 255, .05); }
        .dark .an-by, .dark .an-empty { color: rgb(var(--gray-300)); }
    </style>

    <x-filament::section icon="heroicon-o-bell" heading="Pengumuman resmi">
        <div class="flex flex-col gap-3">
            @forelse ($announcements as $announcement)
                <div>
                    <div class="an-item">{!! str($announcement->content ?? '')->sanitizeHtml() !!}</div>
                    <p class="an-by mt-1">— {{ trim(($announcement->user->firstname ?? 'Admin') . ' ' . ($announcement->user->lastname ?? '')) }}</p>
                </div>
            @empty
                <p class="an-empty">Tidak ada pengumuman aktif.</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament::widget>
