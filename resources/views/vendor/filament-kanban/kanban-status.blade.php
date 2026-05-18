@props(['status'])

<div class="kanban-col">

    @include(static::$headerView)

    <div
        data-status-id="{{ $status['id'] }}"
        class="kanban-body">
        @foreach($status['records'] as $record)
            @include(static::$recordView)
        @endforeach
    </div>

</div>
<style>
    .kanban-col {
        flex: 0 0 280px;
        display: flex;
        flex-direction: column;
        height: 100%;
        min-width: 280px;
    }

    .kanban-body {
        flex: 1;
        overflow-y: auto;
        overflow-x: hidden;
        padding: 12px;
        display: flex;
        flex-direction: column;
        gap: 12px;
        background: #f3f4f6;
        border-radius: 12px;
    }

    .kanban-body::-webkit-scrollbar {
        width: 5px;
    }

    .kanban-body::-webkit-scrollbar-track {
        background: transparent;
    }

    .kanban-body::-webkit-scrollbar-thumb {
        background: rgba(0,0,0,0.15);
        border-radius: 10px;
    }

    .kanban-body::-webkit-scrollbar-thumb:hover {
        background: rgba(0,0,0,0.25);
    }

    /* Tablet */
    @media (max-width: 1279px) {
        .kanban-col {
            flex: 0 0 260px;
            min-width: 260px;
        }
    }

    /* Mobile */
    @media (max-width: 639px) {
        .kanban-col {
            flex: 0 0 85vw;
            min-width: 85vw;
        }
    }
</style>

