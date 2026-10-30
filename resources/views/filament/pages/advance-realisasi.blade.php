<x-filament-panels::page>
    @php
        $rp = fn ($amount) => 'Rp ' . number_format(abs((float) $amount), 0, ',', '.');
        // 2.00 → 2, 1.50 → 1,5
        $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
        // Nama file dipendekkan tanpa membuang ekstensinya.
        $fileLabel = function (string $name): string {
            if (mb_strlen($name) <= 26) {
                return $name;
            }
            $ext = pathinfo($name, PATHINFO_EXTENSION);

            return \Illuminate\Support\Str::limit(pathinfo($name, PATHINFO_FILENAME), 18, '…') . ($ext ? ".{$ext}" : '');
        };

        $over        = $remaining < 0;
        $progress    = max(0, (float) $progressPercentage);
        $batchCount  = count($batches);
    @endphp

    {{-- Hanya tata letak; warna dari variabel tema Filament supaya ikut terang/gelap. --}}
    <style>
        .rl-figures { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1.5rem; }
        .rl-figure dt { font-size: .8125rem; color: rgb(var(--gray-500)); }
        .rl-figure dd { margin: .25rem 0 0; font-size: 1.5rem; line-height: 2rem; font-weight: 600; font-variant-numeric: tabular-nums; color: rgb(var(--gray-950)); }
        .rl-figure p { margin-top: .125rem; font-size: .8125rem; color: rgb(var(--gray-500)); }
        .rl-figure dd.is-over { color: rgb(var(--warning-800)); }
        .dark .rl-figure dd { color: #fff; }
        .dark .rl-figure dd.is-over { color: rgb(var(--warning-300)); }
        .dark .rl-figure dt, .dark .rl-figure p { color: rgb(var(--gray-400)); }

        .rl-progress { height: .5rem; border-radius: 9999px; background: rgb(var(--gray-200)); overflow: hidden; }
        .rl-progress > span { display: block; height: 100%; border-radius: inherit; background: rgb(var(--primary-600)); transition: width .3s ease; }
        .rl-progress.is-over > span { background: rgb(var(--warning-600)); }
        .dark .rl-progress { background: rgba(255, 255, 255, .1); }
        .dark .rl-progress > span { background: rgb(var(--primary-500)); }
        .dark .rl-progress.is-over > span { background: rgb(var(--warning-500)); }

        .rl-meta { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1rem; font-size: .875rem; color: rgb(var(--gray-500)); }
        .rl-meta strong { font-weight: 500; color: rgb(var(--gray-950)); }
        .dark .rl-meta { color: rgb(var(--gray-400)); }
        .dark .rl-meta strong { color: #fff; }

        .rl-note { border-radius: .75rem; padding: .875rem 1rem; font-size: .875rem; border: 1px solid; }
        .rl-note strong { display: block; font-weight: 600; }
        .rl-note p { margin-top: .125rem; }
        .rl-note[data-color="info"]    { background: rgba(var(--info-500), .07);    border-color: rgba(var(--info-500), .25);    color: rgb(var(--info-700)); }
        .rl-note[data-color="success"] { background: rgba(var(--success-500), .07); border-color: rgba(var(--success-500), .25); color: rgb(var(--success-700)); }
        .rl-note[data-color="warning"] { background: rgba(var(--warning-500), .14); border-color: rgba(var(--warning-600), .45); color: rgb(var(--warning-900)); }
        .rl-note[data-color="gray"]    { background: rgba(var(--gray-500), .06);    border-color: rgba(var(--gray-500), .2);     color: rgb(var(--gray-700)); }
        .dark .rl-note[data-color="info"]    { color: rgb(var(--info-300)); }
        .dark .rl-note[data-color="success"] { color: rgb(var(--success-300)); }
        .dark .rl-note[data-color="warning"] { color: rgb(var(--warning-300)); }
        .dark .rl-note[data-color="gray"]    { color: rgb(var(--gray-300)); }

        /* Item batch: tabel di layar lebar, bertumpuk di HP. */
        .rl-items { font-size: .875rem; }
        .rl-item, .rl-items-head { display: grid; grid-template-columns: minmax(0, 1fr) 4.5rem 8.5rem 9rem; gap: .75rem; align-items: start; }
        .rl-items-head { padding-bottom: .5rem; border-bottom: 1px solid rgba(var(--gray-950), .06); font-size: .75rem; font-weight: 500; color: rgb(var(--gray-500)); }
        .rl-item { padding: .875rem 0; border-bottom: 1px solid rgba(var(--gray-950), .05); }
        .rl-item:last-child { border-bottom: 0; padding-bottom: 0; }
        .rl-item .num, .rl-items-head .num { text-align: right; font-variant-numeric: tabular-nums; }
        .rl-item .name { font-weight: 500; color: rgb(var(--gray-950)); }
        .rl-item .desc { margin-top: .125rem; color: rgb(var(--gray-500)); }
        .rl-item .subtotal { font-weight: 600; color: rgb(var(--gray-950)); }
        .rl-item .qty-mobile { display: none; }
        .dark .rl-items-head { border-color: rgba(255, 255, 255, .1); color: rgb(var(--gray-400)); }
        .dark .rl-item { border-color: rgba(255, 255, 255, .06); }
        .dark .rl-item .name, .dark .rl-item .subtotal { color: #fff; }
        .dark .rl-item .desc { color: rgb(var(--gray-400)); }

        .rl-files { display: flex; flex-wrap: wrap; gap: .375rem; margin-top: .5rem; }
        .rl-file { display: inline-flex; align-items: center; gap: .25rem; max-width: 100%; padding: .125rem .5rem; border-radius: .375rem; font-size: .75rem; font-weight: 500;
                   color: rgb(var(--primary-700)); background: rgba(var(--primary-500), .08); border: 1px solid rgba(var(--primary-500), .2); text-decoration: none; }
        .rl-file:hover { background: rgba(var(--primary-500), .14); }
        .dark .rl-file { color: rgb(var(--primary-300)); background: rgba(var(--primary-400), .1); border-color: rgba(var(--primary-400), .25); }

        .rl-drop { display: block; border: 2px dashed rgba(var(--gray-950), .12); border-radius: .75rem; padding: 1.25rem; text-align: center; cursor: pointer; transition: border-color .15s, background .15s; }
        .rl-drop:hover, .rl-drop.is-dragging { border-color: rgb(var(--primary-500)); background: rgba(var(--primary-500), .05); }
        .dark .rl-drop { border-color: rgba(255, 255, 255, .15); }

        .rl-form-item { border-radius: .75rem; padding: .875rem; background: rgba(var(--gray-950), .03); }
        .dark .rl-form-item { background: rgba(255, 255, 255, .04); }
        .rl-form-grid { display: grid; grid-template-columns: minmax(0, 1fr) 5.5rem 9rem; gap: .5rem; align-items: end; }
        .rl-label { display: block; margin-bottom: .25rem; font-size: .75rem; font-weight: 500; color: rgb(var(--gray-600)); }
        .dark .rl-label { color: rgb(var(--gray-400)); }
        .rl-total { display: flex; justify-content: space-between; align-items: baseline; padding: .75rem 1rem; border-radius: .75rem; background: rgba(var(--primary-500), .07); font-variant-numeric: tabular-nums; }
        .rl-total strong { font-size: 1.125rem; color: rgb(var(--gray-950)); }
        .dark .rl-total strong { color: #fff; }

        @media (max-width: 640px) {
            .rl-figures { grid-template-columns: 1fr; gap: 1rem; }
            .rl-items-head { display: none; }
            .rl-item { grid-template-columns: minmax(0, 1fr) auto; }
            .rl-item .qty, .rl-item .price { display: none; }
            .rl-item .qty-mobile { display: block; margin-top: .125rem; color: rgb(var(--gray-500)); }
            .rl-form-grid { grid-template-columns: 1fr 1fr; }
            .rl-form-grid .span-all { grid-column: 1 / -1; }
        }
    </style>

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

                <div class="rl-items" role="table" aria-label="Item batch #{{ $batch['batch_number'] }}">
                    <div class="rl-items-head" role="row">
                        <span role="columnheader">Item</span>
                        <span role="columnheader" class="num">Qty</span>
                        <span role="columnheader" class="num">Harga</span>
                        <span role="columnheader" class="num">Subtotal</span>
                    </div>

                    @foreach($batch['items'] as $item)
                        <div class="rl-item" role="row" wire:key="item-{{ $item['id'] }}">
                            <div role="cell">
                                <div class="name">{{ $item['name'] }}</div>
                                @if(filled($item['description']))
                                    <div class="desc">{{ $item['description'] }}</div>
                                @endif
                                <div class="qty-mobile">{{ $qty($item['quantity']) }} × {{ $rp($item['price']) }}</div>
                                @if(count($item['files']))
                                    <div class="rl-files">
                                        @foreach($item['files'] as $file)
                                            <a href="{{ url('storage/' . $file['filepath']) }}" target="_blank" rel="noopener" class="rl-file" title="{{ $file['original_name'] }}">
                                                <x-filament::icon :icon="$file['is_image'] ? 'heroicon-m-photo' : 'heroicon-m-document-text'" class="h-3.5 w-3.5" />
                                                {{ $fileLabel($file['original_name']) }}
                                            </a>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                            <div role="cell" class="num qty">{{ $qty($item['quantity']) }}</div>
                            <div role="cell" class="num price">{{ $rp($item['price']) }}</div>
                            <div role="cell" class="num subtotal">{{ $rp($item['subtotal']) }}</div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @empty
            <x-filament::section>
                <div class="flex flex-col items-center gap-3 py-6 text-center">
                    <x-filament::icon icon="heroicon-o-receipt-percent" class="h-10 w-10 text-gray-400 dark:text-gray-500" />
                    <div>
                        <p class="text-base font-semibold text-gray-950 dark:text-white">Belum ada batch realisasi</p>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
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
                    <p class="text-xs text-gray-500 dark:text-gray-400">JPG, PNG, WebP, atau PDF, maks. 5 MB per file</p>
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
                            <span class="text-gray-500 dark:text-gray-400">
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
                    <p class="text-xs text-gray-500 dark:text-gray-400">JPG, PNG, WebP, atau PDF, maks. 5 MB</p>
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
