<x-filament::page>
    <div class="space-y-10">

        {{-- ================= HEADER SUMMARY ================= --}}
        <div class="bg-white rounded-2xl shadow-sm border p-8">
            <div class="flex items-start justify-between">

                <div>
                    <div class="text-sm text-gray-500 mb-2">
                        Advance ID #{{ $record->id }}
                    </div>

                    <div class="text-4xl font-semibold tracking-tight text-gray-900 tabular-nums">
                        Rp {{ number_format($record->amount, 0, ',', '.') }}
                    </div>

                    <div class="mt-3 text-sm text-gray-500">
                        {{ \Carbon\Carbon::parse($record->submission_date)->format('d M Y') }}
                    </div>
                </div>

                <div>
                    <span class="
                        inline-flex items-center px-4 py-2 rounded-full text-sm font-semibold
                        @if($record->status === 'Pending') bg-yellow-100 text-yellow-800
                        @elseif($record->status === 'Disetujui') bg-blue-100 text-blue-800
                        @elseif($record->status === 'Dibayarkan') bg-gray-200 text-gray-800
                        @elseif($record->status === 'Sudah di-Realisasi User') bg-indigo-100 text-indigo-800
                        @elseif($record->status === 'Ditolak') bg-red-100 text-red-800
                        @elseif($record->status === 'Selesai') bg-green-100 text-green-800
                        @endif
                    ">
                        {{ $record->status }}
                    </span>
                </div>

            </div>
        </div>


        {{-- ================= RINGKASAN PENGAJUAN ================= --}}
        <div class="bg-white rounded-2xl shadow-sm border p-8">
            <h3 class="text-lg font-semibold mb-8">Ringkasan Pengajuan</h3>

            <div class="grid grid-cols-3 gap-x-16 gap-y-10">

                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-400 mb-2">
                        Project
                    </div>
                    <div class="text-base font-semibold text-gray-900">
                        {{ optional($record->project)->nama_perusahaan }}
                    </div>
                </div>

                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-400 mb-2">
                        Pemohon
                    </div>
                    <div class="text-base font-semibold text-gray-900">
                        {{ optional($record->user)->firstname }}
                        {{ optional($record->user)->lastname }}
                    </div>
                </div>

                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-400 mb-2">
                        Tanggal Pengajuan
                    </div>
                    <div class="text-base font-semibold text-gray-900">
                        {{ \Carbon\Carbon::parse($record->submission_date)->format('d M Y') }}
                    </div>
                </div>

                <div class="col-span-3">
                    <div class="text-xs uppercase tracking-wide text-gray-400 mb-2">
                        Keterangan
                    </div>
                    <div class="text-base text-gray-800 leading-relaxed">
                        {{ $record->keterangan_advance }}
                    </div>
                </div>

            </div>
        </div>


        {{-- ================= RINGKASAN FINANSIAL ================= --}}
        @php
            $totalRealisasi = $record->itemAdvance->sum('subtotal');
            $selisih = $record->amount - $totalRealisasi;
        @endphp

        <div class="bg-white rounded-2xl shadow-sm border p-8">
            <h3 class="text-lg font-semibold mb-8">Ringkasan Finansial</h3>

            <div class="grid grid-cols-3 gap-x-16">

                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-400 mb-2">
                        Total Advance
                    </div>
                    <div class="text-2xl font-semibold tabular-nums text-gray-900">
                        Rp {{ number_format($record->amount, 0, ',', '.') }}
                    </div>
                </div>

                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-400 mb-2">
                        Total Realisasi
                    </div>
                    <div class="text-2xl font-semibold tabular-nums text-gray-900">
                        Rp {{ number_format($totalRealisasi, 0, ',', '.') }}
                    </div>
                </div>

                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-400 mb-2">
                        Selisih
                    </div>
                    <div class="text-2xl font-semibold tabular-nums
                        @if($selisih < 0) text-red-600
                        @elseif($selisih > 0) text-green-600
                        @else text-gray-900
                        @endif">
                        Rp {{ number_format($selisih, 0, ',', '.') }}
                    </div>
                </div>

            </div>

            @if($selisih < 0)
                <div class="mt-6 text-sm text-red-600">
                    Realisasi melebihi jumlah advance.
                </div>
            @elseif($selisih > 0)
                <div class="mt-6 text-sm text-green-600">
                    Masih ada sisa advance.
                </div>
            @endif
        </div>


        {{-- ================= MAIN GRID ================= --}}
        <div class="grid grid-cols-3 gap-10">

            {{-- LEFT SIDE --}}
            <div class="col-span-2 space-y-8">

                {{-- ITEM REALISASI (SCROLLABLE) --}}
                @if($record->itemAdvance->count())
                    <div class="bg-white rounded-2xl shadow-sm border p-8">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-semibold">Detail Realisasi</h3>
                            <div class="text-sm text-gray-400">
                                {{ $record->itemAdvance->count() }} item
                            </div>
                        </div>

                        <div class="max-h-96 overflow-y-auto pr-2 space-y-4 custom-scroll">

                            @foreach($record->itemAdvance as $item)
                                <div class="border rounded-xl p-5 hover:bg-gray-50 transition">

                                    <div class="flex justify-between items-start">
                                        <div>
                                            <div class="font-semibold text-gray-900">
                                                {{ $item->name }}
                                            </div>

                                            <div class="text-sm text-gray-500 mt-1">
                                                {{ $item->description }}
                                            </div>

                                            <div class="text-xs text-gray-400 mt-2">
                                                {{ $item->quantity }} × 
                                                Rp {{ number_format($item->price, 0, ',', '.') }}
                                            </div>
                                        </div>

                                        <div class="font-semibold text-gray-900 tabular-nums">
                                            Rp {{ number_format($item->subtotal, 0, ',', '.') }}
                                        </div>
                                    </div>

                                </div>
                            @endforeach

                        </div>
                    </div>
                @endif

            </div>


            {{-- RIGHT SIDE --}}
            <div class="space-y-8">

                {{-- PAYMENT INFO --}}
                <div class="bg-white rounded-2xl shadow-sm border p-8">
                    <h3 class="text-lg font-semibold mb-6">Pembayaran</h3>

                    @if($record->paid)
                        <div class="space-y-4 text-sm">
                            <div>
                                <div class="text-gray-500">Nominal Dibayar</div>
                                <div class="font-medium tabular-nums">
                                    Rp {{ number_format($record->paid, 0, ',', '.') }}
                                </div>
                            </div>

                            <div>
                                <div class="text-gray-500">Reference</div>
                                <div class="font-medium">
                                    {{ $record->reference }}
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="text-sm text-gray-400">
                            Belum ada pembayaran.
                        </div>
                    @endif
                </div>


                {{-- ATTACHMENT --}}
                <div class="bg-white rounded-2xl shadow-sm border p-8">
                    <h3 class="text-lg font-semibold mb-6">Lampiran</h3>

                    <div class="space-y-4">

                        @if($record->attachment)
                            <a href="{{ asset('storage/'.$record->attachment) }}"
                               target="_blank"
                               class="block w-full text-center bg-blue-600 hover:bg-blue-700 transition text-white py-2.5 rounded-lg text-sm font-medium">
                                Lihat Invoice Realisasi
                            </a>
                        @endif

                        @if($record->attachment_pay)
                            <a href="{{ asset('storage/'.$record->attachment_pay) }}"
                               target="_blank"
                               class="block w-full text-center bg-green-600 hover:bg-green-700 transition text-white py-2.5 rounded-lg text-sm font-medium">
                                Lihat Bukti Transfer
                            </a>
                        @endif

                        @if(!$record->attachment && !$record->attachment_pay)
                            <div class="text-sm text-gray-400">
                                Tidak ada lampiran.
                            </div>
                        @endif

                    </div>
                </div>

            </div>

        </div>

    </div>
</x-filament::page>
