<x-filament-panels::page>
    @php
        $date   = fn ($value) => $value ? \Carbon\Carbon::parse($value)->locale('id')->translatedFormat('j M Y') : '—';
        $status = $record->deal?->status;
    @endphp

    {{-- Warna dari variabel tema Filament supaya ikut terang/gelap. --}}
    <style>
        .pv-meta { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1.25rem; font-size: .875rem; color: rgb(var(--gray-500)); }
        .pv-meta strong { font-weight: 500; color: rgb(var(--gray-950)); }
        .dark .pv-meta { color: rgb(var(--gray-300)); }
        .dark .pv-meta strong { color: #fff; }

        .pv-grid { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: 1.5rem; align-items: start; }
        @media (max-width: 1024px) { .pv-grid { grid-template-columns: 1fr; } }

        /* Markdown (MarkdownEditor); HTML mentah di-escape, baris baru tetap baris baru. */
        .pv-detail { font-size: .875rem; line-height: 1.6; color: rgb(var(--gray-700)); }
        .pv-detail ol { list-style: decimal; padding-left: 1.5rem; margin: .5rem 0; }
        .pv-detail ul { list-style: disc; padding-left: 1.25rem; margin: .25rem 0; }
        .pv-detail li { margin: .2rem 0; }
        .pv-detail p { margin: 0 0 .75rem; }
        .pv-detail strong { color: rgb(var(--gray-950)); }
        .dark .pv-detail { color: rgb(var(--gray-300)); }
        .dark .pv-detail strong { color: #fff; }

        .pv-details { display: grid; gap: 1rem; font-size: .875rem; }
        .pv-details dt { color: rgb(var(--gray-500)); }
        .pv-details dd { margin: .125rem 0 0; font-weight: 500; color: rgb(var(--gray-950)); overflow-wrap: anywhere; white-space: pre-line; }
        .dark .pv-details dt { color: rgb(var(--gray-300)); }
        .dark .pv-details dd { color: #fff; }

        .pv-task { display: flex; justify-content: space-between; gap: 1rem; padding: 1rem 0; border-bottom: 1px solid rgba(var(--gray-950), .06); }
        .pv-task:first-child { padding-top: 0; }
        .pv-task:last-child { border-bottom: 0; padding-bottom: 0; }
        .dark .pv-task { border-color: rgba(255, 255, 255, .08); }
        .pv-task .name { font-weight: 600; color: rgb(var(--gray-950)); }
        .pv-task .desc, .pv-task .info { margin-top: .25rem; font-size: .8125rem; color: rgb(var(--gray-500)); }
        .dark .pv-task .name { color: #fff; }
        .dark .pv-task .desc, .dark .pv-task .info { color: rgb(var(--gray-300)); }
        .pv-people { display: flex; justify-content: flex-end; margin-top: .5rem; }
        .pv-people span { display: inline-flex; width: 1.75rem; height: 1.75rem; margin-left: -.375rem; align-items: center; justify-content: center; border-radius: 9999px;
                          font-size: .6875rem; font-weight: 600; color: rgb(var(--gray-700)); background: rgb(var(--gray-100)); box-shadow: 0 0 0 2px #fff; }
        .dark .pv-people span { color: rgb(var(--gray-200)); background: rgb(var(--gray-700)); box-shadow: 0 0 0 2px rgb(var(--gray-900)); }

        .pv-money { display: grid; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); gap: 1.25rem 1.5rem; }
        .pv-money dt { font-size: .875rem; color: rgb(var(--gray-500)); }
        .pv-money dd { margin: .25rem 0 0; font-size: 1.5rem; line-height: 2rem; font-weight: 600; color: rgb(var(--gray-950)); font-variant-numeric: tabular-nums; }
        .pv-money p { margin-top: .125rem; font-size: .8125rem; color: rgb(var(--gray-500)); }
        .pv-money .is-loss { color: rgb(var(--danger-600)); }
        .pv-money .is-profit { color: rgb(var(--success-600)); }
        .dark .pv-money dt, .dark .pv-money p { color: rgb(var(--gray-300)); }
        .dark .pv-money dd { color: #fff; }
        .dark .pv-money .is-loss { color: rgb(var(--danger-400)); }
        .dark .pv-money .is-profit { color: rgb(var(--success-400)); }
        .pv-note { margin-top: 1rem; font-size: .8125rem; line-height: 1.5; color: rgb(var(--gray-500)); }
        .dark .pv-note { color: rgb(var(--gray-300)); }

        .pv-table-wrap { overflow-x: auto; margin: -.5rem 0; }
        /* Di layar sempit tabel digeser ke samping di dalam kotaknya, bukan dipadatkan per kata. */
        .pv-table { width: 100%; min-width: 44rem; font-size: .875rem; border-collapse: collapse; }
        .pv-table th { padding: .5rem .75rem .5rem 0; text-align: left; font-weight: 500; color: rgb(var(--gray-500)); white-space: nowrap; }
        .pv-table td { padding: .625rem .75rem .625rem 0; border-top: 1px solid rgba(var(--gray-950), .06); color: rgb(var(--gray-950)); vertical-align: top; }
        .pv-table .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .pv-table .muted { color: rgb(var(--gray-500)); }
        .pv-table td.muted { white-space: nowrap; }
        .pv-table tfoot td { font-weight: 600; }
        .dark .pv-table th, .dark .pv-table .muted { color: rgb(var(--gray-300)); }
        .dark .pv-table td { color: #fff; border-color: rgba(255, 255, 255, .08); }
    </style>

    <div x-data="{ tab: 'overview' }" class="flex flex-col gap-6">
        <div class="pv-meta">
            @if($status)
                <span class="flex items-center gap-2">Status deal
                    <x-filament::badge :color="\App\Helpers\ColorHelper::dealStatus($status)" size="sm">{{ $status }}</x-filament::badge>
                </span>
            @endif
            <span>Timeline <strong>{{ $date($record->timeline_mulai) }} – {{ $date($record->timeline_selesai) }}</strong></span>
            <span>ID <strong>#{{ $record->id }}</strong></span>
        </div>

        <x-filament::tabs>
            <x-filament::tabs.item alpine-active="tab === 'overview'" x-on:click="tab = 'overview'">Overview</x-filament::tabs.item>
            <x-filament::tabs.item alpine-active="tab === 'tasks'" x-on:click="tab = 'tasks'">
                Tasks
                <x-slot name="badge">{{ $tasks->count() }}</x-slot>
            </x-filament::tabs.item>
            @if($finance)
                <x-filament::tabs.item alpine-active="tab === 'finance'" x-on:click="tab = 'finance'">Keuangan</x-filament::tabs.item>
            @endif
        </x-filament::tabs>

        <div x-show="tab === 'overview'" class="pv-grid">
            <x-filament::section heading="Detail project">
                @if(filled($record->detail_project))
                    <div class="pv-detail">
                        {!! \Illuminate\Support\Str::markdown((string) $record->detail_project, [
                            'html_input'         => 'escape',
                            'allow_unsafe_links' => false,
                            'renderer'           => ['soft_break' => "<br>\n"],
                        ]) !!}
                    </div>
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-300">Belum ada detail project. Isi lewat tombol Edit di atas.</p>
                @endif
            </x-filament::section>

            <x-filament::section heading="Klien">
                <dl class="pv-details">
                    <div>
                        <dt>Perusahaan</dt>
                        <dd>{{ $record->nama_perusahaan ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt>PIC</dt>
                        <dd>{{ filled($record->nama_pic) && $record->nama_pic !== '-' ? $record->nama_pic : '—' }}</dd>
                    </div>
                    @if(filled($record->alamat))
                        <div>
                            <dt>Alamat</dt>
                            <dd>{{ $record->alamat }}</dd>
                        </div>
                    @endif
                    @if(filled($record->notes))
                        <div>
                            <dt>Catatan</dt>
                            <dd>{{ $record->notes }}</dd>
                        </div>
                    @endif
                    @if($record->invoice)
                        <div>
                            <dt>Dibuat dari invoice</dt>
                            <dd>
                                <x-filament::link :href="\App\Filament\Resources\InvoiceResource::getUrl('view', ['record' => $record->invoice])">
                                    {{ $record->invoice->uuid }}
                                </x-filament::link>
                            </dd>
                        </div>
                    @endif
                </dl>
            </x-filament::section>
        </div>

        <div x-show="tab === 'tasks'" x-cloak>
            <x-filament::section>
                @forelse($tasks as $task)
                    <div class="pv-task">
                        <div class="min-w-0">
                            <div class="name">{{ $task->nama_task }}</div>
                            @if(filled($task->detail_task))
                                <div class="desc">{{ \Illuminate\Support\Str::limit(trim(strip_tags($task->detail_task)), 140) }}</div>
                            @endif
                            <div class="info">
                                {{ $date($task->mulai_task) }} – {{ $date($task->selesai_task) }}
                                @if(filled($task->proritas)) · Prioritas {{ $task->proritas }} @endif
                            </div>
                        </div>
                        <div class="shrink-0 text-right">
                            <x-filament::badge :color="\App\Helpers\ColorHelper::status((string) $task->status)" size="sm">{{ ucfirst((string) $task->status) }}</x-filament::badge>
                            @if($task->users->isNotEmpty())
                                <div class="pv-people">
                                    @foreach($task->users as $user)
                                        <span title="{{ $user->fullName }}">{{ strtoupper(mb_substr((string) $user->firstname, 0, 1)) }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-300">
                        Belum ada task untuk project ini. Tambahkan dari
                        <x-filament::link :href="\App\Filament\Pages\TaskKanbanBoard::getUrl()">Task Kanban</x-filament::link>.
                    </p>
                @endforelse
            </x-filament::section>
        </div>

        @if($finance)
            @php
                $sum  = $finance['summary'];
                $rp   = fn ($amount) => \App\Helpers\Money::signed($amount);
                $kind = fn ($invoice) => match (\App\Services\FinanceOverview::invoiceKind($invoice)) {
                    'dp'    => 'DP ' . $invoice->dpPercentage() . '%',
                    'final' => 'Pelunasan',
                    default => 'Full',
                };
            @endphp

            <div x-show="tab === 'finance'" x-cloak class="flex flex-col gap-6">
                <x-filament::section>
                    <dl class="pv-money">
                        <div>
                            <dt>Uang masuk</dt>
                            <dd>{{ $sum['received'] === null ? '—' : $rp($sum['received']) }}</dd>
                            @if($sum['outstanding'] > 0)
                                <p>{{ $rp($sum['outstanding']) }} belum dibayar klien</p>
                            @endif
                        </div>
                        <div>
                            <dt>Biaya</dt>
                            <dd>{{ $rp($sum['cost']) }}</dd>
                            <p>Expense {{ $rp($sum['expense']) }} · Advance {{ $rp($sum['advance']) }}</p>
                        </div>
                        <div>
                            <dt>Laba</dt>
                            <dd @class(['is-loss' => $sum['profit'] < 0, 'is-profit' => $sum['profit'] > 0])>{{ $sum['profit'] === null ? '—' : $rp($sum['profit']) }}</dd>
                            @if($sum['margin'] !== null)
                                <p>Margin {{ number_format($sum['margin'], 1, ',', '.') }}%</p>
                            @endif
                        </div>
                        @if($sum['profit'] !== null && $sum['outstanding'] > 0)
                            <div>
                                <dt>Laba bila tagihan lunas</dt>
                                <dd @class(['is-loss' => $sum['profit'] + $sum['outstanding'] < 0])>{{ $rp($sum['profit'] + $sum['outstanding']) }}</dd>
                            </div>
                        @endif
                    </dl>

                    @if($sum['shared'])
                        <p class="pv-note">
                            Client ini punya {{ $sum['shared'] }} project tanpa invoice, jadi pembayarannya tidak bisa dibagi per project.
                            Pilih invoice project ini lewat tombol Edit di atas.
                        </p>
                    @endif
                    <p class="pv-note">
                        Uang masuk adalah pembayaran yang sudah diterima dari invoice project ini. Biaya adalah expense yang sudah dibayarkan
                        ditambah advance yang dicairkan dikurangi pengembalian (atau realisasinya, bila lebih besar).
                        @if($sum['expense_pending'] > 0)
                            Expense yang masih menunggu ({{ $rp($sum['expense_pending']) }}) belum dihitung.
                        @endif
                    </p>
                </x-filament::section>

                <x-filament::section heading="Invoice">
                    @if($finance['invoices']->isEmpty())
                        <p class="text-sm text-gray-500 dark:text-gray-300">
                            {{ $sum['shared'] ? 'Belum ada invoice yang dipilih untuk project ini.' : 'Belum ada invoice penjualan untuk project ini. Proforma baru dihitung setelah diubah menjadi invoice.' }}
                        </p>
                    @else
                        <div class="pv-table-wrap">
                            <table class="pv-table">
                                <thead>
                                    <tr><th>Nomor</th><th>Tanggal</th><th>Jenis</th><th>Status</th><th class="num">Total</th><th class="num">Diterima</th></tr>
                                </thead>
                                <tbody>
                                    @foreach($finance['invoices'] as $invoice)
                                        <tr>
                                            <td>
                                                <x-filament::link :href="\App\Filament\Resources\InvoiceResource::getUrl('view', ['record' => $invoice])">{{ $invoice->uuid }}</x-filament::link>
                                            </td>
                                            <td class="muted">{{ $date($invoice->date) }}</td>
                                            <td>{{ $kind($invoice) }}</td>
                                            <td><x-filament::badge :color="\App\Helpers\ColorHelper::status((string) $invoice->status)" size="sm">{{ ucfirst((string) $invoice->status) }}</x-filament::badge></td>
                                            <td class="num">{{ $rp($invoice->grand_total) }}</td>
                                            <td class="num">{{ $rp($invoice->paid) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-filament::section>

                <x-filament::section heading="Expense">
                    @if($finance['expenses']->isEmpty())
                        <p class="text-sm text-gray-500 dark:text-gray-300">Belum ada expense untuk project ini.</p>
                    @else
                        <div class="pv-table-wrap">
                            <table class="pv-table">
                                <thead>
                                    <tr><th>Tanggal</th><th>Keterangan</th><th>Status</th><th class="num">Nominal</th><th class="num">Dihitung</th></tr>
                                </thead>
                                <tbody>
                                    @foreach($finance['expenses'] as $row)
                                        <tr>
                                            <td class="muted">{{ $date($row['expense']->submission_date) }}</td>
                                            <td>
                                                <x-filament::link :href="\App\Filament\Resources\ExpenseResource::getUrl('view', ['record' => $row['expense']])">{{ \Illuminate\Support\Str::limit($row['expense']->keterangan_expense ?: 'Expense #' . $row['expense']->id, 70) }}</x-filament::link>
                                                @if(filled($row['expense']->category))<div class="muted">{{ $row['expense']->category }}</div>@endif
                                            </td>
                                            <td><x-filament::badge :color="\App\Helpers\ColorHelper::status((string) $row['expense']->status)" size="sm">{{ $row['expense']->status }}</x-filament::badge></td>
                                            <td class="num">{{ $rp($row['expense']->amount) }}</td>
                                            <td class="num">{{ $row['counted'] > 0 ? $rp($row['counted']) : '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr><td colspan="4">Total dihitung</td><td class="num">{{ $rp($sum['expense']) }}</td></tr>
                                </tfoot>
                            </table>
                        </div>
                    @endif
                </x-filament::section>

                <x-filament::section heading="Advance">
                    @if($finance['advances']->isEmpty())
                        <p class="text-sm text-gray-500 dark:text-gray-300">Belum ada advance untuk project ini.</p>
                    @else
                        <div class="pv-table-wrap">
                            <table class="pv-table">
                                <thead>
                                    <tr><th>Tanggal</th><th>Keterangan</th><th>Status</th><th class="num">Dicairkan</th><th class="num">Realisasi</th><th class="num">Dikembalikan</th><th class="num">Dihitung</th></tr>
                                </thead>
                                <tbody>
                                    @foreach($finance['advances'] as $row)
                                        <tr>
                                            <td class="muted">{{ $date($row['advance']->submission_date) }}</td>
                                            <td>
                                                <x-filament::link :href="\App\Filament\Resources\AdvanceResource::getUrl('view', ['record' => $row['advance']])">{{ \Illuminate\Support\Str::limit($row['advance']->keterangan_advance ?: 'Advance #' . $row['advance']->id, 70) }}</x-filament::link>
                                            </td>
                                            <td><x-filament::badge :color="$row['advance']->getStatusBadgeClass()" size="sm">{{ $row['advance']->getStatusLabel() }}</x-filament::badge></td>
                                            <td class="num">{{ $row['disbursed'] > 0 ? $rp($row['disbursed']) : '—' }}</td>
                                            <td class="num">{{ $row['realized'] > 0 ? $rp($row['realized']) : '—' }}</td>
                                            <td class="num">{{ $row['returned'] > 0 ? $rp($row['returned']) : '—' }}</td>
                                            <td class="num">{{ $row['counted'] > 0 ? $rp($row['counted']) : '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr><td colspan="6">Total dihitung</td><td class="num">{{ $rp($sum['advance']) }}</td></tr>
                                </tfoot>
                            </table>
                        </div>
                    @endif
                </x-filament::section>
            </div>
        @endif
    </div>
</x-filament-panels::page>
