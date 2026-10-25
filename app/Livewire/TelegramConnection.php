<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\Telegram\TelegramLinker;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Jeffgreco13\FilamentBreezy\Livewire\MyProfileComponent;

/**
 * Bagian "Telegram" di halaman Profil: membuat link sekali pakai untuk
 * menghubungkan akun, dan memutuskannya.
 */
class TelegramConnection extends MyProfileComponent
{
    protected string $view = 'livewire.telegram-connection';

    public static $sort = 20;

    public ?string $linkUrl = null;

    public function generateLink(): void
    {
        $this->linkUrl = app(TelegramLinker::class)->linkFor($this->user());

        if (! $this->linkUrl) {
            Notification::make()
                ->danger()
                ->title('Bot Telegram belum siap')
                ->body('Minta admin mengisi token bot di Settings → Integrasi.')
                ->send();
        }
    }

    public function disconnect(): void
    {
        app(TelegramLinker::class)->unlink($this->user());
        $this->linkUrl = null;

        Notification::make()->success()->title('Telegram diputuskan')->send();
    }

    public function render()
    {
        return view($this->view, [
            'user'       => $this->user()->fresh(),
            'ttlMinutes' => TelegramLinker::TTL_MINUTES,
        ]);
    }

    private function user(): User
    {
        return Filament::auth()->user();
    }
}
