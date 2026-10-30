{{-- Dipakai halaman Realisasi dan View Advance. Hanya tata letak; warna dari
     variabel tema Filament supaya ikut terang/gelap (Tailwind di public/build
     tidak dikompilasi ulang, jadi kelas utilitas baru tidak tersedia). --}}
<style>
    .rl-figures { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1.5rem; }
    .rl-figures.is-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    .rl-figure dt { font-size: .8125rem; color: rgb(var(--gray-500)); }
    .rl-figure dd { margin: .25rem 0 0; font-size: 1.5rem; line-height: 2rem; font-weight: 600; font-variant-numeric: tabular-nums; color: rgb(var(--gray-950)); }
    .rl-figure p { margin-top: .125rem; font-size: .8125rem; color: rgb(var(--gray-500)); }
    .rl-figure dd.is-over { color: rgb(var(--warning-800)); }
    .dark .rl-figure dd { color: #fff; }
    .dark .rl-figure dd.is-over { color: rgb(var(--warning-300)); }
    .dark .rl-figure dt, .dark .rl-figure p { color: rgb(var(--gray-400)); }

    .rl-progress { height: .5rem; border-radius: 9999px; background: rgb(var(--gray-200)); overflow: hidden; }
    .rl-progress > span { display: block; height: 100%; border-radius: inherit; background: rgb(var(--primary-600)); transition: width .3s ease; }
    .rl-progress.is-over > span { background: rgb(var(--warning-600)); }
    .dark .rl-progress { background: rgba(255, 255, 255, .1); }
    .dark .rl-progress > span { background: rgb(var(--primary-500)); }
    .dark .rl-progress.is-over > span { background: rgb(var(--warning-500)); }

    .rl-meta { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1rem; font-size: .875rem; color: rgb(var(--gray-500)); }
    .rl-meta strong { font-weight: 500; color: rgb(var(--gray-950)); }
    .dark .rl-meta { color: rgb(var(--gray-400)); }
    .dark .rl-meta strong { color: #fff; }

    .rl-note { border-radius: .75rem; padding: .875rem 1rem; font-size: .875rem; border: 1px solid; }
    .rl-note strong { display: block; font-weight: 600; }
    .rl-note p { margin-top: .125rem; }
    .rl-note[data-color="info"]    { background: rgba(var(--info-500), .07);    border-color: rgba(var(--info-500), .25);    color: rgb(var(--info-700)); }
    .rl-note[data-color="success"] { background: rgba(var(--success-500), .07); border-color: rgba(var(--success-500), .25); color: rgb(var(--success-700)); }
    .rl-note[data-color="warning"] { background: rgba(var(--warning-500), .14); border-color: rgba(var(--warning-600), .45); color: rgb(var(--warning-900)); }
    .rl-note[data-color="gray"]    { background: rgba(var(--gray-500), .06);    border-color: rgba(var(--gray-500), .2);     color: rgb(var(--gray-700)); }
    .dark .rl-note[data-color="info"]    { color: rgb(var(--info-300)); }
    .dark .rl-note[data-color="success"] { color: rgb(var(--success-300)); }
    .dark .rl-note[data-color="warning"] { color: rgb(var(--warning-300)); }
    .dark .rl-note[data-color="gray"]    { color: rgb(var(--gray-300)); }
    .rl-note[data-color="danger"] { background: rgba(var(--danger-500), .07); border-color: rgba(var(--danger-500), .25); color: rgb(var(--danger-700)); }
    .dark .rl-note[data-color="danger"] { color: rgb(var(--danger-300)); }

    /* Item batch: tabel di layar lebar, bertumpuk di HP. */
    .rl-items { font-size: .875rem; }
    .rl-item, .rl-items-head { display: grid; grid-template-columns: minmax(0, 1fr) 4.5rem 8.5rem 9rem; gap: .75rem; align-items: start; }
    .rl-items-head { padding-bottom: .5rem; border-bottom: 1px solid rgba(var(--gray-950), .06); font-size: .75rem; font-weight: 500; color: rgb(var(--gray-500)); }
    .rl-item { padding: .875rem 0; border-bottom: 1px solid rgba(var(--gray-950), .05); }
    .rl-item:last-child { border-bottom: 0; padding-bottom: 0; }
    .rl-item .num, .rl-items-head .num { text-align: right; font-variant-numeric: tabular-nums; }
    .rl-item .name { font-weight: 500; color: rgb(var(--gray-950)); }
    .rl-item .desc { margin-top: .125rem; color: rgb(var(--gray-500)); }
    .rl-item .subtotal { font-weight: 600; color: rgb(var(--gray-950)); }
    .rl-item .qty-mobile { display: none; }
    .dark .rl-items-head { border-color: rgba(255, 255, 255, .1); color: rgb(var(--gray-400)); }
    .dark .rl-item { border-color: rgba(255, 255, 255, .06); }
    .dark .rl-item .name, .dark .rl-item .subtotal { color: #fff; }
    .dark .rl-item .desc { color: rgb(var(--gray-400)); }

    .rl-files { display: flex; flex-wrap: wrap; gap: .375rem; margin-top: .5rem; }
    .rl-file { display: inline-flex; align-items: center; gap: .25rem; max-width: 100%; padding: .125rem .5rem; border-radius: .375rem; font-size: .75rem; font-weight: 500;
               color: rgb(var(--primary-700)); background: rgba(var(--primary-500), .08); border: 1px solid rgba(var(--primary-500), .2); text-decoration: none; }
    .rl-file:hover { background: rgba(var(--primary-500), .14); }
    .dark .rl-file { color: rgb(var(--primary-300)); background: rgba(var(--primary-400), .1); border-color: rgba(var(--primary-400), .25); }

    .rl-drop { display: block; border: 2px dashed rgba(var(--gray-950), .12); border-radius: .75rem; padding: 1.25rem; text-align: center; cursor: pointer; transition: border-color .15s, background .15s; }
    .rl-drop:hover, .rl-drop.is-dragging { border-color: rgb(var(--primary-500)); background: rgba(var(--primary-500), .05); }
    .dark .rl-drop { border-color: rgba(255, 255, 255, .15); }

    .rl-form-item { border-radius: .75rem; padding: .875rem; background: rgba(var(--gray-950), .03); }
    .dark .rl-form-item { background: rgba(255, 255, 255, .04); }
    .rl-form-grid { display: grid; grid-template-columns: minmax(0, 1fr) 5.5rem 9rem; gap: .5rem; align-items: end; }
    .rl-label { display: block; margin-bottom: .25rem; font-size: .75rem; font-weight: 500; color: rgb(var(--gray-600)); }
    .dark .rl-label { color: rgb(var(--gray-400)); }
    .rl-total { display: flex; justify-content: space-between; align-items: baseline; padding: .75rem 1rem; border-radius: .75rem; background: rgba(var(--primary-500), .07); font-variant-numeric: tabular-nums; }
    .rl-total strong { font-size: 1.125rem; color: rgb(var(--gray-950)); }
    .dark .rl-total strong { color: #fff; }

    /* Pasangan label–isi di View Advance. */
    .rl-details { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1.25rem 2rem; font-size: .875rem; }
    .rl-details dt { color: rgb(var(--gray-500)); }
    .rl-details dd { margin: .125rem 0 0; font-weight: 500; color: rgb(var(--gray-950)); overflow-wrap: anywhere; }
    .rl-details .span-all { grid-column: 1 / -1; }
    .rl-details dd.is-plain { font-weight: 400; white-space: pre-line; }
    .dark .rl-details dt { color: rgb(var(--gray-400)); }
    .dark .rl-details dd { color: #fff; }

    /* Beberapa batch dalam satu section (View Advance). */
    .rl-batch + .rl-batch { margin-top: 1.25rem; padding-top: 1.25rem; border-top: 1px solid rgba(var(--gray-950), .08); }
    .dark .rl-batch + .rl-batch { border-color: rgba(255, 255, 255, .1); }
    .rl-batch-head { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; margin-bottom: .75rem; }
    .rl-batch-head strong { font-weight: 600; color: rgb(var(--gray-950)); }
    .rl-batch-head span { font-size: .8125rem; color: rgb(var(--gray-500)); }
    .rl-batch-head .total { font-weight: 600; font-variant-numeric: tabular-nums; color: rgb(var(--gray-950)); white-space: nowrap; }
    .dark .rl-batch-head strong, .dark .rl-batch-head .total { color: #fff; }
    .dark .rl-batch-head span { color: rgb(var(--gray-400)); }

    @media (max-width: 1024px) {
        .rl-figures.is-4 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    @media (max-width: 640px) {
        .rl-figures, .rl-figures.is-4 { grid-template-columns: 1fr; gap: 1rem; }
        .rl-details { grid-template-columns: 1fr; }
        .rl-items-head { display: none; }
        .rl-item { grid-template-columns: minmax(0, 1fr) auto; }
        .rl-item .qty, .rl-item .price { display: none; }
        .rl-item .qty-mobile { display: block; margin-top: .125rem; color: rgb(var(--gray-500)); }
        .rl-form-grid { grid-template-columns: 1fr 1fr; }
        .rl-form-grid .span-all { grid-column: 1 / -1; }
    }
</style>
