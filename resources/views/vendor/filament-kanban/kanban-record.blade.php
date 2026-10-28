<div
    id="{{ $record->getKey() }}"
    wire:click="recordClicked('{{ $record->getKey() }}', {{ @json_encode($record) }})"
    class="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm hover:shadow-md transition space-y-4"
    @if($record->timestamps)
        @php $updatedAt = $record->updated_at; @endphp
        @if($updatedAt && now()->diffInSeconds($updatedAt) < 3)
            x-data
            x-init="
                $el.classList.add('animate-pulse', 'bg-blue-50');
                setTimeout(() => $el.classList.remove('animate-pulse', 'bg-blue-50'), 2000);
            "
        @endif
    @endif
>


    {{-- HEADER --}}
    <div class="flex items-start justify-between">
        <div>
            <h4 class="text-sm font-semibold text-gray-900 leading-snug">
                {{ $record['nama_task'] ?? 'Untitled' }}
            </h4>

            <p class="text-xs text-gray-500 mt-1">
                {{ $record->project?->nama_perusahaan ?? 'N/A' }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            {{-- Calendar Toggle --}}
            <button
                wire:click.stop="toggleCalendarEvent('{{ $record->getKey() }}')"
                title="{{ $record->event ? 'Hapus dari Calendar' : 'Tambahkan ke Calendar' }}"
                class="p-1.5 rounded-lg transition-colors
                    {{ $record->event
                        ? 'bg-indigo-100 text-indigo-600 hover:bg-indigo-200'
                        : 'bg-gray-100 text-gray-400 hover:bg-gray-200 hover:text-gray-600' }}"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
            </button>

            @can('update', $record)
                <a href="{{ url('admin/tasks/' . $record->id . '/edit') }}"
                   wire:click.stop
                   class="text-xs px-3 py-1 rounded-full bg-gray-50 text-gray-600 hover:bg-gray-200 transition">
                    Edit
                </a>
            @endcan
        </div>
    </div>


    {{-- DESKRIPSI --}}
    @if(!empty($record['description']))
        <p class="text-xs text-gray-500 leading-relaxed">
            {{ \Illuminate\Support\Str::limit($record['description'], 120) }}
        </p>
    @endif


    {{-- ROW 1 : WAKTU --}}
    @php
        $now = \Carbon\Carbon::now();
        $deadline = \Carbon\Carbon::parse($record->selesai_task);
        $diffInDays = (int) $now->diffInDays($deadline, false);
    @endphp

    <div class="flex flex-wrap gap-2">

        <span class="px-3 py-1 rounded-full bg-gray-100 text-gray-600 text-xs font-medium">
            {{ $record->created_at->format('d M Y, H:i') }}
        </span>

        <span
            @class([
                'px-3 py-1 rounded-full text-xs font-semibold',

                'bg-gray-100 text-gray-500' => $record->status === 'done',
                'bg-red-100 text-red-600' => $diffInDays < 0 && $record->status !== 'done',
                'bg-orange-100 text-orange-600' => $diffInDays === 0,
                'bg-green-100 text-green-600' => $diffInDays > 0,
            ])
        >
            @if($record->status === 'done')
                Selesai
            @elseif($diffInDays < 0)
                {{ abs($diffInDays) }} hari terlambat
            @elseif($diffInDays === 0)
                Hari ini
            @else
                {{ $diffInDays }} hari lagi
            @endif
        </span>

    </div>


    {{-- ROW 2 : LAYANAN + PRIORITAS --}}
    <div class="flex flex-wrap gap-2">

        @php
    $layanan = $record->nama_layanan;

    $shortLayanan = match ($layanan) {
        'Social Media Management' => 'SMM',
        'Video Production' => 'Video Prod',
        'Website Development' => 'Web Dev',
        'Tiktok Handling' => 'Tiktok',
        'Photo Product' => 'Photo',
        default => $layanan,
    };
    @endphp

        <span
    @class([
        'px-3 py-1 rounded-full text-xs font-medium whitespace-nowrap',

        'bg-blue-100 text-blue-700' => $layanan === 'Social Media Management',
        'bg-purple-100 text-purple-700' => $layanan === 'Video Production',
        'bg-green-100 text-green-700' => $layanan === 'SEO',
        'bg-pink-100 text-pink-700' => $layanan === 'Tiktok Handling',
        'bg-teal-100 text-teal-700' => $layanan === 'Website Development',
        'bg-yellow-100 text-yellow-700' => $layanan === 'Live Tiktok',
        'bg-indigo-100 text-indigo-700' => $layanan === 'Photo Product',
        'bg-gray-100 text-gray-600' => !in_array($layanan, [
            'Social Media Management',
            'Video Production',
            'SEO',
            'Tiktok Handling',
            'Website Development',
            'Live Tiktok',
            'Photo Product',
            ]),
        ])
    >
        {{ $shortLayanan ?? 'N/A' }}
    </span>


        <span
            @class([
                'px-3 py-1 rounded-full text-xs font-semibold',

                'bg-red-100 text-red-600' => $record->proritas === 'Urgent',
                'bg-yellow-100 text-yellow-700' => $record->proritas === 'Normal',
                'bg-green-100 text-green-600' => $record->proritas === 'Low',
                'bg-gray-100 text-gray-600' => !in_array($record->proritas, ['Urgent','Normal','Low']),
            ])
        >
            {{ $record->proritas ?? 'N/A' }}
        </span>

    </div>


    {{-- AVATAR --}}
    <div class="flex -space-x-2 pt-1">
        @foreach($record->users as $user)
            <div class="w-8 h-8 border-2 border-white rounded-full overflow-hidden shadow-sm">
                @if($user->getFirstMediaUrl('avatars'))
                    <img src="{{ $user->getFirstMediaUrl('avatars') }}"
                         class="w-full h-full object-cover">
                @else
                    @php
                        $colors = ['bg-red-500','bg-blue-500','bg-green-500','bg-yellow-500','bg-purple-500','bg-teal-500','bg-indigo-500'];
                        $colorIndex = abs(crc32($user->id)) % count($colors);
                        $randomColor = $colors[$colorIndex];
                    @endphp
                    <div class="w-full h-full flex items-center justify-center text-white text-xs font-bold {{ $randomColor }}">
                        {{ strtoupper(substr($user->firstname,0,1)) }}{{ strtoupper(substr($user->lastname,0,1)) }}
                    </div>
                @endif
            </div>
        @endforeach
    </div>

</div>
