<x-filament-panels::page>
    @php
        $rp = fn ($amount) => \App\Helpers\Money::rupiah($amount);

        $over        = $remaining < 0;
        $progress    = max(0, (float) $progressPercentage);
        $batchCount  = count($batches);
    @endphp

    @include('filament.pages.partials.realisasi-styles')

    <div class="flex flex-col gap-6">
        {{-- Ringkasan: berapa dicairkan, berapa terealisasi, berapa sisanya, dan apa langkah berikutnya. --}}
        <x-filament::section>
            <div class="flex flex-col gap-5">
                <dl class="rl-figures">
                    <div class="rl-figure">
                        <dt>Dicairkan</dt>
                        <dd>{{ $rp($record->paid) }}</dd>
                        <p>{{ $paidAt ? \Carbon\Carbon::parse($paidAt)->locale('id')->translatedFormat('j M Y') : '—' }}</p>
                    </div>
                    <div class="rl-figure">
                        <dt>Terealisasi</dt>
                        <dd>{{ $rp($totalRealisasi) }}</dd>
                        <p>{{ rtrim(rtrim(number_format($progress, 1, ',', '.'), '0'), ',') }}% · {{ $batchCount }} batch</p>
                    </div>
                    <div class="rl-figure">
                        <dt>{{ $over ? 'Kelebihan' : 'Sisa' }}</dt>
                        <dd @class(['is-over' => $over])>{{ $rp($remaining) }}</dd>
                        <p>{{ $over ? 'melebihi advance' : 'belum direalisasi' }}</p>
                    </div>
                </dl>

                <div @class(['rl-progress', 'is-over' => $over]) role="progressbar" aria-label="Progres realisasi"
                     aria-valuenow="{{ round($progress) }}" aria-valuemin="0" aria-valuemax="100">
                    <span style="width: {{ min($progress, 100) }}%"></span>
                </div>

                <div class="rl-meta">
                    <span>Pemohon <strong>{{ $requester }}</strong></span>
                    <span class="flex items-center gap-2">Status
                        <x-filament::badge :color="$record->getStatusBadgeClass()" size="sm">{{ $record->getStatusLabel() }}</x-filament::badge>
                    </span>
                </div>

                @if($statusNote)
                    <div class="rl-note" data-color="{{ $statusNote['color'] }}" role="status">
                        <strong>{{ $statusNote['title'] }}</strong>
                        <p>{{ $statusNote['body'] }}</p>
                    </div>
                @endif
            </div>
        </x-filament::section>

        {{-- Batch realisasi --}}
        @forelse($batches as $batch)
            <x-filament::section wire:key="batch-{{ $batch['id'] }}">
                <x-slot name="heading">Batch #{{ $batch['batch_number'] }}</x-slot>
                <x-slot name="description">
                    {{ $batch['uploaded_by_name'] }} · {{ \Carbon\Carbon::parse($batch['created_at'])->locale('id')->translatedFormat('j M Y, H.i') }}
                    @if(filled($batch['notes']))
                        <br>{{ $batch['notes'] }}
                    @endif
                </x-slot>
                <x-slot name="headerEnd">
                    <div class="flex items-center gap-2">
                        <span class="text-base font-semibold text-gray-950 dark:text-white" style="font-variant-numeric: tabular-nums;">{{ $rp($batch['total_amount']) }}</span>
                        @if($canDelete)
                            <x-filament::icon-button
                                icon="heroicon-m-trash"
                                color="danger"
                                label="Hapus batch #{{ $batch['batch_number'] }}"
                                wire:click="deleteBatch({{ $batch['id'] }})"
                                wire:confirm="Hapus batch #{{ $batch['batch_number'] }} beserta item dan buktinya?"
                            />
                        @endif
                    </div>
                </x-slot>

                @include('filament.pages.partials.realisasi-items', ['batch' => $batch])
            </x-filament::section>
        @empty
            <x-filament::section>
                <div class="flex flex-col items-center gap-3 py-6 text-center">
                    <x-filament::icon icon="heroicon-o-receipt-percent" class="h-10 w-10 text-gray-400 dark:text-gray-500" />
                    <div>
                        <p class="text-base font-semibold text-gray-950 dark:text-white">Belum ada batch realisasi</p>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">
                            Satu batch berisi pengeluaran yang dibayar dari advance ini, lengkap dengan foto struk atau nota.
                        </p>
                    </div>
                    @if($canAdd)
                        <x-filament::button icon="heroicon-m-plus" wire:click="openBatchModal">Tambah batch pertama</x-filament::button>
                    @endif
                </div>
            </x-filament::section>
        @endforelse
    </div>

    {{-- Tambah batch --}}
    <x-filament::modal id="realisasi-batch" width="2xl" :close-by-clicking-away="false"
                       heading="Tambah batch realisasi" description="Catat pengeluaran dari advance ini beserta bukti pembayarannya.">
        <form wire:submit="saveBatch" id="batchForm" class="flex flex-col gap-5">
            {{-- Bukti --}}
            <div x-data="{ dragging: false }">
                <span class="rl-label">Bukti pengeluaran (struk, nota, invoice)</span>
                <label for="file-upload" @class(['rl-drop']) :class="{ 'is-dragging': dragging }"
                       x-on:dragover.prevent="dragging = true"
                       x-on:dragleave.prevent="dragging = false"
                       x-on:drop.prevent="dragging = false; $refs.files.files = $event.dataTransfer.files; $refs.files.dispatchEvent(new Event('change', { bubbles: true }))">
                    <input type="file" multiple wire:model="uploadedFiles" accept=".pdf,.jpg,.jpeg,.png,.webp" class="sr-only" id="file-upload" x-ref="files">
                    <x-filament::icon icon="heroicon-o-arrow-up-tray" class="mx-auto h-6 w-6 text-gray-400" />
                    <p class="mt-2 text-sm font-medium text-gray-700 dark:text-gray-200">Pilih file atau tarik ke sini</p>
                    <p class="text-xs text-gray-500 dark:text-gray-300">JPG, PNG, WebP, atau PDF, maks. 5 MB per file</p>
                </label>

                <div wire:loading.flex wire:target="uploadedFiles" class="mt-2 items-center gap-2 text-sm text-gray-500">
                    <x-filament::loading-indicator class="h-4 w-4" /> Mengunggah…
                </div>

                @if(count($uploadedFiles))
                    <ul class="mt-2 flex flex-col gap-1">
                        @foreach($uploadedFiles as $index => $file)
                            <li class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200" wire:key="upload-{{ $index }}">
                                <x-filament::icon icon="heroicon-m-paper-clip" class="h-4 w-4 text-gray-400" />
                                <span class="min-w-0 flex-1 truncate">{{ $file->getClientOriginalName() }}</span>
                                <span class="text-xs text-gray-500">{{ number_format($file->getSize() / 1024, 0, ',', '.') }} KB</span>
                                <x-filament::icon-button icon="heroicon-m-x-mark" color="gray" size="sm" label="Hapus file" wire:click="removeUploadedFile({{ $index }})" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Item --}}
            <div class="flex flex-col gap-2">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-semibold text-gray-950 dark:text-white">Pengeluaran</span>
                    <x-filament::link tag="button" type="button" icon="heroicon-m-plus" wire:click="addBatchItem">Tambah item</x-filament::link>
                </div>

                @foreach($batchItems as $index => $item)
                    <div class="rl-form-item" wire:key="batch-item-{{ $index }}">
                        <div class="rl-form-grid">
                            <div class="span-all">
                                <label class="rl-label" for="item-name-{{ $index }}">Nama pengeluaran</label>
                                <x-filament::input.wrapper>
                                    <x-filament::input id="item-name-{{ $index }}" type="text" wire:model="batchItems.{{ $index }}.name" placeholder="mis. Sewa lighting 2 set" />
                                </x-filament::input.wrapper>
                            </div>
                            <div>
                                <label class="rl-label" for="item-qty-{{ $index }}">Qty</label>
                                <x-filament::input.wrapper>
                                    <x-filament::input id="item-qty-{{ $index }}" type="number" min="1" inputmode="numeric" wire:model.live.debounce.400ms="batchItems.{{ $index }}.quantity" />
                                </x-filament::input.wrapper>
                            </div>
                            <div>
                                <label class="rl-label" for="item-price-{{ $index }}">Harga satuan (Rp)</label>
                                <x-filament::input.wrapper>
                                    <x-filament::input id="item-price-{{ $index }}" type="number" min="0" inputmode="numeric" wire:model.live.debounce.400ms="batchItems.{{ $index }}.price" />
                                </x-filament::input.wrapper>
                            </div>
                        </div>
                        <div class="mt-2 flex items-center justify-between text-sm">
                            <span class="text-gray-500 dark:text-gray-300">
                                Subtotal <strong class="text-gray-950 dark:text-white">{{ $rp(((float) ($item['price'] ?: 0)) * ((float) ($item['quantity'] ?: 1))) }}</strong>
                            </span>
                            @if(count($batchItems) > 1)
                                <x-filament::link tag="button" type="button" color="danger" size="sm" wire:click="removeBatchItem({{ $index }})">Hapus item</x-filament::link>
                            @endif
                        </div>
                    </div>
                @endforeach

                @php $batchTotal = collect($batchItems)->sum(fn ($item) => ((float) ($item['price'] ?: 0)) * ((float) ($item['quantity'] ?: 1))); @endphp
                <div class="rl-total">
                    <span class="text-sm text-gray-600 dark:text-gray-300">Total batch</span>
                    <strong>{{ $rp($batchTotal) }}</strong>
                </div>
            </div>

            <div>
                <label class="rl-label" for="batch-notes">Catatan (opsional)</label>
                <x-filament::input.wrapper>
                    <textarea id="batch-notes" wire:model="batchNotes" rows="2" class="block w-full border-none bg-transparent px-3 py-1.5 text-sm text-gray-950 outline-none focus:ring-0 dark:text-white"
                              placeholder="mis. Shooting hari pertama"></textarea>
                </x-filament::input.wrapper>
            </div>
        </form>

        <x-slot name="footerActions">
            <x-filament::button type="submit" form="batchForm" wire:target="saveBatch">Simpan batch</x-filament::button>
            <x-filament::button color="gray" wire:click="closeBatchModal">Batal</x-filament::button>
        </x-slot>
    </x-filament::modal>

    {{-- Catat pengembalian sisa (Finance) --}}
    <x-filament::modal id="realisasi-return" width="md" heading="Catat pengembalian sisa"
                       description="Sisa advance yang dikembalikan pemohon ke rekening perusahaan.">
        <form wire:submit="processReturn" id="returnForm" class="flex flex-col gap-4">
            <div class="rl-total">
                <span class="text-sm text-gray-600 dark:text-gray-300">Jumlah dikembalikan</span>
                <strong>{{ $rp($remaining) }}</strong>
            </div>

            <div x-data="{ dragging: false }">
                <span class="rl-label">Bukti transfer</span>
                <label for="return-upload" class="rl-drop" :class="{ 'is-dragging': dragging }"
                       x-on:dragover.prevent="dragging = true"
                       x-on:dragleave.prevent="dragging = false"
                       x-on:drop.prevent="dragging = false; $refs.file.files = $event.dataTransfer.files; $refs.file.dispatchEvent(new Event('change', { bubbles: true }))">
                    <input type="file" wire:model="returnAttachment" accept=".pdf,.jpg,.jpeg,.png,.webp" class="sr-only" id="return-upload" x-ref="file">
                    <x-filament::icon icon="heroicon-o-arrow-up-tray" class="mx-auto h-6 w-6 text-gray-400" />
                    <p class="mt-2 text-sm font-medium text-gray-700 dark:text-gray-200">Pilih file atau tarik ke sini</p>
                    <p class="text-xs text-gray-500 dark:text-gray-300">JPG, PNG, WebP, atau PDF, maks. 5 MB</p>
                </label>

                <div wire:loading.flex wire:target="returnAttachment" class="mt-2 items-center gap-2 text-sm text-gray-500">
                    <x-filament::loading-indicator class="h-4 w-4" /> Mengunggah…
                </div>

                @if($returnAttachment)
                    <p class="mt-2 flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <x-filament::icon icon="heroicon-m-paper-clip" class="h-4 w-4 text-gray-400" />
                        <span class="min-w-0 flex-1 truncate">{{ $returnAttachment->getClientOriginalName() }}</span>
                    </p>
                @endif

                @error('returnAttachment')
                    <p class="mt-2 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                @enderror
            </div>
        </form>

        <x-slot name="footerActions">
            <x-filament::button type="submit" form="returnForm" wire:target="processReturn">Simpan pengembalian</x-filament::button>
            <x-filament::button color="gray" wire:click="closeReturnModal">Batal</x-filament::button>
        </x-slot>
    </x-filament::modal>
</x-filament-panels::page>
