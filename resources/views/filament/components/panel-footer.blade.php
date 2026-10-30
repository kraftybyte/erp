@php
    $settings = app(\App\Settings\GeneralSettings::class);
    $version  = config('app.version');
@endphp

{{-- Abu gray-500 (7,8:1), bukan gray-400 dengan opacity 60% (1,9–3,4:1). Nama
     merek bukan link: dulu <a href="#"> yang tidak menuju ke mana pun. --}}
<footer class="flex w-full flex-wrap items-center justify-between gap-2 px-4 py-6 text-sm font-medium text-gray-500 dark:text-gray-300">
    <div class="flex items-center gap-4">
        @if($settings->footer_show_brand)
            <span>{{ $settings->footer_brand_text ?: config('app.name') }}</span>
        @endif

        @if($settings->footer_show_version && $version)
            <span>v{{ $version }}</span>
        @endif

        @if($settings->footer_custom_text)
            <span aria-hidden="true">|</span>
            <span>{{ $settings->footer_custom_text }}</span>
        @endif
    </div>

    @if($settings->footer_show_copyright)
        <span>©{{ date('Y') }} {{ $settings->footer_copyright_text ?: 'All Rights Reserved.' }}</span>
    @endif
</footer>
