{{-- Command Palette Component --}}
<div id="command-palette" class="cu-command-overlay" onclick="closeCommandPalette(event)">
    <div class="cu-command-palette" onclick="event.stopPropagation()">
        {{ $slot }}
    </div>
</div>