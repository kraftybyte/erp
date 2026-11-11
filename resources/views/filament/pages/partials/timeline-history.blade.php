{{-- Riwayat Board Project Timeline. $logs: ProjectTimelineLog terbaru dulu; $compact: tanpa nama project (di modal satu phase). --}}
@if ($logs->isEmpty())
    <p class="tl-log-empty">Belum ada perubahan yang tercatat.</p>
@else
    <ol class="tl-log">
        @foreach ($logs as $log)
            @php
                $project = $log->project ? trim((string) ($log->project->nama_perusahaan ?: $log->project->nama_project)) : null;
            @endphp
            <li class="tl-log-item">
                <p class="tl-log-text">
                    <strong>{{ $log->user?->name ?? $log->user_name ?? 'Sistem' }}</strong>
                    {{ $log->summary() }}
                </p>
                <p class="tl-log-meta">
                    @if (! $compact && $project)
                        {{ $project }} ·
                    @endif
                    <time datetime="{{ $log->created_at->toIso8601String() }}" title="{{ $log->created_at->translatedFormat('d M Y H:i') }}">
                        {{ $log->created_at->translatedFormat('d M Y, H:i') }}
                    </time>
                </p>
            </li>
        @endforeach
    </ol>
@endif
