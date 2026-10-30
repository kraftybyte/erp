    <div>
        <x-filament-panels::page>

            @php
                $invoice = $this->getRecord();
                $money   = fn ($value) => \App\Helpers\Money::inCurrency($value, $invoice->currency?->iso);
                $date    = fn ($value) => $value ? \Carbon\Carbon::parse($value)->locale('id')->translatedFormat('j M Y') : '—';
                $subtotal = ($invoice->grand_total + $invoice->total_discount) - ($invoice->total_vat + $invoice->shipping);
            @endphp

            {{-- Warna dari variabel tema supaya ikut terang/gelap; tata letak
                 bertumpuk di HP (dulu grid 2 dan 5 kolom tetap). --}}
            <style>
                .iv { display: flex; flex-direction: column; gap: 2rem; font-size: .875rem; color: rgb(var(--gray-700)); }
                .iv-head { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 18rem); gap: 2rem; }
                .iv-label { font-size: .75rem; color: rgb(var(--gray-500)); }
                .iv-strong { font-weight: 600; color: rgb(var(--gray-950)); }
                .iv-type { font-size: 1.5rem; line-height: 2rem; font-weight: 700; color: rgb(var(--gray-950)); }
                .iv-meta { display: grid; gap: .5rem; margin-top: 1rem; }
                .iv-meta div, .iv-totals div { display: flex; justify-content: space-between; gap: 1rem; }
                .iv-items { border: 1px solid rgba(var(--gray-950), .08); border-radius: .75rem; overflow: hidden; }
                .iv-row { display: grid; grid-template-columns: minmax(0, 1fr) 9rem 4rem 9rem; gap: .75rem; padding: .875rem 1rem; align-items: start; }
                .iv-row + .iv-row { border-top: 1px solid rgba(var(--gray-950), .06); }
                .iv-row.is-head { background: rgba(var(--gray-950), .03); font-size: .75rem; font-weight: 500; color: rgb(var(--gray-500)); }
                .iv-row .num { text-align: right; font-variant-numeric: tabular-nums; }
                .iv-row .desc { margin-top: .25rem; font-size: .8125rem; color: rgb(var(--gray-500)); }
                .iv-row .qty-mobile { display: none; }
                .iv-totals { display: grid; gap: .5rem; width: min(100%, 22rem); margin-left: auto; font-variant-numeric: tabular-nums; }
                .iv-totals .grand { padding-top: .75rem; border-top: 1px solid rgba(var(--gray-950), .1); font-size: 1rem; font-weight: 700; color: rgb(var(--gray-950)); }
                .iv-notes { padding-top: 1.25rem; border-top: 1px solid rgba(var(--gray-950), .08); white-space: pre-line; }
                .dark .iv { color: rgb(var(--gray-200)); }
                .dark .iv-label, .dark .iv-row.is-head, .dark .iv-row .desc { color: rgb(var(--gray-300)); }
                .dark .iv-strong, .dark .iv-type, .dark .iv-totals .grand { color: #fff; }
                .dark .iv-items { border-color: rgba(255, 255, 255, .1); }
                .dark .iv-row + .iv-row { border-color: rgba(255, 255, 255, .06); }
                .dark .iv-row.is-head { background: rgba(255, 255, 255, .03); }
                .dark .iv-totals .grand, .dark .iv-notes { border-color: rgba(255, 255, 255, .1); }
                @media (max-width: 640px) {
                    .iv-head { grid-template-columns: 1fr; }
                    .iv-row.is-head { display: none; }
                    .iv-row { grid-template-columns: minmax(0, 1fr) auto; }
                    .iv-row .price, .iv-row .qty { display: none; }
                    .iv-row .qty-mobile { display: block; margin-top: .25rem; color: rgb(var(--gray-500)); }
                }
            </style>

            <x-filament::section>
                <div class="iv">
                    <div class="iv-head">
                        <div>
                            <div class="iv-label">Ditagihkan kepada</div>
                            <div class="iv-strong mt-1">{{ $invoice->nama_perusahaan ?: '—' }}</div>
                            @if(filled($invoice->nama_pic) && $invoice->nama_pic !== '-')
                                <div class="mt-1">u.p. {{ $invoice->nama_pic }}</div>
                            @endif
                            @if(filled($invoice->alamat))
                                <div class="mt-1">{{ $invoice->alamat }}</div>
                            @endif
                            @if(filled($invoice->telephone))
                                <div class="mt-1">{{ $invoice->telephone }}</div>
                            @endif
                        </div>

                        <div>
                            <div class="iv-type">{{ type_of($invoice->type, 'invoices', 'type')?->name ?? ucfirst((string) $invoice->type) }}</div>
                            <div class="iv-label mt-1">{{ $invoice->uuid }}</div>
                            <div class="iv-meta">
                                <div><span class="iv-label">Tanggal</span><span class="iv-strong">{{ $date($invoice->created_at) }}</span></div>
                                <div><span class="iv-label">Jatuh tempo</span><span class="iv-strong">{{ $date($invoice->due_date) }}</span></div>
                                <div><span class="iv-label">Status</span><span class="iv-strong">{{ type_of($invoice->status, 'invoices', 'status')?->name ?? (filled($invoice->status) ? ucfirst($invoice->status) : '—') }}</span></div>
                            </div>
                        </div>
                    </div>

                    <div class="iv-items" role="table" aria-label="Item invoice">
                        <div class="iv-row is-head" role="row">
                            <span role="columnheader">Item</span>
                            <span role="columnheader" class="num">Harga</span>
                            <span role="columnheader" class="num">Qty</span>
                            <span role="columnheader" class="num">Total</span>
                        </div>
                        @foreach($invoice->invoicesitems as $item)
                            <div class="iv-row" role="row">
                                <div role="cell">
                                    <div class="iv-strong">{{ $item->item }}</div>
                                    @if($item->description)
                                        <div class="desc">{!! str($item->description)->sanitizeHtml() !!}</div>
                                    @endif
                                    <div class="qty-mobile">{{ \App\Helpers\Money::qty($item->qty) }} × {{ $money($item->price) }}</div>
                                </div>
                                <div role="cell" class="num price">{{ $money($item->price) }}</div>
                                <div role="cell" class="num qty">{{ \App\Helpers\Money::qty($item->qty) }}</div>
                                <div role="cell" class="num iv-strong">{{ $money($item->total) }}</div>
                            </div>
                        @endforeach
                    </div>

                    <div class="iv-totals">
                        <div><span>Subtotal</span><span>{{ $money($subtotal) }}</span></div>
                        @if((float) $invoice->total_vat)
                            <div><span>Pajak</span><span>{{ $money($invoice->total_vat) }}</span></div>
                        @endif
                        @if((float) $invoice->total_discount)
                            <div><span>Diskon</span><span>-{{ $money($invoice->total_discount) }}</span></div>
                        @endif
                        @if((float) $invoice->shipping)
                            <div><span>Ongkos kirim</span><span>{{ $money($invoice->shipping) }}</span></div>
                        @endif
                        <div class="grand"><span>Total</span><span>{{ $money($invoice->grand_total) }}</span></div>
                    </div>

                    @if($invoice->notes)
                        <div class="iv-notes">
                            <div class="iv-label">Catatan</div>
                            <div class="mt-1">{{ $invoice->notes }}</div>
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
