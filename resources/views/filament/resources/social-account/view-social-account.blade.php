{{-- resources/views/filament/resources/social-account/view-social-account.blade.php --}}

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
@endpush

<x-filament-panels::page>

<script>
    if (typeof window.Chart === 'undefined') {
        var _cjs = document.createElement('script');
        _cjs.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js';
        document.head.appendChild(_cjs);
    }
</script>

<div class="space-y-8" x-data="socialReport()">

    {{-- ==================== HEADER ==================== --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm p-6 flex flex-col md:flex-row md:items-center justify-between gap-6">
        <div class="flex items-center gap-4">
            @if($account->profile_picture_url)
                <img src="{{ $account->profile_picture_url }}"
                     class="w-14 h-14 rounded-full object-cover ring-2 ring-blue-500"
                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex'" />
                <div class="w-14 h-14 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 items-center justify-center hidden">
                    <span class="text-white font-bold text-lg">{{ strtoupper(substr($account->username ?? $account->page_name ?? '??', 0, 2)) }}</span>
                </div>
            @else
                <div class="w-14 h-14 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center">
                    <span class="text-white font-bold text-lg">{{ strtoupper(substr($account->username ?? $account->page_name ?? '??', 0, 2)) }}</span>
                </div>
            @endif
            <div>
                <h2 class="text-lg font-bold text-gray-900 dark:text-white">{{ $account->username ?? $account->page_name ?? 'Unknown' }}</h2>
                <p class="text-sm text-gray-500">{{ ucfirst($account->platform) }} • {{ $account->client->nama_perusahaan ?? '-' }}</p>
                <p class="text-xs text-gray-400">Last sync: {{ $account->last_synced_at?->diffForHumans() ?? 'Belum pernah' }}</p>
            </div>
        </div>

        <div class="flex items-center gap-3 flex-wrap"
             x-data="{ tempFrom: '{{ $from->format('Y-m-d') }}', tempTo: '{{ $to->format('Y-m-d') }}' }">

            <input type="date" x-model="tempFrom"
                class="rounded-lg border border-gray-300 dark:border-gray-700 text-sm px-3 py-2 bg-white dark:bg-gray-800 dark:text-white" />
            <span class="text-gray-400">–</span>
            <input type="date" x-model="tempTo"
                class="rounded-lg border border-gray-300 dark:border-gray-700 text-sm px-3 py-2 bg-white dark:bg-gray-800 dark:text-white" />

            <button @click="$wire.set('fromDate', tempFrom); $wire.set('toDate', tempTo);"
                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm transition">
                Terapkan
            </button>

            @foreach([['30H', 30], ['90H', 90], ['6BL', 180], ['1TH', 365]] as [$label, $days])
                @php
                    $presetFrom = now()->subDays($days)->format('Y-m-d');
                    $presetTo   = now()->format('Y-m-d');
                    $isActive   = $from->format('Y-m-d') === $presetFrom && $to->format('Y-m-d') === $presetTo;
                @endphp
                <button
                    wire:click="$set('fromDate', '{{ $presetFrom }}'); $set('toDate', '{{ $presetTo }}')"
                    class="text-xs px-3 py-2 rounded-lg border transition font-medium
                        {{ $isActive
                            ? 'bg-blue-600 border-blue-600 text-white'
                            : 'border-gray-200 dark:border-gray-700 hover:bg-blue-50 dark:hover:bg-gray-800 text-gray-600 dark:text-gray-400' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- ==================== STATS CARDS ==================== --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        @foreach([
            ['Followers',          number_format($followersEnd ?? 0),      ($followersGrowth ?? 0) >= 0 ? '+'.number_format($followersGrowth ?? 0) : number_format($followersGrowth ?? 0), 'text-green-500'],
            ['Avg Engagement',     ($avgEngagement ?? 0).'%',              'rata-rata per post',   'text-blue-500'],
            ['Total Interactions', number_format($totalInteractions ?? 0), 'likes+saves+comments', 'text-blue-500'],
            ['Total Posts',        number_format($totalPosts ?? 0),        'di periode ini',       'text-blue-500'],
        ] as [$label, $value, $sub, $color])
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm p-5">
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                <p class="text-2xl font-bold mt-1 text-gray-900 dark:text-white">{{ $value }}</p>
                <p class="text-xs mt-1 {{ $color }}">{{ $sub }}</p>
            </div>
        @endforeach
    </div>

    {{-- ==================== FOLLOWERS CHART + ENGAGEMENT SUMMARY ==================== --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 bg-white dark:bg-gray-900 rounded-xl shadow-sm p-6">
            <h3 class="font-semibold mb-4 text-gray-700 dark:text-gray-300">📈 Followers Growth</h3>
            <div wire:ignore class="relative w-full" style="height:260px">
                <canvas id="followersChart"></canvas>
            </div>
            @if(empty($followersChart))
                <div class="flex items-center justify-center h-8 text-gray-400 text-sm mt-2">
                    Tidak ada data dalam periode ini
                </div>
            @endif
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm p-6">
            <h3 class="font-semibold mb-4 text-gray-700 dark:text-gray-300">📊 Engagement Summary</h3>
            @php
                $summaryItems = [
                    ['❤️', 'Likes',       $totalLikes       ?? 0, 'bg-rose-400'],
                    ['💬', 'Comments',    $totalComments    ?? 0, 'bg-blue-400'],
                    ['↗️', 'Shares',      $totalShares      ?? 0, 'bg-green-400'],
                    ['🔖', 'Saved',       $totalSaved       ?? 0, 'bg-yellow-400'],
                    ['👁️', 'Reach',       $totalReach       ?? 0, 'bg-sky-400'],
                    ['📊', 'Impressions', $totalImpressions ?? 0, 'bg-purple-400'],
                ];
                $summaryMax = max(array_column($summaryItems, 2) ?: [1]);
                $summaryMax = max($summaryMax, 1);
            @endphp
            <div class="space-y-3">
                @foreach($summaryItems as [$icon, $label, $value, $barColor])
                    <div>
                        <div class="flex justify-between items-center text-sm mb-1">
                            <span class="text-gray-500 dark:text-gray-400 flex items-center gap-1.5">
                                <span>{{ $icon }}</span> {{ $label }}
                                @if($label === 'Impressions')
                                    <span class="text-xs text-gray-300 dark:text-gray-600">(akun)</span>
                                @endif
                            </span>
                            <span class="font-semibold text-gray-900 dark:text-white tabular-nums">
                                {{ number_format($value) }}
                            </span>
                        </div>
                        <div class="w-full h-1.5 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden">
                            <div class="{{ $barColor }} h-full rounded-full transition-all duration-500"
                                style="width:{{ round(min(100, ($value / $summaryMax) * 100)) }}%">
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            @if(array_sum(array_column($summaryItems, 2)) === 0)
                <p class="text-xs text-gray-400 text-center mt-4">Belum ada data interaksi di periode ini</p>
            @endif
        </div>
    </div>

    {{-- ==================== REACH CHART ==================== --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm p-6">
        <h3 class="font-semibold mb-4 text-gray-700 dark:text-gray-300">👁️ Reach &amp; Impressions <span class="text-xs font-normal text-gray-400">(dari account insights)</span></h3>
        <div wire:ignore class="relative w-full" style="height:260px">
            <canvas id="reachChart"></canvas>
        </div>
        @if(empty($reachChart))
            <div class="flex items-center justify-center h-8 text-gray-400 text-sm mt-2">
                Tidak ada data dalam periode ini
            </div>
        @endif
    </div>

    {{-- ==================== LATEST POSTS ==================== --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm p-6">
        <h3 class="font-semibold mb-4 text-gray-700 dark:text-gray-300">🕐 Postingan Terbaru</h3>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
            @forelse($latestPosts ?? [] as $post)
                <a href="{{ $post->permalink ?? '#' }}" target="_blank"
                    class="group relative rounded-xl overflow-hidden aspect-square bg-gray-100 dark:bg-gray-800 block">
                    @if($post->thumbnail_url)
                        <img src="{{ $post->thumbnail_url }}"
                             class="w-full h-full object-cover group-hover:scale-105 transition duration-300"
                             loading="lazy"
                             onerror="this.parentElement.innerHTML='<div class=\'w-full h-full flex items-center justify-center text-3xl\'>📸</div>'" />
                    @else
                        <div class="w-full h-full flex items-center justify-center text-3xl">📸</div>
                    @endif
                    <div class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition flex flex-col items-center justify-center text-white text-xs gap-1">
                        <span>❤️ {{ number_format($post->metrics?->likes ?? 0) }}</span>
                        <span>💬 {{ number_format($post->metrics?->comments ?? 0) }}</span>
                        <span>{{ $post->posted_at?->format('d M Y') ?? '-' }}</span>
                    </div>
                </a>
            @empty
                <div class="col-span-6 text-center text-gray-400 py-8">Belum ada postingan</div>
            @endforelse
        </div>
    </div>

    {{-- ==================== TOP POSTS (Alpine Sorting) ==================== --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm p-6"
        x-data="{
            sortBy: 'engagement_rate',
            sortDir: 'desc',
            posts: {{ Js::from($allPostsJson ?? []) }},
            get sorted() {
                if (!this.posts || !Array.isArray(this.posts)) return [];
                return [...this.posts].sort((a, b) => {
                    const va = parseFloat(a?.[this.sortBy]) || 0;
                    const vb = parseFloat(b?.[this.sortBy]) || 0;
                    return this.sortDir === 'desc' ? vb - va : va - vb;
                }).slice(0, 10);
            },
            setSort(field) {
                if (this.sortBy === field) {
                    this.sortDir = this.sortDir === 'desc' ? 'asc' : 'desc';
                } else {
                    this.sortBy = field;
                    this.sortDir = 'desc';
                }
            }
        }">

        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
            <h3 class="font-semibold text-gray-700 dark:text-gray-300">🏆 Top Posts</h3>
            <div class="flex items-center gap-2 flex-wrap">
                <span class="text-xs text-gray-400">Urutkan:</span>
                @foreach([
                    ['engagement_rate', '📊 Engagement'],
                    ['likes',           '❤️ Likes'],
                    ['comments',        '💬 Komentar'],
                    ['saved',           '🔖 Saved'],
                    ['reach',           '👁️ Reach'],
                ] as [$field, $label])
                    <button @click="setSort('{{ $field }}')"
                        :class="sortBy === '{{ $field }}'
                            ? 'bg-blue-600 text-white border-blue-600'
                            : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-400 border-gray-200 dark:border-gray-700 hover:border-blue-400'"
                        class="text-xs px-3 py-1.5 rounded-lg border transition-all flex items-center gap-1">
                        {{ $label }}
                        <span x-show="sortBy === '{{ $field }}'">
                            <span x-show="sortDir === 'desc'">↓</span>
                            <span x-show="sortDir === 'asc'">↑</span>
                        </span>
                    </button>
                @endforeach
            </div>
        </div>

        <div class="space-y-2">
            <template x-for="(post, i) in sorted" :key="post?.id || i">
                <div class="flex items-center gap-3 p-2 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800/50 transition">
                    <span class="font-black w-6 text-sm flex-shrink-0"
                        :class="{ 'text-yellow-500': i===0, 'text-gray-400': i===1, 'text-amber-600': i===2, 'text-gray-300': i>2 }"
                        x-text="'#'+(i+1)"></span>
                    <template x-if="post?.thumbnail_url">
                        <img :src="post.thumbnail_url" class="w-10 h-10 rounded-lg object-cover flex-shrink-0" loading="lazy" />
                    </template>
                    <template x-if="!post?.thumbnail_url">
                        <div class="w-10 h-10 rounded-lg bg-gray-100 dark:bg-gray-700 flex-shrink-0 flex items-center justify-center text-lg">📸</div>
                    </template>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs text-gray-600 dark:text-gray-400 truncate" x-text="post?.caption || '—'"></p>
                        <p class="text-xs text-gray-400" x-text="post?.posted_at || ''"></p>
                    </div>
                    <div class="flex items-center gap-4 flex-shrink-0 text-xs text-gray-500">
                        <span class="hidden sm:flex items-center gap-1">❤️ <span x-text="Number(post?.likes||0).toLocaleString('id-ID')"></span></span>
                        <span class="hidden sm:flex items-center gap-1">💬 <span x-text="Number(post?.comments||0).toLocaleString('id-ID')"></span></span>
                        <span class="hidden md:flex items-center gap-1">👁️ <span x-text="Number(post?.reach||0).toLocaleString('id-ID')"></span></span>
                        <span class="font-bold text-blue-600 dark:text-blue-400 text-sm min-w-[60px] text-right">
                            <span x-show="sortBy==='engagement_rate'" x-text="parseFloat(post?.engagement_rate||0).toFixed(2)+'%'"></span>
                            <span x-show="sortBy==='likes'"           x-text="Number(post?.likes||0).toLocaleString('id-ID')"></span>
                            <span x-show="sortBy==='comments'"        x-text="Number(post?.comments||0).toLocaleString('id-ID')"></span>
                            <span x-show="sortBy==='saved'"           x-text="Number(post?.saved||0).toLocaleString('id-ID')"></span>
                            <span x-show="sortBy==='reach'"           x-text="Number(post?.reach||0).toLocaleString('id-ID')"></span>
                        </span>
                        <a :href="post?.permalink||'#'" target="_blank" class="text-gray-300 hover:text-blue-500 transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                            </svg>
                        </a>
                    </div>
                </div>
            </template>
            <template x-if="!sorted || sorted.length === 0">
                <div class="text-center text-gray-400 text-sm py-8">Belum ada data post</div>
            </template>
        </div>
    </div>

</div>{{-- /x-data=socialReport --}}

</x-filament-panels::page>

@script
<script>
(function () {
    'use strict';

    function whenChartReady(cb, n) {
        n = n || 0;
        if (typeof window.Chart !== 'undefined') { cb(); }
        else if (n < 40) { setTimeout(function () { whenChartReady(cb, n + 1); }, 150); }
        else { console.error('[SocialReport] Chart.js tidak dimuat'); }
    }

    function buildFollowers(el, data) {
        var existing = window.Chart.getChart(el);
        if (existing) existing.destroy();
        if (!data || !data.length) { el.style.display = 'none'; return; }
        el.style.display = '';
        new window.Chart(el, {
            type: 'line',
            data: {
                labels:   data.map(function (d) { return d.date || ''; }),
                datasets: [{
                    label:           'Followers',
                    data:            data.map(function (d) { return d.value || 0; }),
                    borderColor:     '#3bbeff',
                    backgroundColor: 'rgba(59,190,255,0.12)',
                    fill: true, tension: 0.4, borderWidth: 2,
                    pointRadius: 3, pointHoverRadius: 5,
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } },
                    y: { beginAtZero: false, ticks: { callback: function (v) { return Number(v).toLocaleString('id-ID'); } } }
                }
            }
        });
    }

    function buildReach(el, data) {
        var existing = window.Chart.getChart(el);
        if (existing) existing.destroy();
        if (!data || !data.length) { el.style.display = 'none'; return; }
        el.style.display = '';
        new window.Chart(el, {
            type: 'bar',
            data: {
                labels:   data.map(function (d) { return d.date || ''; }),
                datasets: [
                    { label: 'Reach',       data: data.map(function (d) { return d.reach || 0; }),       backgroundColor: '#3bbeff', borderRadius: 4 },
                    { label: 'Impressions', data: data.map(function (d) { return d.impressions || 0; }), backgroundColor: '#ff5915', borderRadius: 4 },
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'top', labels: { boxWidth: 12, padding: 15 } } },
                scales: {
                    x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } },
                    y: { beginAtZero: true, ticks: { callback: function (v) { return Number(v).toLocaleString('id-ID'); } } }
                }
            }
        });
    }

    function initCharts() {
        var followersData = @json($followersChart ?? []);
        var reachData     = @json($reachChart ?? []);
        var elF = document.getElementById('followersChart');
        var elR = document.getElementById('reachChart');
        if (elF) buildFollowers(elF, followersData);
        if (elR) buildReach(elR, reachData);
    }

    window.socialReport = function () {
        return {
            init: function () {
                whenChartReady(initCharts);
                // Re-init setiap Livewire update DOM
                document.addEventListener('livewire:updated', function () {
                    whenChartReady(initCharts);
                });
            }
        };
    };
})();
</script>
@endscript
