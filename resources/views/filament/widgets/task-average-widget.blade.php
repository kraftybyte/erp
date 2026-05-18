<x-filament-widgets::widget style="height:100%;display:flex;flex-direction:column;">
@php
$settings = app(\App\Settings\GeneralSettings::class);
$primary = $settings->site_theme['primary'] ?? '#6366f1';
@endphp

    <div class="rounded-2xl p-8 shadow-lg flex-1 flex items-center" style="background:{{ $primary }};position:relative;overflow:hidden;">

        <div class="text-white w-full" style="display:flex;flex-direction:column;gap:14px;">

            <h2 style="font-size:11px;font-weight:700;text-transform:uppercase;opacity:0.7;margin:0;">
                Rata-rata Penyelesaian Task
            </h2>

            <div style="display:flex;align-items:baseline;gap:10px;">
                <span style="font-size:72px;font-weight:900;color:white;line-height:1;letter-spacing:-3px;">
                    {{ $average_days }}
                </span>
                <span style="font-size:20px;font-weight:500;opacity:0.85;">hari</span>
            </div>

            <div class="inline-block px-4 py-1.5 rounded-full bg-white/30 backdrop-blur-sm" style="font-size:13px;font-weight:600;width:fit-content;">
                {{ $average_days < 7 ? 'Sangat Baik' : ($average_days <= 14 ? 'Cukup Baik' : 'Perlu Ditingkatkan') }}
            </div>

            <p style="font-size:13px;opacity:0.85;margin:0;">
                {{ $average_days < 7
                    ? 'Performa tim kamu stabil dan cepat 🚀'
                    : ($average_days <= 14
                        ? 'Masih bisa lebih optimal ⚡'
                        : 'Perlu evaluasi ritme kerja 📊') }}
            </p>

        </div>

        <style>
            @media (max-width: 768px) { .tw-rocket { display: none !important; } }
        </style>
        <img src="/storage/sites/task.webp"
             class="tw-rocket"
             style="position:absolute;bottom:-100px;right:-40px;width:370px;height:370px;object-fit:contain;opacity:0.9;" />

    </div>

</x-filament-widgets::widget>