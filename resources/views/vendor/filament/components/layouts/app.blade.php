<div
    x-data="pageTransition"
    x-init="init"
    x-show="show"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-y-2"
    x-transition:enter-end="opacity-100 translate-y-0"
    class="min-h-screen"
>
    {{ $slot }}
</div>
