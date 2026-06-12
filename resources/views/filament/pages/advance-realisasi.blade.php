<x-filament-panels::page>
    @php
        $themeColor = '#90c646';
        $themeColorDark = '#6fa335';
    @endphp

    <style>
        .realisasi-page {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
        }

        /* ── Progress ── */
        .progress-bar {
            height: 10px;
            border-radius: 999px;
            background: #e9ecef;
            overflow: hidden;
        }
        .progress-bar-fill {
            height: 100%;
            border-radius: 999px;
            transition: width 0.4s ease;
        }

        /* ── Cards ── */
        .card {
            background: #ffffff;
            border: 1px solid #e8eaed;
            border-radius: 14px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.05);
        }
        .card-header {
            padding: 18px 22px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* ── Stat blocks ── */
        .stat-block {
            background: #ffffff;
            border-radius: 12px;
            padding: 18px 20px;
            border: 1px solid #e8eaed;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .stat-block .stat-label {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: #9aa0ab;
            margin-bottom: 6px;
        }
        .stat-block .stat-value {
            font-size: 22px;
            font-weight: 700;
            color: #1a1d23;
            line-height: 1.2;
        }

        /* ── Batch card ── */
        .batch-card {
            background: #ffffff;
            border: 1px solid #e8eaed;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 1px 4px rgba(0,0,0,0.05);
            transition: box-shadow 0.2s;
        }
        .batch-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.08);
        }
        .batch-header {
            background: #fafbfc;
            padding: 14px 20px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* ── Item rows ── */
        .item-header-row {
            display: grid;
            grid-template-columns: 1fr 70px 130px 150px 60px;
            gap: 12px;
            padding: 10px 20px;
            background: #f8f9fb;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: .05em;
            text-transform: uppercase;
            color: #9aa0ab;
            border-bottom: 1px solid #f0f2f5;
        }
        .item-row {
            display: grid;
            grid-template-columns: 1fr 70px 130px 150px 60px;
            gap: 12px;
            padding: 14px 20px;
            border-bottom: 1px solid #f5f6f8;
            align-items: center;
            transition: background 0.15s;
        }
        .item-row:last-child { border-bottom: none; }
        .item-row:hover { background: #fafbfc; }

        /* ── Buttons ── */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 18px;
            border-radius: 9px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.18s;
            white-space: nowrap;
        }
        .btn-primary {
            background: {{ $themeColor }};
            color: #ffffff;
        }
        .btn-primary:hover {
            background: {{ $themeColorDark }};
            box-shadow: 0 4px 12px rgba(144,198,70,0.35);
        }
        .btn-warning {
            background: #fff3cd;
            color: #b45309;
            border: 1px solid #fde68a;
        }
        .btn-warning:hover {
            background: #fde68a;
        }
        .btn-danger {
            background: #fff1f1;
            color: #dc2626;
            border: 1px solid #fecaca;
        }
        .btn-danger:hover { background: #fee2e2; }
        .btn-ghost {
            background: transparent;
            color: #6b7280;
            border: 1px solid #e5e7eb;
        }
        .btn-ghost:hover { background: #f3f4f6; }
        .btn-icon {
            padding: 7px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            transition: all 0.15s;
            background: transparent;
            color: #9ca3af;
        }
        .btn-icon:hover {
            background: #fff1f1;
            color: #dc2626;
        }

        /* ── Badges ── */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .02em;
        }
        .badge::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
        }
        .badge-success  { background: #e8faf0; color: #15803d; }
        .badge-success::before { background: #22c55e; }
        .badge-warning  { background: #fef9ec; color: #b45309; }
        .badge-warning::before { background: #f59e0b; }
        .badge-danger   { background: #fff1f1; color: #dc2626; }
        .badge-danger::before { background: #ef4444; }
        .badge-info     { background: #eff6ff; color: #1d4ed8; }
        .badge-info::before { background: #3b82f6; }
        .badge-done     { background: #e8faf0; color: #15803d; }
        .badge-done::before { background: #22c55e; }
        .badge-locked   { background: #f3f4f6; color: #6b7280; }
        .badge-locked::before { background: #9ca3af; }

        /* ── File badge ── */
        .file-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            background: #eff6ff;
            color: #2563eb;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid #bfdbfe;
            transition: background .15s;
        }
        .file-badge:hover { background: #dbeafe; }

        /* ── Status bar ── */
        .status-bar {
            background: #ffffff;
            border-top: 1px solid #f0f2f5;
            padding: 16px 22px;
        }

        /* ── Dropzone ── */
        .dropzone {
            border: 2px dashed #d1d5db;
            border-radius: 12px;
            padding: 32px;
            text-align: center;
            transition: all 0.2s;
            cursor: pointer;
            background: #fafbfc;
        }
        .dropzone:hover, .dropzone.dragover {
            border-color: {{ $themeColor }};
            background: rgba(144, 198, 70, 0.04);
        }

        /* ── Modal ── */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,0.55);
            backdrop-filter: blur(4px);
            z-index: 50;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .modal-content {
            background: #ffffff;
            border-radius: 18px;
            width: 100%;
            max-height: 92vh;
            overflow-y: auto;
            box-shadow: 0 32px 64px rgba(0,0,0,0.22);
        }
        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            background: #ffffff;
            z-index: 10;
        }

        /* ── Empty state ── */
        .empty-state {
            background: #ffffff;
            border: 1px solid #e8eaed;
            border-radius: 14px;
            padding: 56px 24px;
            text-align: center;
            box-shadow: 0 1px 4px rgba(0,0,0,0.05);
        }

        /* ── Item input card ── */
        .item-input-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 16px;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .item-input-card:hover {
            border-color: #d1d5db;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .form-input {
            width: 100%;
            padding: 9px 13px;
            font-size: 14px;
            border: 1px solid #e5e7eb;
            border-radius: 9px;
            outline: none;
            transition: border-color 0.15s, box-shadow 0.15s;
            background: #ffffff;
            color: #1a1d23;
        }
        .form-input:focus {
            border-color: {{ $themeColor }};
            box-shadow: 0 0 0 3px rgba(144,198,70,0.14);
        }
        .form-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            margin-bottom: 5px;
        }
        .form-textarea {
            width: 100%;
            padding: 9px 13px;
            font-size: 14px;
            border: 1px solid #e5e7eb;
            border-radius: 9px;
            outline: none;
            resize: vertical;
            transition: border-color 0.15s, box-shadow 0.15s;
            background: #ffffff;
            color: #1a1d23;
        }
        .form-textarea:focus {
            border-color: {{ $themeColor }};
            box-shadow: 0 0 0 3px rgba(144,198,70,0.14);
        }

        /* ── Custom Scrollbar untuk Item List ── */
        .items-scroll-container {
            max-height: 460px;
            overflow-y: auto;
            overflow-x: hidden;
            padding-right: 4px;
        }
        
        .items-scroll-container::-webkit-scrollbar {
            width: 6px;
        }
        
        .items-scroll-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .items-scroll-container::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 10px;
        }
        
        .items-scroll-container::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
        
        /* Fixed bottom total */
        .total-sticky {
            position: sticky;
            bottom: 0;
            z-index: 2;
        }
    </style>

    <div class="realisasi-page max-w-5xl mx-auto px-4 py-6 space-y-5">

        {{-- ── Header ── --}}
        <div class="flex items-center justify-between">
            <div>
                <a href="{{ url()->previous() }}" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-800 mb-3 transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                    Kembali
                </a>
                <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Realisasi Advance</h1>
            </div>

            @if($record->status === 'Selesai')
                <span class="badge badge-done text-sm px-4 py-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                    </svg>
                    Realisasi Selesai
                </span>
            @elseif($progressPercentage >= 100)
                <span class="badge badge-locked text-sm px-4 py-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                    Tidak Dapat Diedit
                </span>
            @else
                <button wire:click="openBatchModal" class="btn btn-primary">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                    </svg>
                    Tambah Batch
                </button>
            @endif
        </div>

        {{-- ── Info Card ── --}}
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="text-base font-bold text-gray-900">{{ $record->keterangan_advance }}</h2>
                    @if($record->project)
                        <p class="text-sm text-gray-400 mt-0.5">{{ $record->project->name }}</p>
                    @endif
                </div>
                <div class="text-right">
                    <p class="text-xs text-gray-400 font-medium uppercase tracking-wide mb-1">Total Advance</p>
                    <p class="text-2xl font-bold" style="color: {{ $themeColor }};">
                        Rp {{ number_format($record->paid, 0, ',', '.') }}
                    </p>
                </div>
            </div>

            <div class="px-6 py-5 border-b border-gray-100">
                {{-- Progress --}}
                <div class="flex justify-between items-center mb-2">
                    <span class="text-sm font-semibold text-gray-700">Progress Realisasi</span>
                    <span class="text-sm font-bold" style="color: {{ $themeColor }};">{{ $progressPercentage }}%</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-bar-fill
                        @if($progressPercentage >= 100) bg-green-500
                        @elseif($progressPercentage >= 80) bg-amber-400
                        @else bg-blue-500 @endif"
                        style="width: {{ min($progressPercentage, 100) }}%"></div>
                </div>
            </div>

            {{-- Stats Grid --}}
            <div class="grid grid-cols-3 gap-4 p-5">
                <div class="stat-block">
                    <div class="stat-label">Total Realisasi</div>
                    <div class="stat-value">Rp {{ number_format($totalRealisasi, 0, ',', '.') }}</div>
                </div>
                <div class="stat-block @if($remaining < 0) border-red-200 @endif" style="@if($remaining < 0) background: #fff8f8; @endif">
                    <div class="stat-label">Sisa</div>
                    <div class="stat-value @if($remaining < 0) text-red-600 @endif">
                        Rp {{ number_format(abs($remaining), 0, ',', '.') }}
                    </div>
                    @if($remaining < 0)
                        <span class="badge badge-danger mt-2" style="font-size:11px;">Overrun</span>
                    @endif
                </div>
                <div class="stat-block">
                    <div class="stat-label">Total Batch</div>
                    <div class="stat-value">{{ count($batches) }}</div>
                </div>
            </div>

            {{-- Status Bar --}}
            <div class="status-bar">
                <div class="flex items-center justify-between flex-wrap gap-3">
                    {{-- Status info --}}
                    <div class="flex items-center gap-4">
                        @switch($status)
                            @case('exact_match')
                                <span class="badge badge-success">Exact Match</span>
                                <span class="text-sm text-gray-500">{{ $statusMessage }}</span>
                                @break
                            @case('under_run')
                                <span class="badge badge-danger">Menunggu Return</span>
                                <div>
                                    <div class="text-xs text-gray-400">Sisa yang harus dikembalikan</div>
                                    <div class="text-lg font-bold text-red-600">Rp {{ number_format(abs($remaining), 0, ',', '.') }}</div>
                                </div>
                                @break
                            @case('over_run')
                                <span class="badge badge-warning">Overrun</span>
                                <div>
                                    <div class="text-xs text-gray-400">Realisasi melebihi advance</div>
                                    <div class="text-lg font-bold text-amber-600">Rp {{ number_format(abs($remaining), 0, ',', '.') }}</div>
                                </div>
                                @break
                        @endswitch
                    </div>

                    {{-- Action buttons --}}
                    <div class="flex gap-2">
                        @if($status === 'under_run' && auth()->user()->hasRole(['Finance', 'Director', 'super_admin']))
                            <button wire:click="openReturnModal" class="btn btn-primary">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                Konfirmasi Return
                            </button>
                        @endif

                        @if($status === 'over_run' && auth()->user()->hasRole(['Finance', 'Director', 'super_admin']))
                            <button wire:click="approveOverrun" class="btn btn-warning">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                Setujui Overrun
                            </button>
                        @endif

                        @if((in_array($status, ['exact_match']) || $record->overrun_approved_by) && auth()->user()->hasRole(['Finance', 'Director', 'super_admin']))
                            <button wire:click="finishAdvance" class="btn btn-primary">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                                Selesaikan
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Batch List ── --}}
        <div class="space-y-4">
            @forelse($batches as $batch)
                <div class="batch-card">
                    <div class="batch-header">
                        <div class="flex items-center gap-3">
                            <span class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold text-white flex-shrink-0"
                                  style="background: {{ $themeColor }};">
                                {{ $batch['batch_number'] }}
                            </span>
                            <div>
                                <p class="font-bold text-gray-900 text-sm">Batch #{{ $batch['batch_number'] }}</p>
                                <p class="text-xs text-gray-400 mt-0.5">
                                    {{ $batch['uploaded_by_name'] }} &bull;
                                    {{ \Carbon\Carbon::parse($batch['created_at'])->format('d M Y H:i') }}
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-base font-bold" style="color: {{ $themeColor }};">
                                Rp {{ number_format($batch['total_amount'], 0, ',', '.') }}
                            </span>
                            @if($record->isEditable() && $record->canRealisasi())
                                <button wire:click="deleteBatch({{ $batch['id'] }})"
                                        wire:confirm="Yakin hapus batch ini?"
                                        class="btn-icon">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </button>
                            @endif
                        </div>
                    </div>

                    {{-- Table --}}
                    <div class="item-header-row">
                        <div>Item</div>
                        <div class="text-center">Qty</div>
                        <div class="text-right">Harga</div>
                        <div class="text-right">Subtotal</div>
                        <div class="text-center">File</div>
                    </div>

                    @foreach($batch['items'] as $item)
                        <div class="item-row">
                            <div>
                                <p class="font-semibold text-gray-900 text-sm">{{ $item['name'] }}</p>
                                @if(!empty($item['description']))
                                    <p class="text-xs text-gray-400 mt-0.5">{{ $item['description'] }}</p>
                                @endif
                                @if(count($item['files']) > 0)
                                    <div class="flex flex-wrap gap-1.5 mt-2">
                                        @foreach(array_slice($item['files'], 0, 5) as $file)
                                            <a href="{{ url('storage/' . $file['filepath']) }}"
                                               target="_blank"
                                               class="file-badge">
                                                @if($file['is_image'])
                                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                                    </svg>
                                                @else
                                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                                    </svg>
                                                @endif
                                                {{ Str::limit($file['original_name'], 14) }}
                                            </a>
                                        @endforeach
                                        @if(count($item['files']) > 5)
                                            <span class="badge badge-info" style="font-size:11px;">+{{ count($item['files']) - 5 }} more</span>
                                        @endif
                                    </div>
                                @endif
                            </div>
                            <div class="text-center text-sm text-gray-700">{{ $item['quantity'] }}</div>
                            <div class="text-right text-sm text-gray-700">Rp {{ number_format((float) $item['price'], 0, ',', '.') }}</div>
                            <div class="text-right text-sm font-bold text-gray-900">Rp {{ number_format((float) $item['subtotal'], 0, ',', '.') }}</div>
                            <div class="text-center">
                                <span class="text-xs font-semibold text-gray-400">{{ count($item['files']) }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @empty
                <div class="empty-state">
                    <div class="w-16 h-16 rounded-2xl flex items-center justify-center mx-auto mb-4 bg-gray-100">
                        <svg class="w-8 h-8 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                    </div>
                    <h3 class="text-base font-bold text-gray-900 mb-1">Belum ada batch</h3>
                    <p class="text-sm text-gray-400 mb-5">Mulai upload invoice dan bukti pembayaran</p>
                    @if($record->isEditable() && $record->canRealisasi())
                        <button wire:click="openBatchModal" class="btn btn-primary mx-auto">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                            </svg>
                            Tambah Batch Pertama
                        </button>
                    @endif
                </div>
            @endforelse
        </div>
    </div>

    {{-- MODAL: Add Batch --}}
    @if($showBatchModal)
        <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" wire:click.self="closeBatchModal">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col" wire:click.stop>

                {{-- Header --}}
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between flex-shrink-0">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Tambah Batch Baru</h2>
                        <p class="text-sm text-gray-500 mt-0.5">Catat pengeluaran dan upload bukti</p>
                    </div>
                    <button wire:click="closeBatchModal" class="p-2 hover:bg-gray-100 rounded-lg transition-colors">
                        <svg class="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                {{-- Scrollable Content --}}
                <div class="flex-1 overflow-y-auto p-6">
                    <form wire:submit="saveBatch" class="space-y-5" id="batchForm">

                        {{-- Upload File --}}
                        <div x-data="{ isDragging: false }">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Bukti Pengeluaran</label>
                            <div class="border-2 border-dashed border-gray-200 rounded-xl p-5 text-center hover:border-gray-300 transition-colors cursor-pointer"
                                 x-on:dragover.prevent="isDragging = true"
                                 x-on:dragleave="isDragging = false"
                                 x-on:drop.prevent="isDragging = false"
                                 :class="isDragging ? 'border-green-400 bg-green-50/50' : ''">
                                <input type="file" multiple wire:model="uploadedFiles" accept=".pdf,.jpg,.jpeg,.png" class="hidden" id="file-upload">
                                <label for="file-upload" class="cursor-pointer">
                                    <div class="w-10 h-10 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-2">
                                        <svg class="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                        </svg>
                                    </div>
                                    <p class="text-sm font-medium text-gray-600">Klik untuk upload file</p>
                                    <p class="text-xs text-gray-400 mt-0.5">atau drag & drop file ke sini</p>
                                </label>
                            </div>

                            @if(count($uploadedFiles) > 0)
                                <div class="mt-2 space-y-2 max-h-32 overflow-y-auto">
                                    @foreach($uploadedFiles as $index => $file)
                                        <div class="flex items-center gap-3 p-2 bg-gray-50 rounded-lg text-sm">
                                            <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                            </svg>
                                            <span class="flex-1 truncate">{{ $file->getClientOriginalName() }}</span>
                                            <span class="text-xs text-gray-400">{{ number_format($file->getSize() / 1024, 0) }} KB</span>
                                            <button type="button" wire:click="removeUploadedFile({{ $index }})" class="text-red-400 hover:text-red-600">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                </svg>
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        {{-- Items with Scroll Container --}}
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <h3 class="text-sm font-semibold text-gray-900">Daftar Pengeluaran</h3>
                                <button type="button" wire:click="addBatchItem" class="inline-flex items-center gap-1 text-sm font-medium px-3 py-1.5 rounded-lg transition-colors" style="background: {{ $themeColor }}; color: white;">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                    </svg>
                                    Tambah
                                </button>
                            </div>

                            {{-- Scrollable items container --}}
                            <div class="pr-1 overflow-y-auto" style="max-height: 224px;">
                                @foreach($batchItems as $index => $item)
                                    <div wire:key="batch-item-{{ $index }}" class="bg-gray-50 rounded-xl p-3 mb-2">
                                        <div class="flex items-center justify-between mb-2">
                                            <span class="text-xs font-medium text-gray-500">Item {{ $index + 1 }}</span>
                                            @if(count($batchItems) > 1)
                                                <button type="button" wire:click="removeBatchItem({{ $index }})" class="p-1 hover:bg-red-50 rounded">
                                                    <svg class="w-3.5 h-3.5 text-gray-400 hover:text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                    </svg>
                                                </button>
                                            @endif
                                        </div>

                                        <div class="space-y-2">
                                            <input type="text" wire:model="batchItems.{{ $index }}.name" placeholder="Nama pengeluaran"
                                                   class="w-full px-3 py-1.5 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500/20 focus:border-green-400">

                                            <div class="grid grid-cols-2 gap-2">
                                                <input type="number" wire:model="batchItems.{{ $index }}.quantity" min="1" placeholder="Qty"
                                                       class="w-full px-3 py-1.5 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500/20 focus:border-green-400">
                                                <input type="number" wire:model="batchItems.{{ $index }}.price" min="0" placeholder="Harga"
                                                       class="w-full px-3 py-1.5 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500/20 focus:border-green-400 text-right">
                                            </div>

                                            @php $subtotal = ($item['price'] ?? 0) * ($item['quantity'] ?? 1); @endphp
                                            @if($subtotal > 0)
                                                <div class="text-right">
                                                    <span class="text-xs font-semibold" style="color: {{ $themeColor }};">Rp {{ number_format($subtotal, 0, ',', '.') }}</span>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        {{-- Total --}}
                        @php $total = collect($batchItems)->sum(fn($item) => ($item['price'] ?? 0) * ($item['quantity'] ?? 1)); @endphp
                        <div class="flex items-center justify-between py-3 px-4 rounded-xl {{ $total > 0 ? 'bg-green-50 border border-green-200' : 'bg-gray-50 border border-gray-200' }}">
                            <span class="text-sm font-medium text-gray-600">Total</span>
                            <span class="text-lg font-bold" style="color: {{ $themeColor }};">Rp {{ number_format($total, 0, ',', '.') }}</span>
                        </div>

                        {{-- Notes --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Catatan</label>
                            <textarea wire:model="batchNotes" rows="2" placeholder="Opsional"
                                      class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500/20 focus:border-green-400 resize-none"></textarea>
                        </div>
                    </form>
                </div>

                {{-- Footer (Fixed) --}}
                <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-3 flex-shrink-0">
                    <button wire:click="closeBatchModal" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-800 hover:bg-gray-100 rounded-lg transition-colors">
                        Batal
                    </button>
                    <button type="submit" form="batchForm" class="px-5 py-2 text-sm font-medium text-white rounded-lg transition-colors" style="background: {{ $themeColor }};">
                        Simpan
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- MODAL: Return --}}
    @if($showReturnModal)
        <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" wire:click.self="closeReturnModal">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden" wire:click.stop>

                {{-- Header --}}
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between bg-amber-50/50">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Proses Return</h2>
                        <p class="text-sm text-gray-500 mt-0.5">Upload bukti transfer pengembalian</p>
                    </div>
                    <button wire:click="closeReturnModal" class="p-2 hover:bg-gray-100 rounded-lg transition-colors">
                        <svg class="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <form wire:submit="processReturn" class="p-6 space-y-5">
                    {{-- Amount info --}}
                    <div class="flex items-center justify-between p-4 rounded-xl bg-amber-50 border border-amber-200">
                        <span class="text-sm font-medium text-gray-700">Jumlah dikembalikan</span>
                        <span class="text-xl font-bold text-amber-600">Rp {{ number_format($remaining, 0, ',', '.') }}</span>
                    </div>

                    {{-- File upload --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Bukti Transfer</label>
                        <div class="border-2 border-dashed border-gray-200 rounded-xl p-6 text-center hover:border-gray-300 transition-colors cursor-pointer"
                             x-data="{ isDragging: false }"
                             x-on:dragover.prevent="isDragging = true"
                             x-on:dragleave="isDragging = false"
                             x-on:drop.prevent="isDragging = false"
                             :class="isDragging ? 'border-amber-400 bg-amber-50/50' : ''">
                            <input type="file" wire:model="returnAttachment" accept=".pdf,.jpg,.jpeg,.png" class="hidden" id="return-upload">
                            <label for="return-upload" class="cursor-pointer">
                                <div class="w-12 h-12 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-3">
                                    <svg class="w-6 h-6 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                </div>
                                <p class="text-sm font-medium text-gray-600">Klik untuk upload</p>
                                <p class="text-xs text-gray-400 mt-1">PDF, JPG, PNG</p>
                            </label>
                        </div>

                        @if($returnAttachment)
                            <div class="mt-3 flex items-center gap-3 p-4 bg-green-50 rounded-xl border border-green-100">
                                <div class="w-8 h-8 bg-white rounded-lg flex items-center justify-center shadow-sm">
                                    <svg class="w-4 h-4 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-700 truncate">{{ $returnAttachment->getClientOriginalName() }}</p>
                                    <p class="text-xs text-gray-400">{{ number_format($returnAttachment->getSize() / 1024, 0) }} KB</p>
                                </div>
                            </div>
                        @endif
                        
                        @error('returnAttachment') 
                            <p class="text-xs text-red-500 mt-2">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Footer --}}
                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" wire:click="closeReturnModal" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-800 hover:bg-gray-100 rounded-lg transition-colors">
                            Batal
                        </button>
                        <button type="submit" 
                                wire:loading.attr="disabled"
                                class="px-5 py-2 text-sm font-medium text-white bg-amber-500 hover:bg-amber-600 rounded-lg transition-all transform hover:scale-105 active:scale-95 flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
                            <svg wire:loading wire:target="processReturn" class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span wire:loading.remove wire:target="processReturn">Konfirmasi Return</span>
                            <span wire:loading wire:target="processReturn">Memproses...</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @livewireStyles
    @livewireScripts
</x-filament-panels::page>