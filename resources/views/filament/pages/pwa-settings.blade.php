<x-filament-panels::page>
    <div class="fi-content">
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-xl flex items-center justify-center">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">PWA & Notifications</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Atur push notifications dan install aplikasi</p>
                    </div>
                </div>
            </x-slot>

            <div class="space-y-6">
                <form wire:submit="saveSettings">
                    <x-filament::section>
                        <x-slot name="heading">Push Notifications</x-slot>

                        <div class="space-y-4">
                            <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-800 rounded-xl">
                                <div>
                                    <h4 class="font-medium text-gray-900 dark:text-white">Status Notifikasi</h4>
                                    <p class="text-sm text-gray-500">{{ $this->pushStatus }}</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    @if($this->isSubscribed)
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                            ✓ Aktif
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">
                                            Nonaktif
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <div class="grid gap-4">
                                <label class="flex items-center gap-3 p-4 bg-gray-50 dark:bg-gray-800 rounded-xl cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                                    <input type="checkbox" wire:model="pushSettings.expense_approvals" class="w-5 h-5 text-indigo-600 rounded">
                                    <div class="flex-1">
                                        <span class="font-medium text-gray-900 dark:text-white">Persetujuan Expense</span>
                                        <p class="text-sm text-gray-500">Notifikasi saat ada pengajuan expense baru</p>
                                    </div>
                                </label>

                                <label class="flex items-center gap-3 p-4 bg-gray-50 dark:bg-gray-800 rounded-xl cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                                    <input type="checkbox" wire:model="pushSettings.absence_requests" class="w-5 h-5 text-indigo-600 rounded">
                                    <div class="flex-1">
                                        <span class="font-medium text-gray-900 dark:text-white">Pengajuan Cuti/Izin</span>
                                        <p class="text-sm text-gray-500">Notifikasi saat ada pengajuan cuti baru</p>
                                    </div>
                                </label>

                                <label class="flex items-center gap-3 p-4 bg-gray-50 dark:bg-gray-800 rounded-xl cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                                    <input type="checkbox" wire:model="pushSettings.project_updates" class="w-5 h-5 text-indigo-600 rounded">
                                    <div class="flex-1">
                                        <span class="font-medium text-gray-900 dark:text-white">Update Project</span>
                                        <p class="text-sm text-gray-500">Notifikasi perubahan status project</p>
                                    </div>
                                </label>

                                <label class="flex items-center gap-3 p-4 bg-gray-50 dark:bg-gray-800 rounded-xl cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                                    <input type="checkbox" wire:model="pushSettings.task_reminders" class="w-5 h-5 text-indigo-600 rounded">
                                    <div class="flex-1">
                                        <span class="font-medium text-gray-900 dark:text-white">Pengingat Task</span>
                                        <p class="text-sm text-gray-500">Pengingat deadline task hari ini</p>
                                    </div>
                                </label>

                                <label class="flex items-center gap-3 p-4 bg-gray-50 dark:bg-gray-800 rounded-xl cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                                    <input type="checkbox" wire:model="pushSettings.announcements" class="w-5 h-5 text-indigo-600 rounded">
                                    <div class="flex-1">
                                        <span class="font-medium text-gray-900 dark:text-white">Pengumuman Company</span>
                                        <p class="text-sm text-gray-500">Notifikasi pengumuman dari management</p>
                                    </div>
                                </label>
                            </div>
                        </div>
                    </x-filament::section>

                    <x-filament::section>
                        <x-slot name="heading">Install Aplikasi</x-slot>
                        <x-slot name="description">Pasang ERP di perangkat Anda untuk pengalaman terbaik</x-slot>

                        <div class="grid md:grid-cols-3 gap-4">
                            <div class="bg-gradient-to-br from-blue-50 to-blue-100 dark:from-blue-900/20 dark:to-blue-800/20 rounded-xl p-4 border border-blue-200 dark:border-blue-700">
                                <div class="w-10 h-10 bg-blue-500 rounded-lg flex items-center justify-center mb-3">
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                                    </svg>
                                </div>
                                <h3 class="font-semibold text-gray-900 dark:text-white mb-2">iOS (Safari)</h3>
                                <ol class="text-sm text-gray-600 dark:text-gray-400 space-y-1 list-decimal list-inside">
                                    <li>Buka Safari</li>
                                    <li>Klik tombol Share</li>
                                    <li>Pilih "Add to Home Screen"</li>
                                </ol>
                            </div>

                            <div class="bg-gradient-to-br from-green-50 to-green-100 dark:from-green-900/20 dark:to-green-800/20 rounded-xl p-4 border border-green-200 dark:border-green-700">
                                <div class="w-10 h-10 bg-green-500 rounded-lg flex items-center justify-center mb-3">
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                                    </svg>
                                </div>
                                <h3 class="font-semibold text-gray-900 dark:text-white mb-2">Android (Chrome)</h3>
                                <ol class="text-sm text-gray-600 dark:text-gray-400 space-y-1 list-decimal list-inside">
                                    <li>Buka Chrome</li>
                                    <li>Klik menu (⋮)</li>
                                    <li>Pilih "Install App"</li>
                                </ol>
                            </div>

                            <div class="bg-gradient-to-br from-purple-50 to-purple-100 dark:from-purple-900/20 dark:to-purple-800/20 rounded-xl p-4 border border-purple-200 dark:border-purple-700">
                                <div class="w-10 h-10 bg-purple-500 rounded-lg flex items-center justify-center mb-3">
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                    </svg>
                                </div>
                                <h3 class="font-semibold text-gray-900 dark:text-white mb-2">Desktop</h3>
                                <ol class="text-sm text-gray-600 dark:text-gray-400 space-y-1 list-decimal list-inside">
                                    <li>Buka di Chrome/Edge</li>
                                    <li>Klik icon install di address bar</li>
                                    <li>Ikuti instruksi</li>
                                </ol>
                            </div>
                        </div>
                    </x-filament::section>

                    <x-filament::section>
                        <x-slot name="heading">Offline Support</x-slot>

                        <div class="bg-gradient-to-r from-indigo-500 to-purple-600 rounded-xl p-6 text-white">
                            <div class="flex items-center gap-4">
                                <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="font-semibold text-lg">Mode Offline Tersedia</h3>
                                    <p class="text-white/80 text-sm">Beberapa halaman akan tetap bisa diakses meski tanpa koneksi internet.</p>
                                </div>
                            </div>
                        </div>
                    </x-filament::section>

                    <div class="flex justify-end">
                        <x-filament::button type="submit" size="lg">
                            Simpan Pengaturan
                        </x-filament::button>
                    </div>
                </form>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>