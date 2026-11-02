@php
    $rp = fn ($value) => \App\Helpers\Money::rupiah($value);
    $tanggal = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->locale('id')->translatedFormat('j M') : '—';
@endphp

<x-filament-panels::page>
    {{-- Hanya tata letak; warna dari variabel tema Filament (Tailwind di
         public/build tidak dikompilasi ulang, kelas utilitas baru tidak ada). --}}
    <style>
        .fk-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem 1.5rem; }
        .fk-month { display: flex; align-items: center; gap: .25rem; }
        .fk-month strong { min-width: 9rem; text-align: center; font-size: 1rem; font-weight: 600; color: rgb(var(--gray-950)); }
        .fk-check { display: inline-flex; align-items: center; gap: .5rem; font-size: .875rem; color: rgb(var(--gray-700)); cursor: pointer; }
        .dark .fk-month strong { color: #fff; }
        .dark .fk-check { color: rgb(var(--gray-200)); }

        .fk-figures { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: 1.25rem 1.5rem; }
        .fk-figures dt { font-size: .875rem; color: rgb(var(--gray-500)); }
        .fk-figures dd { margin: .25rem 0 0; font-size: 1.5rem; line-height: 2rem; font-weight: 600; color: rgb(var(--gray-950)); font-variant-numeric: tabular-nums; }
        .fk-figures dd.is-missing { color: rgb(var(--warning-700)); }
        .dark .fk-figures dt { color: rgb(var(--gray-300)); }
        .dark .fk-figures dd { color: #fff; }
        .dark .fk-figures dd.is-missing { color: rgb(var(--warning-400)); }
        .fk-explain { margin-top: 1rem; font-size: .8125rem; line-height: 1.5; color: rgb(var(--gray-500)); }
        .dark .fk-explain { color: rgb(var(--gray-300)); }

        /* Di layar sempit tabel digeser ke samping di dalam kotaknya. */
        .fk-table-wrap { overflow-x: auto; margin: -.5rem 0; }
        .fk-table { width: 100%; min-width: 50rem; font-size: .875rem; border-collapse: collapse; }
        .fk-table th { padding: .5rem .75rem .5rem 0; text-align: left; font-weight: 500; color: rgb(var(--gray-500)); white-space: nowrap; }
        .fk-table td { padding: .625rem .75rem .625rem 0; border-top: 1px solid rgba(var(--gray-950), .06); color: rgb(var(--gray-950)); vertical-align: top; }
        .fk-table .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .fk-table .muted { color: rgb(var(--gray-500)); }
        .fk-table td.muted { white-space: nowrap; }
        .fk-table a { color: rgb(var(--primary-600)); font-weight: 500; }
        .fk-table a:hover { text-decoration: underline; }
        .fk-table tfoot td { font-weight: 600; }
        .fk-table .fk-faktur { width: 15rem; }
        .fk-missing { margin-top: .25rem; font-size: .75rem; font-weight: 500; color: rgb(var(--warning-700)); }
        .fk-table a.fk-pdf { display: inline-block; margin-top: .25rem; font-size: .8125rem; }
        .fk-table tr.fk-note-row td { border-top: 0; padding-top: 0; }
        .fk-note { font-size: .8125rem; color: rgb(var(--warning-700)); }
        .fk-files { display: flex; flex-wrap: wrap; gap: .25rem .75rem; }
        .dark .fk-table th, .dark .fk-table .muted { color: rgb(var(--gray-300)); }
        .dark .fk-table td { color: #fff; border-color: rgba(255, 255, 255, .08); }
        .dark .fk-table a { color: rgb(var(--primary-400)); }
        .dark .fk-missing, .dark .fk-note { color: rgb(var(--warning-400)); }
        .fk-empty { font-size: .875rem; color: rgb(var(--gray-500)); }
        .dark .fk-empty { color: rgb(var(--gray-300)); }
    </style>

    <div class="flex flex-col gap-6">
        <div class="fk-bar">
            <div class="fk-month">
                <x-filament::icon-button icon="heroicon-m-chevron-left" color="gray" wire:click="geserBulan(-1)" label="Bulan sebelumnya" />
                <strong>{{ $periode }}</strong>
                <x-filament::icon-button icon="heroicon-m-chevron-right" color="gray" wire:click="geserBulan(1)" label="Bulan berikutnya" />
            </div>

            <label class="fk-check">
                <x-filament::input.checkbox wire:model.live="belumAda" />
                Hanya yang belum ada faktur
            </label>
        </div>

        <x-filament::tabs label="Jenis faktur">
            <x-filament::tabs.item :active="$tab === 'keluaran'" wire:click="$set('tab', 'keluaran')"
                :badge="$ringkas['keluaranBelumAda'] ?: null" badge-color="warning">
                Keluaran
            </x-filament::tabs.item>
            <x-filament::tabs.item :active="$tab === 'masukan'" wire:click="$set('tab', 'masukan')"
                :badge="$ringkas['masukanBelumAda'] ?: null" badge-color="warning">
                Masukan
            </x-filament::tabs.item>
        </x-filament::tabs>

        @if ($tab === 'keluaran')
            <x-filament::section>
                <dl class="fk-figures">
                    <div><dt>Invoice ber-PPN</dt><dd>{{ $ringkas['keluaran'] }}</dd></div>
                    <div><dt>DPP</dt><dd>{{ $rp($ringkas['dpp']) }}</dd></div>
                    <div><dt>PPN keluaran</dt><dd>{{ $rp($ringkas['ppn']) }}</dd></div>
                    <div><dt>Belum ada nomor faktur</dt><dd @class(['is-missing' => $ringkas['keluaranBelumAda']])>{{ $ringkas['keluaranBelumAda'] }}</dd></div>
                </dl>
                <p class="fk-explain">
                    Invoice penjualan ber-PPN menurut tanggal invoice, tanpa proforma dan yang dibatalkan.
                    Invoice DP dan pelunasan dihitung dari porsi yang ditagih, jadi PPN satu pekerjaan tidak terhitung dua kali.
                    Nomor faktur tersimpan begitu kolomnya ditinggalkan, atau upload PDF faktur pajaknya lewat <strong>Upload faktur</strong>: nomornya terisi sendiri.
                </p>
            </x-filament::section>

            <x-filament::section>
                @if ($keluaran->isEmpty())
                    <p class="fk-empty">
                        {{ $belumAda && $ringkas['keluaran'] ? "Semua invoice ber-PPN pada {$periode} sudah punya nomor faktur." : "Tidak ada invoice ber-PPN pada {$periode}." }}
                    </p>
                @else
                    <div class="fk-table-wrap">
                        <table class="fk-table">
                            <thead>
                                <tr><th>Tanggal</th><th>Invoice</th><th>Jenis</th><th class="num">DPP</th><th class="num">PPN</th><th>No. faktur pajak</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($keluaran as $row)
                                    @php($invoice = $row['invoice'])
                                    <tr wire:key="inv-{{ $invoice->id }}">
                                        <td class="muted">{{ $tanggal($invoice->date) }}</td>
                                        <td>
                                            <a href="{{ $this->invoiceUrl($invoice) }}">{{ $invoice->uuid }}</a>
                                            <div class="muted">{{ $invoice->nama_perusahaan ?: '—' }}</div>
                                        </td>
                                        <td>{{ $row['kind'] }}</td>
                                        <td class="num">{{ $rp($row['dpp']) }}</td>
                                        <td class="num">{{ $rp($row['ppn']) }}</td>
                                        <td class="fk-faktur">
                                            <x-filament::input.wrapper>
                                                <x-filament::input
                                                    type="text"
                                                    maxlength="100"
                                                    :value="$invoice->faktur"
                                                    placeholder="Isi nomor faktur"
                                                    aria-label="Nomor faktur pajak {{ $invoice->uuid }}"
                                                    wire:change="simpanFaktur({{ $invoice->id }}, $event.target.value)"
                                                />
                                            </x-filament::input.wrapper>
                                            @unless ($row['faktur'])
                                                <div class="fk-missing">Belum ada nomor faktur</div>
                                            @endunless
                                            @if ($invoice->faktur_file)
                                                <a class="fk-pdf" href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($invoice->faktur_file) }}" target="_blank" rel="noopener">Lihat file faktur pajak</a>
                                            @endif
                                        </td>
                                    </tr>
                                    @if ($row['note'])
                                        <tr class="fk-note-row" wire:key="inv-note-{{ $invoice->id }}">
                                            <td></td>
                                            <td colspan="5" class="fk-note">{{ $row['note'] }}</td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr><td></td><td>Total</td><td></td><td class="num">{{ $rp($keluaran->sum('dpp')) }}</td><td class="num">{{ $rp($keluaran->sum('ppn')) }}</td><td></td></tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        @else
            <x-filament::section>
                <dl class="fk-figures">
                    <div><dt>Pengeluaran</dt><dd>{{ $ringkas['masukan'] }}</dd></div>
                    <div><dt>Nominal</dt><dd>{{ $rp($ringkas['nominal']) }}</dd></div>
                    <div><dt>Belum ada faktur</dt><dd @class(['is-missing' => $ringkas['masukanBelumAda']])>{{ $ringkas['masukanBelumAda'] }}</dd></div>
                </dl>
                <p class="fk-explain">
                    Nota Expense (tanpa yang ditolak) menurut tanggal pengajuan, dan struk Realisasi Advance menurut tanggal batch-nya.
                    Yang belum ada fakturnya bisa di-upload langsung lewat tombol <strong>Upload</strong>.
                    Tombol <strong>Unduh ZIP</strong> mengumpulkan semua file-nya beserta daftar Excel.
                </p>
            </x-filament::section>

            <x-filament::section>
                @if ($masukan->isEmpty())
                    <p class="fk-empty">
                        {{ $belumAda && $ringkas['masukan'] ? "Semua pengeluaran pada {$periode} sudah ada fakturnya." : "Tidak ada expense atau realisasi pada {$periode}." }}
                    </p>
                @else
                    <div class="fk-table-wrap">
                        <table class="fk-table">
                            <thead>
                                <tr><th>Tanggal</th><th>Sumber</th><th>Pengaju</th><th>Keterangan</th><th class="num">Nominal</th><th>Faktur</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($masukan as $i => $row)
                                    @php($ada = collect($row['files'])->where('exists', true)->values())
                                    <tr wire:key="msk-{{ $row['source'] }}-{{ $row['record']->id }}-{{ $i }}">
                                        <td class="muted">{{ $tanggal($row['date']) }}</td>
                                        <td>{{ $row['source'] }}</td>
                                        <td>{{ $row['user'] }}</td>
                                        <td>
                                            <a href="{{ $this->recordUrl($row) }}">{{ \Illuminate\Support\Str::limit($row['description'] ?: '—', 70) }}</a>
                                            <div class="muted">{{ $row['project'] }}</div>
                                        </td>
                                        <td class="num">{{ $rp($row['amount']) }}</td>
                                        <td>
                                            @if ($ada->isNotEmpty())
                                                <div class="fk-files">
                                                    @foreach ($ada as $n => $file)
                                                        <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($file['path']) }}" target="_blank" rel="noopener">
                                                            {{ $ada->count() > 1 ? 'File ' . ($n + 1) : 'Lihat' }}
                                                        </a>
                                                    @endforeach
                                                </div>
                                            @else
                                                <div class="fk-missing">{{ $row['files'] ? 'File tidak ditemukan di server' : 'Belum ada' }}</div>
                                                {{ ($this->uploadAction)(['target' => $row['target'], 'label' => $row['source'] . ' · ' . \Illuminate\Support\Str::limit($row['description'] ?: '—', 60) . ' · ' . $rp($row['amount'])]) }}
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr><td></td><td></td><td></td><td>Total</td><td class="num">{{ $rp($masukan->sum('amount')) }}</td><td></td></tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
