<x-filament-panels::page>
    <div class="mb-4 flex justify-end gap-2">
        <a
            href="{{ route('filament.admin.resources.contracts.preview', $record) }}"
            target="_blank"
            class="fi-btn bg-primary-600 text-white hover:bg-primary-500 rounded-lg px-4 py-2 text-sm font-medium inline-flex items-center gap-2"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            Download HTML
        </a>
        <a
            href="{{ route('filament.admin.resources.contracts.download-pdf', $record) }}"
            class="fi-btn bg-success-600 text-white hover:bg-success-500 rounded-lg px-4 py-2 text-sm font-medium inline-flex items-center gap-2"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            Download PDF
        </a>
    </div>

    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        <iframe
            srcdoc="{{ $html }}"
            class="w-full h-[calc(100vh-280px)] border-0"
            title="Contract Preview"
        ></iframe>
    </div>
</x-filament-panels::page>
