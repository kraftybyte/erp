<div
    id="{{ $record->getKey() }}"
    wire:click="recordClicked('{{ $record->getKey() }}', {{ @json_encode($record) }})"
    class="py-5 px-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-2xl shadow-sm hover:shadow-md transition cursor-pointer"
    @if($record->timestamps && now()->diffInSeconds($record->{$record::UPDATED_AT}) < 3)
        x-data
        x-init="
            $el.classList.add('animate-pulse', 'bg-blue-50');
            setTimeout(() => $el.classList.remove('animate-pulse', 'bg-blue-50'), 2000);
        "
    @endif
>

    {{-- HEADER --}}
    <div class="flex items-center justify-between gap-4 mb-3">

        <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg"
                 fill="none"
                 viewBox="0 0 24 24"
                 stroke-width="1.5"
                 stroke="currentColor"
                 class="w-4 h-4 text-green-500">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
            </svg>

            <span class="truncate">
                {{ $record->{static::$recordTitleAttribute} }}
            </span>
        </h2>

        @can('update', $record)
            <a href="{{ url('admin/tasks/' . $record->id . '/edit') }}"
               wire:click.stop
               class="text-xs px-3 py-1 rounded-full bg-blue-100 text-blue-600 hover:bg-blue-200 transition">
                Edit
            </a>
        @endcan

    </div>

</div>



    <div class="flex space-x-3">
        <span class="
            {{
                $record->nama_layanan == 'Social Media Management' ? 'bg-blue-600 text-blue-100' :
                ($record->nama_layanan == 'Video Production' ? 'bg-red-700 text-red-200' :
                ($record->nama_layanan == 'SEO' ? 'bg-green-700 text-green-200' :
                ($record->nama_layanan == 'Tiktok Handling' ? 'bg-purple-700 text-purple-200' :
                ($record->nama_layanan == 'Website Development' ? 'bg-teal-700 text-teal-200' :
                ($record->nama_layanan == 'Live Tiktok' ? 'bg-pink-700 text-pink-200' :
                ($record->nama_layanan == 'Photo Product' ? 'bg-yellow-700 text-yellow-200' :
                ($record->nama_layanan == 'Lain-lain' ? 'bg-gray-700 text-gray-200' : 'bg-gray-300 text-gray-800')
                ))))))
            }}
            text-xs font-medium px-2 py-1 rounded-xl dark:bg-gray-700">
            {{ $record->nama_layanan ?? 'N/A' }}
        </span>

        <span class="
            {{
                $record->proritas == 'Normal' ? 'bg-yellow-400 text-yellow-100' :
                ($record->proritas == 'Urgent' ? 'bg-pink-600 text-pink-100' :
                ($record->proritas == 'Low' ? 'bg-green-400 text-green-100' : 'bg-gray-300 text-gray-800'))
            }}
            text-xs font-medium px-2 py-1 rounded-xl dark:bg-gray-700">
            {{ $record->proritas ?? 'N/A' }}
        </span>
    </div>



    <!-- Task creation and deadline info -->
    <div class="mt-6">
        <p class="text-xs leading-5 text-gray-500">
            Klien: {{ $record->project->nama_perusahaan ?? 'N/A' }}
        </p>
        <p class="text-xs leading-5 text-gray-500">
            Dibuat: <b>{{ $record->created_at->format('d M Y, H:i') }}</b>
        </p>
        <p class="text-xs leading-5 text-gray-500">
            Waktu Pengerjaan:
            <b>
                @php
                // Pastikan timeline_selesai berisi tanggal yang valid
                $now = \Carbon\Carbon::now();
                $deadline = \Carbon\Carbon::parse($record->selesai_task);

                // Menambahkan pengecekan jika tanggal deadline tidak valid
                if ($deadline->isValid()) {
                    // Tambahkan penyesuaian dengan menghitung perbedaan hari secara tepat
                    $diffInDays = $now->diffInDays($deadline, false); // Menghitung selisih hari

                    // Membulatkan hasil perhitungan ke angka bulat
                    $roundedDiff = round($diffInDays);

                    // Menampilkan hasil berdasarkan perhitungan
                    if ($record->status == 'Done') {
                        echo 'Selesai';
                    } elseif ($roundedDiff < 0) {
                        echo abs($roundedDiff) . ' hari terlambat';
                    } elseif ($roundedDiff == 0) {
                        echo 'Hari ini';
                    } else {
                        echo $roundedDiff . ' hari lagi';
                    }
                } else {
                    echo 'Tanggal tidak valid'; // Jika tanggal tidak valid
                }
                @endphp
            </b>
        </p>



    </div>

    <!-- Assigned users -->
    <div class="mt-6">
        <div class="flex -space-x-3 hover:-space-x-1">
        @foreach($record->users as $user)
            <div class="w-8 h-8 transition-all bg-gray-200 border-2 border-white rounded-full">
                @if($user->getFirstMediaUrl('avatars'))
                    <img src="{{ $user->getFirstMediaUrl('avatars') }}" 
                        alt="{{ $user->firstname }} {{ $user->lastname }}" 
                        class="object-cover w-full h-full rounded-full">
                @else
                    <!-- Avatar dengan Inisial dan Warna Random -->
                    @php
                        // Generate warna acak
                        $colors = ['bg-red-500', 'bg-blue-500', 'bg-green-500', 'bg-yellow-500', 'bg-purple-500', 'bg-teal-500', 'bg-indigo-500'];
                        $randomColor = $colors[array_rand($colors)]; // Pilih warna acak
                    @endphp
                    <div class="flex items-center justify-center w-full h-full text-white font-bold {{ $randomColor }} rounded-full">
                        {{ strtoupper(substr($user->firstname, 0, 1)) }}{{ strtoupper(substr($user->lastname, 0, 1)) }}
                    </div>
                @endif
            </div>
        @endforeach
    </div>
    </div>

    


</div>
