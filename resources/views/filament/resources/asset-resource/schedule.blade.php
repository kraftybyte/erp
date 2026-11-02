@php
    $rp = fn ($value) => \App\Helpers\Money::rupiah($value);
@endphp

{{-- Jadwal penyusutan satu aset. Hanya tata letak; warna dari variabel tema Filament. --}}
<style>
    .as-meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); gap: .75rem 1.25rem; font-size: .875rem; }
    .as-meta dt { color: rgb(var(--gray-500)); }
    .as-meta dd { margin-top: .125rem; font-weight: 600; color: rgb(var(--gray-950)); font-variant-numeric: tabular-nums; }
    .dark .as-meta dt { color: rgb(var(--gray-300)); }
    .dark .as-meta dd { color: #fff; }
    .as-table-wrap { overflow-x: auto; margin-top: 1.25rem; }
    .as-table { width: 100%; min-width: 30rem; font-size: .875rem; border-collapse: collapse; }
    .as-table th { padding: .5rem .75rem .5rem 0; text-align: left; font-weight: 500; color: rgb(var(--gray-500)); }
    .as-table td { padding: .5rem .75rem .5rem 0; border-top: 1px solid rgba(var(--gray-950), .06); color: rgb(var(--gray-950)); }
    .as-table .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .as-table tr.is-now td { font-weight: 600; }
    .dark .as-table th { color: rgb(var(--gray-300)); }
    .dark .as-table td { color: #fff; border-color: rgba(255, 255, 255, .08); }
    .as-note { margin-top: 1rem; font-size: .8125rem; color: rgb(var(--gray-500)); }
    .dark .as-note { color: rgb(var(--gray-300)); }
</style>

<dl class="as-meta">
    <div><dt>Harga perolehan</dt><dd>{{ $rp($state['cost']) }}</dd></div>
    <div><dt>Akumulasi s.d. bulan ini</dt><dd>{{ $rp($state['accumulated']) }}</dd></div>
    <div><dt>Nilai buku</dt><dd>{{ $rp($state['book']) }}</dd></div>
    <div><dt>Penyusutan bulan ini</dt><dd>{{ $rp($state['monthly']) }}</dd></div>
</dl>

@if ($rows)
    <div class="as-table-wrap">
        <table class="as-table">
            <thead>
                <tr><th>Tahun</th><th class="num">Penyusutan</th><th class="num">Akumulasi</th><th class="num">Nilai buku akhir tahun</th></tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr @class(['is-now' => $row['year'] === now()->year])>
                        <td>{{ $row['year'] }}</td>
                        <td class="num">{{ $rp($row['depreciation']) }}</td>
                        <td class="num">{{ $rp($row['accumulated']) }}</td>
                        <td class="num">{{ $rp($row['book']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

<p class="as-note">{{ $note }}</p>
