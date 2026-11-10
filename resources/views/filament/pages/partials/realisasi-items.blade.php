{{-- Item satu batch realisasi: tabel di layar lebar, bertumpuk di HP.
     $batch berbentuk RealisasiService::batchesForDisplay(). --}}
@php
    // Nama file dipendekkan tanpa membuang ekstensinya.
    $fileLabel = function (string $name): string {
        if (mb_strlen($name) <= 26) {
            return $name;
        }
        $ext = pathinfo($name, PATHINFO_EXTENSION);

        return \Illuminate\Support\Str::limit(pathinfo($name, PATHINFO_FILENAME), 18, '…') . ($ext ? ".{$ext}" : '');
    };
@endphp

<div class="rl-items" role="table" aria-label="{{ $label ?? 'Item batch #' . $batch['batch_number'] }}">
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
                @if(filled($item['receipt_date'] ?? null))
                    <div class="date">Tanggal struk {{ \Carbon\Carbon::parse($item['receipt_date'])->locale('id')->translatedFormat('j M Y') }}</div>
                @endif
                @if(filled($item['description']))
                    <div class="desc">{{ $item['description'] }}</div>
                @endif
                <div class="qty-mobile">{{ \App\Helpers\Money::qty($item['quantity']) }} × {{ \App\Helpers\Money::rupiah($item['price']) }}</div>
                @if(count($item['files']))
                    <div class="rl-files">
                        @foreach($item['files'] as $file)
                            <a href="{{ url('storage/' . $file['filepath']) }}" target="_blank" rel="noopener" class="rl-file" title="{{ $file['original_name'] }}">
                                <x-filament::icon :icon="$file['is_image'] ? 'heroicon-m-photo' : 'heroicon-m-document-text'" class="h-3.5 w-3.5" />
                                {{ $fileLabel($file['original_name']) }}
                            </a>
                            {{-- Foto sebelum dirapikan seperti scan: untuk hasil olahan yang kurang jelas. --}}
                            @if(! empty($file['original']))
                                <a href="{{ url('storage/' . $file['original']) }}" target="_blank" rel="noopener" class="rl-file" title="Foto asli {{ $file['original_name'] }}">
                                    <x-filament::icon icon="heroicon-m-photo" class="h-3.5 w-3.5" />
                                    Foto asli
                                </a>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>
            <div role="cell" class="num qty">{{ \App\Helpers\Money::qty($item['quantity']) }}</div>
            <div role="cell" class="num price">{{ \App\Helpers\Money::rupiah($item['price']) }}</div>
            <div role="cell" class="num subtotal">{{ \App\Helpers\Money::rupiah($item['subtotal']) }}</div>
        </div>
    @endforeach
</div>
