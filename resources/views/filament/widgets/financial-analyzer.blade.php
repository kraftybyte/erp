<x-filament::card class="relative isolate overflow-hidden">

    {{-- Glow Background --}}
    <div class="pointer-events-none absolute top-0 right-0 h-40 w-40 rounded-full bg-primary-500/10 blur-2xl"></div>

    @php
        $text = $report->analysis ?? '';

        preg_match('/STATUS:(.*)/i', $text, $statusMatch);
        preg_match('/TREN UTAMA:(.*)/i', $text, $trendMatch);
        preg_match('/RISIKO TERBESAR:(.*)/i', $text, $riskMatch);
        preg_match('/AKSI 7 HARI:(.*)/i', $text, $actionMatch);
        preg_match('/PELUANG STRATEGIS:(.*)/i', $text, $opportunityMatch);

        $status = trim($statusMatch[1] ?? 'MENUNGGU DATA');
        $trend = trim($trendMatch[1] ?? '-');
        $risk = trim($riskMatch[1] ?? '-');
        $action = trim($actionMatch[1] ?? '-');
        $opportunity = trim($opportunityMatch[1] ?? '-');

        $statusLower = strtolower($status);

        if (str_contains($statusLower, 'kritis')) {
            $badge = 'bg-red-100 text-red-700 ring-red-300';
            $icon = '🚨';
        } elseif (str_contains($statusLower, 'waspada')) {
            $badge = 'bg-yellow-100 text-yellow-700 ring-yellow-300';
            $icon = '⚠️';
        } else {
            $badge = 'bg-green-100 text-green-700 ring-green-300';
            $icon = '✅';
        }
    @endphp

    <div class="space-y-6">

        {{-- HEADER --}}
        <div class="flex flex-wrap items-center justify-between gap-4">

            <div>
                <h2 class="text-lg font-bold">
                    AI Financial Intelligence
                </h2>
                <p class="text-xs text-gray-500">
                    Decision & Growth Panel
                </p>
            </div>

            <div class="flex items-center gap-3">

                <span class="inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-xs font-bold ring-1 {{ $badge }}">
                    {{ $icon }} {{ $status }}
                </span>

                <x-filament::button
                    size="sm"
                    wire:click="generate"
                    wire:loading.attr="disabled"
                >
                    <span wire:loading.remove>Generate</span>
                    <span wire:loading>Thinking…</span>
                </x-filament::button>

            </div>
        </div>

        {{-- OVERVIEW --}}
        <div class="rounded-xl bg-gradient-to-r from-primary-50 to-white p-4 border border-primary-100">
            <p class="text-xs font-semibold text-primary-700 uppercase mb-2">
                Overview
            </p>

            <div class="text-sm font-medium text-gray-800 leading-relaxed prose prose-sm max-w-none">
                {!! \Illuminate\Support\Str::markdown($trend) !!}
            </div>
        </div>

        {{-- GRID --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">

            {{-- Risk --}}
            <div class="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                <p class="text-xs font-semibold text-primary-700 uppercase mb-3">
                    Risiko Utama
                </p>

                <div class="text-sm text-gray-700 leading-relaxed prose prose-sm max-w-none">
                    {!! \Illuminate\Support\Str::markdown($risk) !!}
                </div>
            </div>

            {{-- Action --}}
            <div class="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                <p class="text-xs font-semibold text-primary-700 uppercase mb-3">
                    Aksi 7 Hari
                </p>

                @if($action !== '-')
                    <ul class="text-sm text-gray-700 space-y-2">
                        @foreach(array_filter(explode(',', $action)) as $item)
                            <li class="flex items-start gap-2">
                                <span class="text-yellow-500 mt-0.5">☐</span>
                                <span class="prose prose-sm max-w-none">
                                    {!! \Illuminate\Support\Str::markdown(trim($item)) !!}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-gray-500">Belum ada rekomendasi</p>
                @endif
            </div>

            {{-- Opportunity --}}
            <div class="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                <p class="text-xs font-semibold text-primary-700 uppercase mb-3">
                    Peluang Strategis
                </p>

                <div class="text-sm text-gray-700 leading-relaxed prose prose-sm max-w-none">
                    {!! \Illuminate\Support\Str::markdown($opportunity) !!}
                </div>
            </div>

        </div>

        {{-- FOOTER --}}
        <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-gray-400 pt-2 border-t border-gray-100">
            <span>
                AI-powered insights • Based on internal financial & sales data
            </span>
            <span>
                Last updated:
                {{ optional($report)->created_at?->format('d M Y H:i') ?? '-' }}
            </span>
        </div>

    </div>

</x-filament::card>