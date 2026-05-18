<div class="flex items-start gap-1 w-full overflow-hidden" style="font-size: 11px; line-height: 1.35;">
    <div class="flex-shrink-0 font-bold" style="min-width: 36px; color: rgba(255,255,255,0.9);">
        {{ $timeDisplay }}
    </div>
    <div class="flex-1 overflow-hidden">
        <div class="font-medium truncate" style="color: rgba(255,255,255,0.95);">{{ $title }}</div>
        @if($description)
            <div class="truncate" style="color: rgba(255,255,255,0.7); font-size: 10px;">{{ $description }}</div>
        @endif
    </div>
</div>