<x-filament-panels::page>
    @php
        $allItems = $record->realizasis->flatMap->items;
        $totalRealisasi = $allItems->sum('subtotal');
        $paid = (float) ($record->paid ?? 0);
        $remaining = $paid - $totalRealisasi;
        $batchCount = $record->realizasis->count();
        $progress = $paid > 0 ? round($totalRealisasi / $paid * 100) : 0;
        $isOver = $remaining < 0;
        $isComplete = $totalRealisasi >= $paid && $paid > 0;
    @endphp

    <style>
        /* Smooth scrolling and better scrollbar */
        .scrollable-invoices {
            max-height: 500px;
            overflow-y: auto;
            scrollbar-width: thin;
        }
        .scrollable-invoices::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        .scrollable-invoices::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }
        .scrollable-invoices::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }
        .scrollable-invoices::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        
        /* Card hover effect */
        .stat-card {
            transition: all 0.2s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
    </style>

    <div class="space-y-6">
        
        {{-- Header Card with Status and Action --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-md transition-shadow duration-200 p-6">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                <div class="space-y-2">
                    {{-- Status with better visual --}}
                    <div class="flex items-center gap-2">
                        <div class="relative">
                            <span class="flex h-3 w-3">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full 
                                    @if($record->status === 'Pending') bg-yellow-400 opacity-75
                                    @elseif($record->status === 'Disetujui') bg-blue-400 opacity-75
                                    @elseif($record->status === 'Ditolak') bg-red-400 opacity-75
                                    @elseif($record->status === 'Selesai') bg-green-400 opacity-75
                                    @elseif($record->status === 'Sudah di-Realisasi User') bg-indigo-400 opacity-75
                                    @else bg-gray-400 opacity-75
                                    @endif
                                "></span>
                                <span class="relative inline-flex rounded-full h-3 w-3
                                    @if($record->status === 'Pending') bg-yellow-500
                                    @elseif($record->status === 'Disetujui') bg-blue-500
                                    @elseif($record->status === 'Ditolak') bg-red-500
                                    @elseif($record->status === 'Selesai') bg-green-500
                                    @elseif($record->status === 'Sudah di-Realisasi User') bg-indigo-500
                                    @else bg-gray-400
                                    @endif
                                "></span>
                            </span>
                        </div>
                        <span class="text-xs font-semibold uppercase tracking-wider text-gray-500">{{ $record->status }}</span>
                    </div>
                    
                    {{-- Amount and Date --}}
                    <div>
                        <div class="text-3xl font-bold text-gray-900 tracking-tight">Rp {{ number_format($record->amount, 0, ',', '.') }}</div>
                        <div class="flex items-center gap-2 mt-1">
                            <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                            <span class="text-sm text-gray-500">{{ $record->submission_date ? $record->submission_date->format('d F Y') : '-' }}</span>
                        </div>
                    </div>
                </div>
                
                {{-- Action Button --}}
                @if(in_array($record->status, ['Dibayarkan', 'Sudah di-Realisasi User']))
                    <div class="flex-shrink-0">
                        <a href="{{ url('/admin/advances/' . $record->id . '/realisasi') }}" 
                           class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-xl transition-all duration-200 shadow-sm hover:shadow-md">
                            @if($record->status === 'Sudah di-Realisasi User')
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                </svg>
                                <span>Lihat Realisasi</span>
                            @else
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                                <span>Kelola Realisasi</span>
                            @endif
                        </a>
                    </div>
                @endif
            </div>
        </div>

        {{-- Financial Progress Card --}}
        @if($paid > 0)
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                    </svg>
                    <span class="text-xs font-semibold uppercase tracking-wider text-gray-500">Progress Realisasi</span>
                </div>
                <span class="text-sm font-bold 
                    @if($isComplete) text-green-600
                    @elseif($isOver) text-red-600
                    @elseif($progress >= 50) text-indigo-600
                    @else text-amber-600
                    @endif
                ">{{ $progress }}%</span>
            </div>
            
            {{-- Progress Bar with animation --}}
            <div class="relative h-2 bg-gray-100 rounded-full overflow-hidden mb-5">
                <div class="absolute inset-y-0 left-0 rounded-full transition-all duration-700 ease-out
                    @if($isComplete) bg-green-500
                    @elseif($isOver) bg-red-500
                    @elseif($progress >= 50) bg-indigo-500
                    @else bg-amber-500
                    @endif
                " style="width: {{ min($progress, 100) }}%"></div>
            </div>
            
            {{-- Stats Grid --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="stat-card bg-gray-50 rounded-xl p-3 text-center">
                    <div class="text-xs font-medium text-gray-500 mb-1">Total Advance</div>
                    <div class="text-lg font-bold text-gray-800">Rp {{ number_format($record->amount, 0, ',', '.') }}</div>
                </div>
                <div class="stat-card bg-indigo-50 rounded-xl p-3 text-center">
                    <div class="text-xs font-medium text-indigo-600 mb-1">Dibayarkan</div>
                    <div class="text-lg font-bold text-indigo-700">Rp {{ number_format($paid, 0, ',', '.') }}</div>
                </div>
                <div class="stat-card bg-green-50 rounded-xl p-3 text-center">
                    <div class="text-xs font-medium text-green-600 mb-1">Sudah Realisasi</div>
                    <div class="text-lg font-bold text-green-700">Rp {{ number_format($totalRealisasi, 0, ',', '.') }}</div>
                </div>
                <div class="stat-card rounded-xl p-3 text-center
                    @if($remaining > 0) bg-amber-50
                    @elseif($remaining < 0) bg-red-50
                    @else bg-gray-50
                    @endif
                ">
                    <div class="text-xs font-medium mb-1
                        @if($remaining > 0) text-amber-600
                        @elseif($remaining < 0) text-red-600
                        @else text-gray-500
                        @endif
                    ">Sisa Dana</div>
                    <div class="text-lg font-bold
                        @if($remaining > 0) text-amber-700
                        @elseif($remaining < 0) text-red-700
                        @else text-gray-600
                        @endif
                    ">
                        @if($remaining < 0) - @endif
                        Rp {{ number_format(abs($remaining), 0, ',', '.') }}
                    </div>
                </div>
            </div>
            
            {{-- Alert for over payment --}}
            @if($isOver)
            <div class="mt-4 p-3 bg-red-50 border border-red-200 rounded-xl">
                <div class="flex items-start gap-2">
                    <svg class="w-4 h-4 text-red-500 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span class="text-sm text-red-700">Realisasi melebihi nilai advance yang dibayarkan. Kelebihan: Rp {{ number_format(abs($remaining), 0, ',', '.') }}</span>
                </div>
            </div>
            @endif
        </div>
        @endif

        {{-- Detail Information Card - Improved Layout --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="px-6 py-4 bg-gradient-to-r from-gray-50 to-white border-b border-gray-200">
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Informasi Detail</span>
                </div>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    {{-- Left Column --}}
                    <div class="space-y-4">
                        <div class="bg-gray-50 rounded-xl p-4">
                            <div class="flex items-start justify-between">
                                <div class="space-y-1">
                                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wide">Project</div>
                                    <div class="text-base font-semibold text-gray-800">{{ $record->project?->nama_perusahaan ?? '-' }}</div>
                                </div>
                                <div class="w-8 h-8 bg-indigo-100 rounded-lg flex items-center justify-center">
                                    <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                    </svg>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-gray-50 rounded-xl p-4">
                            <div class="flex items-start justify-between">
                                <div class="space-y-1">
                                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wide">Pemohon</div>
                                    <div class="text-base font-semibold text-gray-800">{{ $record->user?->firstname }} {{ $record->user?->lastname }}</div>
                                </div>
                                <div class="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center">
                                    <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    {{-- Right Column --}}
                    <div class="space-y-4">
                        <div class="bg-gray-50 rounded-xl p-4">
                            <div class="flex items-start justify-between">
                                <div class="space-y-1">
                                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wide">Rekening Tujuan</div>
                                    <div class="text-base font-semibold text-gray-800">{{ $record->paymentAccount?->name ?? '-' }}</div>
                                </div>
                                <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center">
                                    <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                                    </svg>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-gray-50 rounded-xl p-4">
                            <div class="flex items-start justify-between">
                                <div class="space-y-1">
                                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wide">Referensi</div>
                                    <div class="text-base font-mono font-semibold text-gray-800">{{ $record->reference ?? '-' }}</div>
                                </div>
                                <div class="w-8 h-8 bg-purple-100 rounded-lg flex items-center justify-center">
                                    <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14"></path>
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                @if($record->keterangan_advance)
                <div class="mt-6 pt-4 border-t border-gray-200">
                    <div class="flex items-start gap-3">
                        <div class="flex-shrink-0">
                            <div class="w-8 h-8 bg-amber-100 rounded-lg flex items-center justify-center">
                                <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                            </div>
                        </div>
                        <div class="flex-1">
                            <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Keterangan Advance</div>
                            <div class="text-sm text-gray-700 leading-relaxed">{{ $record->keterangan_advance }}</div>
                        </div>
                    </div>
                </div>
                @endif

                {{-- Lampiran pengajuan, mis. penawaran/referensi yang dikirim lewat bot Telegram.
                     Bukan @php(...) satu baris: di file yang juga memakai @php ... @endphp, Blade
                     menelan semua isi sampai @endphp berikutnya dan halaman ini error. --}}
                @php $lampiran = $record->attachmentPaths(); @endphp
                @if(count($lampiran))
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach($lampiran as $path)
                    <a href="{{ asset('storage/' . $path) }}"
                       target="_blank"
                       class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 transition text-white px-4 py-2 rounded-lg text-sm font-medium">
                        {{ count($lampiran) > 1 ? 'Lampiran ' . $loop->iteration : 'Lihat Lampiran' }}
                    </a>
                    @endforeach
                </div>
                @endif
            </div>
        </div>

        {{-- Invoices Section --}}
        @if($batchCount > 0)
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="px-6 py-4 bg-gradient-to-r from-gray-50 to-white border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <span class="text-sm font-semibold text-gray-700">Daftar Invoice / Realisasi</span>
                    </div>
                    <span class="text-xs font-medium text-indigo-600 bg-indigo-50 px-2.5 py-1 rounded-full">{{ $batchCount }} Batch</span>
                </div>
            </div>
            <div class="scrollable-invoices divide-y divide-gray-100">
                @foreach($record->realizasis as $batch)
                @php $files = $batch->items->flatMap->files; @endphp
                <div class="p-6 hover:bg-gray-50/50 transition-colors duration-150">
                    {{-- Batch Header --}}
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-indigo-100 flex items-center justify-center shadow-sm">
                                <span class="text-sm font-bold text-indigo-700">#{{ $batch->batch_number }}</span>
                            </div>
                            <div>
                                <div class="text-sm font-semibold text-gray-800">{{ $batch->uploadedBy?->fullName ?? 'Unknown' }}</div>
                                <div class="flex items-center gap-1.5 mt-0.5">
                                    <svg class="w-3 h-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    <span class="text-xs text-gray-500">{{ $batch->created_at ? $batch->created_at->format('d M Y, H:i') : '-' }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-base font-bold text-indigo-700">Rp {{ number_format($batch->getTotalAmount(), 0, ',', '.') }}</div>
                            <div class="text-xs text-gray-500 mt-0.5">{{ $batch->items->count() }} item(s)</div>
                        </div>
                    </div>

                    {{-- Files Attachment --}}
                    @if($files->count())
                    <div class="mb-5 pb-3 border-b border-gray-100">
                        <div class="flex flex-wrap gap-2">
                            @foreach($files as $file)
                            <a href="{{ asset('storage/' . $file->filepath) }}" target="_blank"
                               class="inline-flex items-center gap-2 px-3 py-1.5 bg-white border border-gray-200 rounded-lg hover:bg-indigo-50 hover:border-indigo-200 transition-all duration-150 group">
                                @if($file->isImage())
                                    <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                    </svg>
                                @elseif($file->isPdf())
                                    <svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                                    </svg>
                                @else
                                    <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path>
                                    </svg>
                                @endif
                                <span class="text-sm text-gray-700 group-hover:text-indigo-700 transition">{{ Str::limit($file->original_name, 25) }}</span>
                            </a>
                            @endforeach
                        </div>
                    </div>
                    @endif

                    {{-- Items Table --}}
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200">
                                    <th class="text-left py-2.5 pr-4 font-semibold text-gray-500 text-xs uppercase tracking-wider">Item</th>
                                    <th class="text-center py-2.5 px-3 font-semibold text-gray-500 text-xs uppercase tracking-wider">Qty</th>
                                    <th class="text-right py-2.5 px-3 font-semibold text-gray-500 text-xs uppercase tracking-wider">Harga</th>
                                    <th class="text-right py-2.5 pl-4 font-semibold text-gray-500 text-xs uppercase tracking-wider">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($batch->items as $item)
                                <tr class="hover:bg-gray-50/50 transition-colors">
                                    <td class="py-3 pr-4">
                                        <div class="font-medium text-gray-800">{{ $item->name }}</div>
                                        @if($item->description)
                                            <div class="text-xs text-gray-500 mt-0.5 line-clamp-1">{{ $item->description }}</div>
                                        @endif
                                    </td>
                                    <td class="text-center py-3 px-3 text-gray-600">{{ number_format($item->quantity, 0, ',', '.') }}</td>
                                    <td class="text-right py-3 px-3 text-gray-600">Rp {{ number_format($item->price, 0, ',', '.') }}</td>
                                    <td class="text-right py-3 pl-4 font-semibold text-gray-800">Rp {{ number_format($item->subtotal, 0, ',', '.') }}</td>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
        @else
       <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50/50 px-8 py-12">
    <div class="flex flex-col items-center text-center space-y-4">
        {{-- Icon --}}
        <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-white shadow-sm">
            <svg class="h-8 w-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="1.5"
                    d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                />
            </svg>
        </div>

        {{-- Title --}}
        <h3 class="text-lg font-semibold text-gray-900">
            Belum Ada Invoice
        </h3>

        {{-- Description --}}
        <p class="max-w-md text-sm leading-6 text-gray-500">
            @if($record->status === 'Dibayarkan')
                Upload invoice pertama untuk memulai proses realisasi advance.
            @elseif($record->status === 'Sudah di-Realisasi User')
                Invoice sudah diupload dan menunggu verifikasi oleh tim Finance.
            @else
                Invoice akan muncul setelah status advance berubah menjadi
                <span class="font-medium text-gray-700">"Dibayarkan"</span>.
            @endif
        </p>

        {{-- Button --}}
        @if($record->status === 'Dibayarkan')
            <a
                href="{{ url('/admin/advances/' . $record->id . '/realisasi') }}"
                class="inline-flex h-10 items-center justify-center gap-2 rounded-xl px-5 text-sm font-medium text-white shadow-sm hover:shadow-md transition-all"
                style="background-color: {{ $themeColor ?? '#4f46e5' }}"
            >
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M12 4v16m8-8H4"
                    />
                </svg>
                Upload Invoice Sekarang
            </a>
        @endif
    </div>
</div>
        @endif

        {{-- Payment Information --}}
        @if($record->paid)
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <div class="flex items-center gap-2 mb-4">
                <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Informasi Pembayaran</span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="bg-green-50 rounded-xl p-4 flex items-center justify-between">
                    <span class="text-sm font-medium text-green-700">Jumlah Dibayarkan</span>
                    <span class="text-lg font-bold text-green-800">Rp {{ number_format($record->paid, 0, ',', '.') }}</span>
                </div>
                <div class="bg-gray-50 rounded-xl p-4 flex items-center justify-between">
                    <span class="text-sm font-medium text-gray-700">Dibayar Oleh</span>
                    <span class="text-sm font-semibold text-gray-800">{{ $record->paidBy?->fullName ?? '-' }}</span>
                </div>
            </div>
        </div>
        @endif

    </div>
</x-filament-panels::page>