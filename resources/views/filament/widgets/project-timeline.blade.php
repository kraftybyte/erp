<x-filament::page>

<div class="space-y-6">

    {{-- PROJECT DETAIL CARD --}}
    <div class="bg-white rounded-xl shadow p-6">
        <div class="flex justify-between items-center">
            <div>
                <h2 class="text-2xl font-bold">
                    {{ $record->name }}
                </h2>
                <p class="text-gray-500">
                    {{ $record->start_date->format('d M Y') }}
                    -
                    {{ $record->end_date->format('d M Y') }}
                </p>
            </div>

            <div class="text-right">
                <div class="text-sm text-gray-500">Status</div>
                <div class="font-semibold">
                    {{ ucfirst($record->status) }}
                </div>
            </div>
        </div>

        <div class="mt-4">
            <div class="text-sm text-gray-500">Progress</div>
            <div class="w-full bg-gray-200 rounded-full h-3">
                <div
                    class="bg-primary-600 h-3 rounded-full"
                    style="width: {{ $record->progress ?? 0 }}%">
                </div>
            </div>
        </div>
    </div>


    {{-- GANTT CHART --}}
    <link rel="stylesheet" href="https://unpkg.com/frappe-gantt/dist/frappe-gantt.css" />
    <script src="https://unpkg.com/frappe-gantt/dist/frappe-gantt.js"></script>

    <div class="bg-white rounded-xl shadow p-6">
        <div id="gantt"></div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    let tasks = @json($timelineData);

    const gantt = new Gantt("#gantt", tasks, {
        view_mode: 'Day',
    });

});
</script>

</x-filament::page>
