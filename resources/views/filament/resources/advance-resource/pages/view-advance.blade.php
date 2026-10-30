<x-filament-panels::page>
    @php
            $date       = fn ($value, string $format = 'j M Y') => $value ? \Carbon\Carbon::parse($value)->locale('id')->translatedFormat($format) : '—';
        $over       = $remaining < 0;
        $batchCount = count($batches);
        // Lampiran pengajuan, mis. penawaran/referensi yang dikirim lewat bot Telegram.
        $lampiran   = $record->attachmentPaths();
    @endphp

    @include('filament.pages.partials.realisasi-styles')

    <div class="flex flex-col gap-6">
        {{-- Ringkasan: diajukan, dicairkan, terealisasi, sisa. --}}
        <x-filament::section>
            <div class="flex flex-col gap-5">
                <dl @class(['rl-figures', 'is-4' => $paid > 0])>
                    <div class="rl-figure">
                        <dt>Diajukan</dt>
                        <dd>{{ \App\Helpers\Money::rupiah($record->amount) }}</dd>
                        <p>{{ $date($record->submission_date) }}</p>
                    </div>
                    @if($paid > 0)
                        <div class="rl-figure">
                            <dt>Dicairkan</dt>
                            <dd>{{ \App\Helpers\Money::rupiah($paid) }}</dd>
                            <p>{{ $date($record->paid_at) }}</p>
                        </div>
                        <div class="rl-figure">
                            <dt>Terealisasi</dt>
                            <dd>{{ \App\Helpers\Money::rupiah($realisasi) }}</dd>
                            <p>{{ \App\Helpers\Money::qty(round($progress, 1)) }}% · {{ $batchCount }} batch</p>
                        </div>
                        <div class="rl-figure">
                            <dt>{{ $over ? 'Kelebihan' : 'Sisa' }}</dt>
                            <dd @class(['is-over' => $over])>{{ \App\Helpers\Money::rupiah($remaining) }}</dd>
                            <p>{{ $over ? 'melebihi advance' : 'belum direalisasi' }}</p>
                        </div>
                    @endif
                </dl>

                @if($paid > 0)
                    <div @class(['rl-progress', 'is-over' => $over]) role="progressbar" aria-label="Progres realisasi"
                         aria-valuenow="{{ round($progress) }}" aria-valuemin="0" aria-valuemax="100">
                        <span style="width: {{ min(max($progress, 0), 100) }}%"></span>
                    </div>
                @endif

                <div class="rl-meta">
                    <span>Pemohon <strong>{{ $requester }}</strong></span>
                    <span class="flex items-center gap-2">Status
                        <x-filament::badge :color="$record->getStatusBadgeClass()" size="sm">{{ $record->getStatusLabel() }}</x-filament::badge>
                    </span>
                </div>

                @if($record->status === \App\Models\Advance::STATUS_DITOLAK && filled($record->rejection_reason))
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
                    <dt>Dibayar oleh</dt>
                    <dd>{{ $record->paidBy?->fullName ?? '—' }}</dd>
                </div>
                @if(filled($record->reference_number))
                    <div>
                        <dt>No. referensi</dt>
                        <dd>{{ $record->reference_number }}</dd>
                    </div>
                @endif
                @if(filled($record->keterangan_advance))
                    <div class="span-all">
                        <dt>Keterangan</dt>
                        <dd class="is-plain">{{ $record->keterangan_advance }}</dd>
                    </div>
                @endif
                @if(filled($record->realisasi_notes))
                    <div class="span-all">
                        <dt>Catatan realisasi</dt>
                        <dd class="is-plain">{{ $record->realisasi_notes }}</dd>
                    </div>
                @endif
                @if(count($lampiran))
                    <div class="span-all">
                        <dt>Lampiran pengajuan</dt>
                        <dd class="rl-files">
                            @foreach($lampiran as $path)
                                <a href="{{ asset('storage/' . $path) }}" target="_blank" rel="noopener" class="rl-file">
                                    <x-filament::icon icon="heroicon-m-paper-clip" class="h-3.5 w-3.5" />
                                    {{ count($lampiran) > 1 ? 'Lampiran ' . $loop->iteration : 'Lihat Lampiran' }}
                                </a>
                            @endforeach
                        </dd>
                    </div>
                @endif
            </dl>
        </x-filament::section>

        {{-- Semua batch dalam satu section, tanpa kotak gulir di dalam halaman
             (dulu dibatasi 500px sehingga batch berikutnya terpotong). --}}
        <x-filament::section heading="Realisasi"
                             :description="$batchCount ? $batchCount . ' batch · ' . \App\Helpers\Money::rupiah($realisasi) : null">
            @forelse($batches as $batch)
                <div class="rl-batch" wire:key="batch-{{ $batch['id'] }}">
                    <div class="rl-batch-head">
                        <div>
                            <strong>Batch #{{ $batch['batch_number'] }}</strong>
                            <span>· {{ $batch['uploaded_by_name'] }} · {{ $date($batch['created_at'], 'j M Y, H.i') }}</span>
                            @if(filled($batch['notes']))
                                <span class="block">{{ $batch['notes'] }}</span>
                            @endif
                        </div>
                        <span class="total">{{ \App\Helpers\Money::rupiah($batch['total_amount']) }}</span>
                    </div>

                    @include('filament.pages.partials.realisasi-items', ['batch' => $batch])
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $emptyText }}</p>
            @endforelse
        </x-filament::section>
    </div>
</x-filament-panels::page>
