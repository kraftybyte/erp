<x-filament-panels::page>
    @php
        $date  = fn ($value) => $value ? \Carbon\Carbon::parse($value)->locale('id')->translatedFormat('j M Y') : '—';
        $items = $record->itemExpenses;
    @endphp

    {{-- Gaya bersama halaman advance: ikut mode terang/gelap dan bertumpuk di HP. --}}
    @include('filament.pages.partials.realisasi-styles')

    <div class="flex flex-col gap-6">
        <x-filament::section>
            <div class="flex flex-col gap-5">
                <dl class="rl-figures">
                    <div class="rl-figure">
                        <dt>Diajukan</dt>
                        <dd>{{ \App\Helpers\Money::rupiah($record->amount) }}</dd>
                        <p>{{ $date($record->submission_date) }}</p>
                    </div>
                    <div class="rl-figure">
                        <dt>Dibayarkan</dt>
                        <dd>{{ (float) $record->paid > 0 ? \App\Helpers\Money::rupiah($record->paid) : '—' }}</dd>
                        <p>{{ $record->paidBy ? 'oleh ' . $record->paidBy->fullName : 'Belum dibayar' }}</p>
                    </div>
                </dl>

                <div class="rl-meta">
                    <span>Pemohon <strong>{{ $record->user?->fullName ?? '-' }}</strong></span>
                    <span class="flex items-center gap-2">Status
                        <x-filament::badge :color="\App\Helpers\ColorHelper::status((string) $record->status)" size="sm">{{ $record->status }}</x-filament::badge>
                    </span>
                    @if($record->approvedBy)
                        <span>Disetujui <strong>{{ $record->approvedBy->fullName }}</strong></span>
                    @endif
                </div>

                @if($record->status === 'Ditolak' && filled($record->rejection_reason))
                    <div class="rl-note" data-color="danger" role="status">
                        <strong>Alasan ditolak</strong>
                        <p>{{ $record->rejection_reason }}</p>
                    </div>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section heading="Detail pengajuan">
            <dl class="rl-details">
                <div>
                    <dt>Project</dt>
                    <dd>{{ $project ?? 'Tanpa project' }}</dd>
                </div>
                <div>
                    <dt>Kategori</dt>
                    <dd>{{ $record->category ?: '—' }}</dd>
                </div>
                <div>
                    <dt>Rekening pembayaran</dt>
                    <dd>{{ $record->paymentAccount?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt>No. referensi</dt>
                    <dd>{{ $record->reference ?: '—' }}</dd>
                </div>
                @if(filled($record->keterangan_expense))
                    <div class="span-all">
                        <dt>Keterangan</dt>
                        <dd class="is-plain">{{ $record->keterangan_expense }}</dd>
                    </div>
                @endif
                <div class="span-all">
                    <dt>Lampiran</dt>
                    <dd class="rl-files">
                        @if($record->attachment)
                            <a href="{{ asset('storage/' . $record->attachment) }}" target="_blank" rel="noopener" class="rl-file">
                                <x-filament::icon icon="heroicon-m-document-text" class="h-3.5 w-3.5" /> Invoice / nota
                            </a>
                        @endif
                        @if($record->attachment_pay)
                            <a href="{{ asset('storage/' . $record->attachment_pay) }}" target="_blank" rel="noopener" class="rl-file">
                                <x-filament::icon icon="heroicon-m-banknotes" class="h-3.5 w-3.5" /> Bukti pembayaran
                            </a>
                        @endif
                        @if(! $record->attachment && ! $record->attachment_pay)
                            <span class="text-sm font-normal text-gray-500 dark:text-gray-300">Belum ada lampiran. Tambahkan lewat tombol Edit.</span>
                        @endif
                    </dd>
                </div>
            </dl>
        </x-filament::section>

        @if($items->isNotEmpty())
            <x-filament::section heading="Item" :description="$items->count() . ' item · ' . \App\Helpers\Money::rupiah($items->sum('subtotal'))">
                @include('filament.pages.partials.realisasi-items', ['label' => 'Item expense', 'batch' => [
                    'items' => $items->map(fn ($item) => [
                        'id' => $item->id, 'name' => $item->name, 'description' => $item->description,
                        'quantity' => $item->quantity, 'price' => $item->price, 'subtotal' => $item->subtotal, 'files' => [],
                    ])->all(),
                ]])
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
