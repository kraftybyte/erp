<x-filament::page>
    <div class="space-y-8">

        {{-- =======================
             HEADER SUMMARY
        ======================== --}}
        <div class="bg-white rounded-2xl shadow-sm border p-8">

            <div class="flex items-start justify-between">

                <div>
                    <div class="text-sm text-gray-500 mb-1">
                        Expense ID #{{ $record->id }}
                    </div>

                    <div class="text-4xl font-bold tracking-tight text-gray-900">
                        Rp {{ number_format($record->amount, 0, ',', '.') }}
                    </div>

                    <div class="mt-2 text-sm text-gray-500">
                        {{ \Carbon\Carbon::parse($record->submission_date)->format('d M Y') }}
                    </div>
                </div>

                <div>
                    <span class="
                        inline-flex items-center px-4 py-2 rounded-full text-sm font-semibold
                        @if($record->status === 'Pending') bg-yellow-100 text-yellow-800
                        @elseif($record->status === 'Disetujui') bg-blue-100 text-blue-800
                        @elseif($record->status === 'Ditolak') bg-red-100 text-red-800
                        @elseif($record->status === 'Dibayarkan') bg-gray-200 text-gray-800
                        @elseif($record->status === 'Selesai') bg-green-100 text-green-800
                        @endif
                    ">
                        {{ $record->status }}
                    </span>
                </div>

            </div>
        </div>

        {{-- =======================
             MAIN CONTENT
        ======================== --}}
        <div class="grid grid-cols-3 gap-8">

            {{-- LEFT SIDE --}}
            <div class="col-span-2 space-y-8">

                {{-- INFORMASI --}}
                <div class="bg-white rounded-2xl shadow-sm border p-6">
                    <h3 class="text-lg font-semibold mb-6">Informasi Expense</h3>

                    <div class="grid grid-cols-2 gap-6 text-sm">

                        <div>
                            <div class="text-gray-500 mb-1">Project</div>
                            <div class="font-medium">
                                {{ optional($record->project)->nama_perusahaan }}
                            </div>
                        </div>

                        <div>
                            <div class="text-gray-500 mb-1">Pemohon</div>
                            <div class="font-medium">
                                {{ optional($record->user)->firstname }}
                                {{ optional($record->user)->lastname }}
                            </div>
                        </div>

                    </div>

                    <div class="mt-8">
                        <div class="text-gray-500 mb-2 text-sm">Keterangan</div>
                        <div class="text-gray-800 leading-relaxed">
                            {{ $record->keterangan_expense }}
                        </div>
                    </div>
                </div>

                {{-- ITEM LIST --}}
                @if($record->itemExpenses->count())
                    <div class="bg-white rounded-2xl shadow-sm border p-6">
                        <h3 class="text-lg font-semibold mb-6">Detail Item</h3>

                        <div class="divide-y">

                            @foreach($record->itemExpenses as $item)
                                <div class="py-4 flex justify-between items-start">

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

                                    <div class="font-semibold text-gray-900">
                                        Rp {{ number_format($item->subtotal, 0, ',', '.') }}
                                    </div>

                                </div>
                            @endforeach

                        </div>
                    </div>
                @endif

            </div>

            {{-- RIGHT SIDE --}}
            <div class="space-y-8">

                {{-- APPROVAL --}}
                <div class="bg-white rounded-2xl shadow-sm border p-6">
                    <h3 class="text-lg font-semibold mb-6">Approval</h3>

                    @if($record->status === 'Ditolak')
                        <div class="text-red-600 text-sm mb-4">
                            {{ $record->rejection_reason }}
                        </div>
                    @endif

                    @if($record->paid)
                        <div class="space-y-3 text-sm">

                            <div>
                                <div class="text-gray-500">Nominal Dibayar</div>
                                <div class="font-medium">
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
                        <div class="text-gray-400 text-sm">
                            Belum ada pembayaran.
                        </div>
                    @endif
                </div>

                {{-- ATTACHMENT --}}
                <div class="bg-white rounded-2xl shadow-sm border p-6">
                    <h3 class="text-lg font-semibold mb-6">Lampiran</h3>

                    <div class="space-y-4">

                        @if($record->attachment)
                            <a href="{{ asset('storage/'.$record->attachment) }}"
                               target="_blank"
                               class="block w-full text-center bg-blue-600 hover:bg-blue-700 transition text-white py-2.5 rounded-lg text-sm font-medium">
                                Lihat Invoice
                            </a>
                        @endif

                        @if($record->attachment_pay)
                            <a href="{{ asset('storage/'.$record->attachment_pay) }}"
                               target="_blank"
                               class="block w-full text-center bg-green-600 hover:bg-green-700 transition text-white py-2.5 rounded-lg text-sm font-medium">
                                Lihat Bukti Pembayaran
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
