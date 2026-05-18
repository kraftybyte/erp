<div>
    <x-filament-panels::page>

        <x-filament::section>
            <div class="space-y-12">

                {{-- ================= HEADER ================= --}}
                <div class="grid grid-cols-2 gap-16">

                    {{-- BILL TO --}}
                    <div>
                        <div class="text-xs uppercase tracking-wide text-gray-400 font-semibold mb-3">
                            Bill To
                        </div>

                        <div class="text-2xl font-bold">
                            {{ $this->getRecord()->nama_perusahaan ?? 'N/A' }}
                        </div>

                        <div class="mt-3 text-sm text-gray-600">
                            {{ $this->getRecord()->alamat ?? 'N/A' }}
                        </div>

                        <div class="mt-2 text-sm text-gray-600">
                            {{ $this->getRecord()->telephone ?? 'N/A' }}
                        </div>
                    </div>

                    {{-- INVOICE META --}}
                    <div class="text-right">
                        <div class="text-3xl font-bold uppercase">
                            {{ type_of($this->getRecord()->type, 'invoices', 'type')->name }}
                        </div>

                        <div class="mt-2 text-sm text-gray-500">
                            {{ $this->getRecord()->uuid }}
                        </div>

                        <div class="mt-8 space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-400">Issue Date</span>
                                <span class="font-medium">
                                    {{ $this->getRecord()->created_at->toDateString() }}
                                </span>
                            </div>

                            <div class="flex justify-between">
                                <span class="text-gray-400">Due Date</span>
                                <span class="font-medium">
                                    {{ $this->getRecord()->due_date->toDateString() }}
                                </span>
                            </div>

                            <div class="flex justify-between">
                                <span class="text-gray-400">Status</span>
                                <span class="font-semibold">
                                    {{ type_of($this->getRecord()->status, 'invoices', 'status')->name }}
                                </span>
                            </div>
                        </div>
                    </div>

                </div>


                {{-- ================= ITEMS ================= --}}
                <div class="border rounded-2xl overflow-hidden">

                    <div class="grid grid-cols-5 bg-gray-50 text-xs uppercase tracking-wide text-gray-500 font-semibold p-4">
                        <div class="col-span-2">Item</div>
                        <div class="text-right">Price</div>
                        <div class="text-right">Qty</div>
                        <div class="text-right">Total</div>
                    </div>

                    @foreach($this->getRecord()->invoicesitems as $item)
                        <div class="grid grid-cols-5 p-4 border-t text-sm items-start">

                            <div class="col-span-2">
                                <div class="font-semibold text-gray-900">
                                    {{ $item->item }}
                                </div>

                                @if($item->description)
                                    <div class="text-gray-400 text-xs mt-1">
                                        {!! $item->description !!}
                                    </div>
                                @endif
                            </div>

                            <div class="text-right">
                                <small class="text-xs text-gray-400">
                                    {{ $this->getRecord()->currency?->iso }}
                                </small>
                                {{ number_format($item->price, 2) }}
                            </div>

                            <div class="text-right">
                                {{ $item->qty }}
                            </div>

                            <div class="text-right font-semibold">
                                <small class="text-xs text-gray-400">
                                    {{ $this->getRecord()->currency?->iso }}
                                </small>
                                {{ number_format($item->total, 2) }}
                            </div>

                        </div>
                    @endforeach

                </div>


                {{-- ================= TOTAL ================= --}}
                <div class="flex justify-end">

                    <div class="w-96 space-y-3">

                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500">Subtotal</span>
                            <span class="font-medium">
                                {{ $this->getRecord()->currency?->iso }}
                                {{ number_format(($this->getRecord()->grand_total + $this->getRecord()->total_discount) - ($this->getRecord()->total_vat + $this->getRecord()->shipping), 2) }}
                            </span>
                        </div>

                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500">Tax</span>
                            <span>
                                {{ $this->getRecord()->currency?->iso }}
                                {{ number_format($this->getRecord()->total_vat, 2) }}
                            </span>
                        </div>

                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500">Discount</span>
                            <span>
                                {{ $this->getRecord()->currency?->iso }}
                                {{ number_format($this->getRecord()->total_discount, 2) }}
                            </span>
                        </div>

                        <div class="border-t pt-3 flex justify-between text-lg font-bold">
                            <span>Total</span>
                            <span>
                                {{ $this->getRecord()->currency?->iso }}
                                {{ number_format($this->getRecord()->grand_total, 2) }}
                            </span>
                        </div>

                    </div>

                </div>


                {{-- ================= NOTES ================= --}}
                @if($this->getRecord()->notes)
                    <div class="pt-8 border-t">
                        <div class="text-sm uppercase tracking-wide text-gray-400 font-semibold mb-2">
                            Notes
                        </div>

                        <div class="text-sm text-gray-600">
                            {!! $this->getRecord()->notes !!}
                        </div>
                    </div>
                @endif

            </div>
        </x-filament::section>


        {{-- ================= RELATION MANAGERS (TETAP ADA) ================= --}}
        <div class="no-print">
            @php
                $relationManagers = $this->getRelationManagers();
                $hasCombinedRelationManagerTabsWithContent = $this->hasCombinedRelationManagerTabsWithContent();
            @endphp

            @if (count($relationManagers))
                <x-filament-panels::resources.relation-managers
                    :active-locale="isset($activeLocale) ? $activeLocale : null"
                    :active-manager="$this->activeRelationManager ?? ($hasCombinedRelationManagerTabsWithContent ? null : array_key_first($relationManagers))"
                    :content-tab-label="$this->getContentTabLabel()"
                    :content-tab-icon="$this->getContentTabIcon()"
                    :content-tab-position="$this->getContentTabPosition()"
                    :managers="$relationManagers"
                    :owner-record="$record"
                    :page-class="static::class"
                >
                    @if ($hasCombinedRelationManagerTabsWithContent)
                        <x-slot name="content">
                            @if ($this->hasInfolist())
                                {{ $this->infolist }}
                            @else
                                {{ $this->form }}
                            @endif
                        </x-slot>
                    @endif
                </x-filament-panels::resources.relation-managers>
            @endif
        </div>

    </x-filament-panels::page>
</div>
