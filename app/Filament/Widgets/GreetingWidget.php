<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class GreetingWidget extends Widget
{
    protected static string $view = 'filament.widgets.greeting-widget';
    protected int|string|array $columnSpan = [
    'default' => 'full',
    'md' => 1,
    'lg' => 1,
    'xl' => 1,
    ];
    protected static ?int $sort = 0;


    public function getViewData(): array
{
    $user = Auth::user();
    $name = $user ? $user->name : 'Guest';

    // Ambil jam sekarang
    $hour = now()->hour;

    if ($hour >= 5 && $hour < 11) {
        $greeting = 'Selamat Pagi';
        $messages = [
            'Semoga harimu penuh semangat! ☀️',
            'Yuk mulai hari dengan senyuman 😄',
            'Waktunya kejar target hari ini! 🚀',
            'Semoga semua urusan lancar pagi ini ✨',
        ];
    } elseif ($hour >= 11 && $hour < 18) {
        $greeting = 'Selamat Siang';
        $messages = [
            'Jangan lupa makan siang ya! 🍽️',
            'Tetap semangat sampai sore! 💪',
            'Minum air dulu biar tetap fokus 💧',
            'Semoga kerjaanmu lancar hari ini 🚀',
        ];
    } else {
        $greeting = 'Selamat Malam';
        $messages = [
            'Waktunya istirahat dan recharge 🌙',
            'Terima kasih sudah bekerja keras hari ini ✨',
            'Jangan lupa tidur cukup ya 😴',
            'Semoga mimpimu indah malam ini 🌌',
        ];
    }

    // Ambil pesan random
    $message = $messages[array_rand($messages)];

    return compact('greeting', 'name', 'message');
}

}

