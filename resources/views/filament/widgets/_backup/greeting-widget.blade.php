<x-filament-widgets::widget>

    @php
        $hour = now()->hour;

        $morningTexts = [
            'Semoga harimu penuh energi dan semangat 🚀',
            'Mulai hari dengan langkah yang mantap ya!',
            'Pagi yang produktif dimulai dari sini ☀️',
            'Yuk, selesaikan yang terbaik hari ini!',
            'Selamat pagi! Hari baru, semangat baru 💪',
            'Kopi sudah? Siap tancap gas! ☕',
            'Hari ini pasti lebih baik dari kemarin 🌟',
            'Bismillah, semoga lancar semua urusannya!',
        ];

        $noonTexts = [
            'Jangan lupa istirahat sejenak dan isi energi',
            'Setengah hari sudah terlewati, tetap semangat!',
            'Waktunya stretching dan minum air putih 💧',
            'Siang yang sibuk? Tarik napas dulu sebentar 😊',
            'Makan siang dulu, biar otak tetap fresh!',
            'Jaga stamina, masih ada sisa hari yang menanti 🌤️',
            'Istirahat sebentar itu bukan malas, tapi investasi! ✨',
        ];

        $afternoonTexts = [
            'Semangat! Sedikit lagi selesai hari ini',
            'Sprint terakhir! Finish strong 💥',
            'Hampir sampai garis finish, jangan kendur!',
            'Produktivitasmu hari ini luar biasa 🔥',
            'Sore ini, selesaikan satu hal lagi yang berarti',
            'Tetap fokus, sudah dekat dengan akhir hari 🎯',
            'Tinggal sedikit lagi, kamu pasti bisa!',
        ];

        $eveningTexts = [
            'Waktu yang tepat untuk refleksi dan recharge',
            'Sudah kerja keras hari ini, kamu layak istirahat 🌙',
            'Evaluasi sebentar, besok kita lebih baik lagi',
            'Malam ini, simpan energi untuk hari esok ⭐',
            'Terima kasih sudah berjuang penuh hari ini!',
            'Istirahat yang baik adalah kunci produktivitas esok 💤',
            'Hari ini sudah luar biasa, lanjutkan besok 🌛',
        ];

        $pool = match(true) {
            $hour >= 5  && $hour < 12 => $morningTexts,
            $hour >= 12 && $hour < 15 => $noonTexts,
            $hour >= 15 && $hour < 18 => $afternoonTexts,
            default                   => $eveningTexts,
        };

        // Seed berdasarkan user id + tanggal supaya konsisten per hari per user
        $seed  = (auth()->id() ?? 0) + now()->dayOfYear;
        $index = $seed % count($pool);
        $subText = $pool[$index];
    @endphp

<style>
@media (max-width: 640px) {
    .name-mobile-break {
        display: block;
        margin-top: 4px;
    }
}
</style>

<div style="padding: 8px 4px;">
    <h3 style="font-size: 28px; font-weight: 500; color: #111827; margin: 0 0 6px; line-height: 1.2;">
        {{ $greeting }}, 
        <span class="name-mobile-break" style="font-weight: 800;">
            {{ $name }} 👋
        </span>
    </h3>

    <p style="font-size: 15px; color: #6b7280; margin: 0;">
        {{ $subText }}
    </p>
</div>
</x-filament-widgets::widget>