<div>
    <x-filament-panels::page>
        <x-filament::section>
            <div class="space-y-8">
                <!-- Header Section -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <!-- Left Column - Company & Client Info -->
                    <div class="space-y-6">
                        <!-- Logo -->
                        <div class="mb-6">
                            @php
                                $logo = \TomatoPHP\FilamentSettings\Settings\General::get('site_logo') ?? setting('site_logo');
 @endphp
                            @if($logo)
                                <img src="{{ url('storage/' . $logo) }}" alt="{{ setting('site_name') }}" class="h-16 w-auto">
                            @else
                                <div class="text-2xl font-bold text-primary-600">{{ setting('site_name', 'ERP Creative') }}</div>
                            @endif
                        </div>

                        <!-- Bill From -->
                        <div>
                            <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">
                                {{ trans('filament-invoices::messages.invoices.view.bill_from') }}
                            </div>
                            <div class="space-y-1">
                               <div class="text-lg font-bold text-gray-900 dark:text-white">
                                    {{ $this->getRecord()->billedFrom?->name ?? setting('general.quot_company_name', 'PT Digital Catha Sangkara') }}
                                </div>
<div class="text-sm text-gray-600 dark:text-gray-300">
                                    {{ $this->getRecord()->billedFrom?->phone ?? setting('general.quot_company_phone', '085186028747') }}
                                </div>
                                <div class="text-sm text-gray-600 dark:text-gray-300">
                                    {{ $this->getRecord()->billedFrom?->address ?? setting('general.inv_company_address', '-') }}
                                </div>
                            </div>
                        </div>

                        <!-- Bill To -->
                        <div>
                            <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">
                                {{ trans('filament-invoices::messages.invoices.view.bill_to') }}
                            </div>
                            <div class="space-y-1">
                                <div class="text-lg font-bold text-gray-900 dark:text-white">
                                    {{ $this->getRecord()->billedFor?->name ?? $this->getRecord()->name ?? $this->getRecord()->nama_perusahaan ?? '-' }}
                                </div>
                                <div class="text-sm text-gray-600 dark:text-gray-300">
                                    {{ $this->getRecord()->billedFor?->email ?? '-' }}
                                </div>
                                <div class="text-sm text-gray-600 dark:text-gray-300">
                                    {{ $this->getRecord()->billedFor?->phone ?? $this->getRecord()->phone ?? $this->getRecord()->telephone ?? '-' }}
                                </div>
                                <div class="text-sm text-gray-600 dark:text-gray-300">
                                    {{ $this->getRecord()->billedFor?->address ?? $this->getRecord()->address ?? $this->getRecord()->alamat ?? '-' }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column - Invoice Details -->
                    <div class="space-y-6 lg:text-right">
                        <div>
                            <h1 class="text-4xl font-bold text-gray-900 dark:text-white uppercase tracking-tight">
                                {{ trans('filament-invoices::messages.invoices.view.invoice') }}
                            </h1>
                            <div class="text-lg text-gray-500 dark:text-gray-400 mt-1">
                                #{{ $this->getRecord()->uuid }}
                            </div>
                        </div>

                        <div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-6 space-y-3">
                            <div class="flex justify-between lg:justify-end gap-8">
                                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">
                                    {{ trans('filament-invoices::messages.invoices.view.issue_date') }}:
                                </span>
                                <span class="text-sm font-semibold text-gray-900 dark:text-white">
                                    {{ $this->getRecord()->date?->toDateString() ?? $this->getRecord()->created_at?->toDateString() ?? '-' }}
                                </span>
                            </div>
                            <div class="flex justify-between lg:justify-end gap-8">
                                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">
                                    {{ trans('filament-invoices::messages.invoices.view.due_date') }}:
                                </span>
                                <span class="text-sm font-semibold text-gray-900 dark:text-white">
                                    {{ $this->getRecord()->due_date?->toDateString() ?? '-' }}
                                </span>
                            </div>
                            <div class="flex justify-between lg:justify-end gap-8">
                                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">
                                    {{ trans('filament-invoices::messages.invoices.view.status') }}:
                                </span>
                                <span class="text-sm font-semibold text-gray-900 dark:text-white">
                                    @php
                                        try {
                                            echo \Spatie\Enum\Enum::make($this->getRecord()->status)->label();
                                        } catch (\Throwable $e) {
                                            echo ucfirst($this->getRecord()->status ?? '-');
                                        }
                                    @endphp
                                </span>
                            </div>
                            <div class="flex justify-between lg:justify-end gap-8">
                                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">
                                    {{ trans('filament-invoices::messages.invoices.view.type') }}:
                                </span>
                                <span class="text-sm font-semibold text-gray-900 dark:text-white">
                                    @php
                                        try {
                                            echo \Spatie\Enum\Enum::make($this->getRecord()->type)->label();
                                        } catch (\Throwable $e) {
                                            echo ucfirst($this->getRecord()->type ?? '-');
                                        }
                                    @endphp
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Items Table -->
                <div class="overflow-hidden border border-gray-200 dark:border-gray-700 rounded-lg">
                    <!-- Table Header -->
                    <div class="bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
                        <div class="grid grid-cols-2 gap-4 px-6 py-4">
                            <div class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">
                                Item
                            </div>
                            <div class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide text-right">
                                Total
                            </div>
                        </div>
                    </div>

                    <!-- Table Body -->
                    <div class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse($this->getRecord()->invoicesitems as $item)
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 px-6 py-5 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition">
                                <!-- Item Details -->
                                <div class="space-y-2">
                                    <div class="text-base font-semibold text-gray-900 dark:text-white">
                                        {{ $item->item ?? '-' }}
                                    </div>
                                    @if($item->description)
                                        <div class="text-sm text-gray-500 dark:text-gray-400">
                                            {{ $item->description }}
                                        </div>
                                    @endif
                                </div>

                                <!-- Item Pricing -->
                                <div class="space-y-2 lg:text-right">
                                    <div class="flex justify-between lg:justify-end gap-8">
                                        <span class="text-sm text-gray-500 dark:text-gray-400">Price:</span>
                                        <span class="text-sm font-medium text-gray-900 dark:text-white">
                                            Rp {{ number_format($item->price ?? 0, 0) }}
                                        </span>
                                    </div>
                                    <div class="flex justify-between lg:justify-end gap-8">
                                        <span class="text-sm text-gray-500 dark:text-gray-400">Qty:</span>
                                        <span class="text-sm font-medium text-gray-900 dark:text-white">
                                            {{ $item->qty ?? 0 }}
                                        </span>
                                    </div>
<div class="flex justify-between lg:justify-end gap-8">
                                        <span class="text-sm text-gray-500 dark:text-gray-400">Tax:</span>
                                        <span class="text-sm font-medium text-gray-900 dark:text-white">
                                            Rp {{ number_format($item->tax ?? 0, 0) }}
                                        </span>
                                    </div>
                                    <div class="flex justify-between lg:justify-end gap-8">
                                        <span class="text-sm text-gray-500 dark:text-gray-400">Discount:</span>
                                        <span class="text-sm font-medium text-gray-900 dark:text-white">
                                            Rp {{ number_format($item->discount ?? 0, 0) }}
</span>
                                    </div>
                                    <div class="flex justify-between lg:justify-end gap-8 pt-2 border-t border-gray-200 dark:border-gray-700">
                                        <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Total:</span>
                                        <span class="text-base font-bold text-gray-900 dark:text-white">
                                            Rp {{ number_format($item->total ?? 0, 0) }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                                No items found
                            </div>
                        @endforelse
                    </div>
                </div>

                <!-- Summary Section -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <!-- Left Column - Bank Info -->
                    <div class="space-y-6">
                        @if($this->getRecord()->is_bank_transfer)
                            <div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-6">
                                <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4">
                                    Bank Transfer Info
                                </h3>
                                <div class="space-y-2 text-sm">
                                    <div class="flex gap-2">
                                        <span class="text-gray-500 dark:text-gray-400 min-w-[80px]">Bank:</span>
                                        <span class="font-semibold text-gray-900 dark:text-white">
                                            {{ $this->getRecord()->bank_name ?? '-' }}
                                        </span>
                                    </div>
                                    <div class="flex gap-2">
                                        <span class="text-gray-500 dark:text-gray-400 min-w-[80px]">Account:</span>
                                        <span class="font-semibold text-gray-900 dark:text-white">
                                            {{ $this->getRecord()->bank_account ?? '-' }}
                                        </span>
                                    </div>
                                    <div class="flex gap-2">
                                        <span class="text-gray-500 dark:text-gray-400 min-w-[80px]">Atas Nama:</span>
                                        <span class="font-semibold text-gray-900 dark:text-white">
                                            {{ $this->getRecord()->bank_account_owner ?? '-' }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>

                    <!-- Right Column - Total Summary -->
                    <div>
                        <div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-6 space-y-4">
                            <div class="flex justify-between text-sm">
                                <span class="font-medium text-gray-600 dark:text-gray-300">Subtotal</span>
                                <span class="font-semibold text-gray-900 dark:text-white">
                                    Rp {{ number_format($this->getRecord()->total ?? 0, 0) }}
                                </span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="font-medium text-gray-600 dark:text-gray-300">Tax (VAT)</span>
                                <span class="font-semibold text-gray-900 dark:text-white">
                                    Rp {{ number_format($this->getRecord()->vat ?? 0, 0) }}
                                </span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="font-medium text-gray-600 dark:text-gray-300">Discount</span>
                                <span class="font-semibold text-gray-900 dark:text-white">
                                    Rp {{ number_format($this->getRecord()->discount ?? 0, 0) }}
                                </span>
                            </div>
                            <div class="border-t-2 border-gray-300 dark:border-gray-600 pt-4 mt-4">
                                <div class="flex justify-between">
                                    <span class="text-xl font-bold text-gray-900 dark:text-white">Total</span>
                                    <span class="text-2xl font-bold text-primary-600 dark:text-primary-400">
                                        Rp {{ number_format($this->getRecord()->grand_total ?? $this->getRecord()->total ?? 0, 0) }}
                                    </span>
                                </div>
                            </div>
<div class="flex justify-between text-sm border-t pt-2">
                                <span class="font-medium text-gray-600 dark:text-gray-300">Paid</span>
                                <span class="font-semibold text-green-600 dark:text-green-400">
                                    Rp {{ number_format($this->getRecord()->paid ?? 0, 0) }}
                                </span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="font-medium text-gray-600 dark:text-gray-300">Remaining</span>
                                <span class="font-semibold text-red-600 dark:text-red-400">
                                    Rp {{ number_format(($this->getRecord()->grand_total ?? $this->getRecord()->total ?? 0) - ($this->getRecord()->paid ?? 0), 0) }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Notes Section -->
                @if($this->getRecord()->notes)
                    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-6">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-3">Notes</h3>
                        <div class="text-sm text-gray-700 dark:text-gray-300">
                            {!! $this->getRecord()->notes !!}
                        </div>
                    </div>
                @endif
            </div>
        </x-filament::section>
    </x-filament-panels::page>
</div>
