{{-- PackingSlip Page --}}
<x-filament-panels::page>

    <div class="max-w-2xl mx-auto">

        {{-- Project Info Card --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="bg-gradient-to-r from-primary-500 to-primary-600 px-6 py-4">
                <h2 class="text-lg font-semibold text-white">{{ $record->nama_project }}</h2>
                <p class="text-primary-100 text-sm">Project #{{ $record->id }}</p>
            </div>

            <div class="p-6 space-y-6">
                {{-- Data akan di-print --}}
                <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                    <h3 class="font-semibold text-gray-700 dark:text-gray-200 mb-3 flex items-center gap-2">
                        <x-heroicon-o-information-circle class="w-5 h-5 text-primary-500" />
                        Data Packing Slip
                    </h3>

                    {{-- Pengirim --}}
                    <div class="mb-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Pengirim</p>
                        <p class="font-medium text-gray-900 dark:text-white">{{ $this->sender['name'] }}</p>
                        <p class="text-sm text-gray-600 dark:text-gray-300">{{ $this->sender['address'] }}</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $this->sender['phone'] }}</p>
                    </div>

                    <div class="border-t border-gray-200 dark:border-gray-600 my-4"></div>

                    {{-- Penerima --}}
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Penerima</p>
                        <p class="font-medium text-gray-900 dark:text-white">{{ $this->recipient['name'] }}</p>
                        <p class="text-sm text-gray-600 dark:text-gray-300">{{ $this->recipient['address'] }}</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $this->recipient['phone'] }}</p>
                    </div>
                </div>

                {{-- Info --}}
                <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                    <p class="text-blue-700 dark:text-blue-300 text-sm">
                        <x-heroicon-o-light-bulb class="w-4 h-4 inline mr-1" />
                        Klik <strong>Print Thermal</strong> untuk membuka preview dan print. Pastikan printer thermal sudah terhubung.
                    </p>
                </div>

                {{-- Action Info --}}
                <div class="flex items-center justify-center gap-4 pt-4">
                    <div class="text-center">
                        <x-heroicon-o-printer class="w-8 h-8 text-success-500 mx-auto mb-1" />
                        <p class="text-xs text-gray-500">Print Thermal</p>
                        <p class="text-xs text-gray-400">58/80/100mm</p>
                    </div>
                    <div class="text-center">
                        <x-heroicon-o-document-arrow-down class="w-8 h-8 text-primary-500 mx-auto mb-1" />
                        <p class="text-xs text-gray-500">Download PDF</p>
                        <p class="text-xs text-gray-400">Simpan file</p>
                    </div>
                </div>
            </div>
        </div>

    </div>

</x-filament-panels::page>
