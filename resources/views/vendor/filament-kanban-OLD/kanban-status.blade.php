@props(['status'])

<div class="md:w-[24rem] flex-shrink-0 mb-5 md:min-h-full flex flex-col">
    @include(static::$headerView)

    <div
        data-status-id="{{ $status['id'] }}"
        class="flex flex-col flex-1 gap-3 p-8 bg-gray-50 dark:bg-gray-800 rounded-2xl"
    >
        @foreach($status['records'] as $record)
            @include(static::$recordView)
        @endforeach
    </div>
</div>
