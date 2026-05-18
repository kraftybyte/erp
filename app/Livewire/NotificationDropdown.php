<?php

namespace App\Livewire;

use Livewire\Component;

class NotificationDropdown extends Component
{
    public function getNotificationsProperty()
    {
        return auth()->user()
            ?->notifications()
            ->latest()
            ->take(8)
            ->get() ?? collect();
    }

    public function getUnreadCountProperty(): int
    {
        return auth()->user()
            ?->unreadNotifications()
            ->count() ?? 0;
    }

    public function markAllRead(): void
    {
        auth()->user()?->unreadNotifications()->update(['read_at' => now()]);
    }

    public function markRead(string $id): void
    {
        auth()->user()
            ?->notifications()
            ->where('id', $id)
            ->update(['read_at' => now()]);
    }

    public function clearAll(): void
    {
        auth()->user()?->notifications()->delete();
    }

    public function render()
    {
        return view('livewire.notification-dropdown', [
            'notifications' => $this->notifications,
            'unreadCount'   => $this->unreadCount,
        ]);
    }
}
