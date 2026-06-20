<footer class="flex items-center justify-between w-full px-4 py-6 font-medium">
    {{-- Left side --}}
    <div class="flex items-center gap-4 text-sm text-gray-400 dark:text-gray-300">
        @php
            $settings = app(\App\Settings\GeneralSettings::class);
            $brandName = config('app.name');
            $version = env('APP_VERSION');
        @endphp

        @if($settings->footer_show_brand)
            <a href="#" class="hover:underline">
                {{ $settings->footer_brand_text ?: $brandName }}
            </a>
        @endif

        @if($settings->footer_show_version && $version)
            <span class="opacity-60">v{{ $version }}</span>
        @endif

        @if($settings->footer_custom_text)
            <span class="opacity-60">|</span>
            <span>{{ $settings->footer_custom_text }}</span>
        @endif
    </div>

    {{-- Right side --}}
    @if($settings->footer_show_copyright)
        <span class="text-sm text-center text-gray-400 dark:text-gray-300">
            ©{{ date('Y') }} {{ $settings->footer_copyright_text ?: 'All Rights Reserved.' }}
        </span>
    @endif
</footer>
