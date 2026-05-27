<x-filament::widget>
    <x-filament::card>
        <h2 class="text-lg font-bold flex items-center">
            <x-heroicon-o-bell class="w-5 h-5 mr-2 text-grey-100" /> Pengumuman Resmi:
        </h2>
        <div class="space-y-2 mt-2">
            @forelse ($announcements as $announcement)
                <div class="bg-gray-50 p-3 rounded-lg flex justify-between items-center">
                    <span class="text-sm">{!! $announcement->content !!}</span>
                </div>
                <span class="text-xs text-gray-500">
                        - {{ $announcement->user->firstname ?? 'Admin' }} {{ $announcement->user->lastname ?? '' }}
                    </span>
            @empty
                <p class="text-gray-500 text-sm">Tidak ada pengumuman aktif.</p>
            @endforelse
        </div>
    </x-filament::card>
</x-filament::widget>
