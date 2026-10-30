@php
    $isEdit = $this instanceof \Filament\Resources\Pages\EditRecord;
    $showPreview = $this->hasContractPreview();
    $previewHtml = $showPreview ? $this->contractPreviewHtml() : null;
@endphp

<x-filament-panels::page
    @class([
        $isEdit ? 'fi-resource-edit-record-page' : 'fi-resource-create-record-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
    ])
>
    <style>
        .ct-split { display: grid; gap: 1.5rem; align-items: start; }
        .ct-preview { border: 1px solid rgb(var(--gray-200)); border-radius: .75rem; background: #fff; overflow: hidden; }
        .ct-preview-head { display: flex; align-items: center; justify-content: space-between; gap: .75rem;
                           padding: .625rem 1rem; border-bottom: 1px solid rgb(var(--gray-200)); font-size: .875rem; }
        .ct-preview-title { font-weight: 600; color: rgb(var(--gray-950)); }
        .ct-preview-status { color: rgb(var(--gray-500)); }
        .ct-preview-box { height: 80vh; overflow: hidden; background: #e5e7eb; }
        .ct-preview-box iframe { display: block; width: 842px; border: 0; transform-origin: 0 0; }
        .ct-preview-empty { padding: 1.5rem 1rem; font-size: .875rem; color: rgb(var(--gray-600)); background: #fff; height: 100%; }
        .dark .ct-preview { border-color: rgb(255 255 255 / .1); background: rgb(var(--gray-900)); }
        .dark .ct-preview-head { border-color: rgb(255 255 255 / .1); }
        .dark .ct-preview-title { color: #fff; }
        .dark .ct-preview-status { color: rgb(var(--gray-400)); }
        .dark .ct-preview-empty { color: rgb(var(--gray-300)); background: rgb(var(--gray-900)); }

        /* Layar lebar: form dan kontrak bersebelahan, pratinjau menempel saat form digulir. */
        @media (min-width: 1280px) {
            .ct-split.has-preview { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
            .ct-preview { position: sticky; top: 5rem; }
            .ct-preview-box { height: calc(100vh - 9rem); }
        }
    </style>

    <div @class(['ct-split', 'has-preview' => $showPreview])>
        <x-filament-panels::form
            id="form"
            :wire:key="$this->getId() . '.forms.' . $this->getFormStatePath()"
            :wire:submit="$isEdit ? 'save' : 'create'"
        >
            {{ $this->form }}

            <x-filament-panels::form.actions
                :actions="$this->getCachedFormActions()"
                :full-width="$this->hasFullWidthFormActions()"
            />
        </x-filament-panels::form>

        @if ($showPreview)
            {{-- Halaman A4 (842px dengan tepi abu) diperkecil selebar kolom. Posisi gulir
                 dipulihkan setiap kali isi pratinjau dimuat ulang. --}}
            <aside
                class="ct-preview"
                x-data="{
                    scale: 1,
                    height: 600,
                    scrollTop: 0,
                    fit() {
                        const box = this.$refs.box
                        this.scale = Math.min(1, box.clientWidth / 842)
                        this.height = box.clientHeight / this.scale
                    },
                    restore() {
                        const win = this.$refs.frame?.contentWindow
                        if (! win) return
                        win.scrollTo(0, this.scrollTop)
                        win.addEventListener('scroll', () => this.scrollTop = win.scrollY, { passive: true })
                    },
                }"
                x-init="fit(); new ResizeObserver(() => fit()).observe($refs.box)"
            >
                <div class="ct-preview-head">
                    <span class="ct-preview-title">Preview kontrak</span>
                    <span class="ct-preview-status" wire:loading.delay>Memperbarui…</span>
                </div>

                <div class="ct-preview-box" x-ref="box">
                    @if ($previewHtml)
                        <iframe
                            x-ref="frame"
                            srcdoc="{{ $previewHtml }}"
                            sandbox="allow-same-origin"
                            title="Preview kontrak"
                            x-on:load="restore()"
                            x-bind:style="{ transform: `scale(${scale})`, height: `${height}px` }"
                        ></iframe>
                    @else
                        <p class="ct-preview-empty">Preview belum bisa ditampilkan. Periksa isian tanggal dan angka di form.</p>
                    @endif
                </div>
            </aside>
        @endif
    </div>

    <x-filament-panels::page.unsaved-data-changes-alert />
</x-filament-panels::page>
