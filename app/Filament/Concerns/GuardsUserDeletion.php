<?php

namespace App\Filament\Concerns;

use App\Models\User;
use Filament\Actions\MountableAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Tombol hapus user/karyawan: batal dengan pesan yang jelas bila user masih
 * punya data (expense, advance, invoice, cuti, aset, …). Penjaga sebenarnya
 * ada di User::booted(); ini hanya agar pengguna tahu alasannya.
 */
class GuardsUserDeletion
{
    public static function single(MountableAction $action, User $record): void
    {
        self::guard($action, collect([$record]));
    }

    public static function bulk(MountableAction $action, Collection $records): void
    {
        self::guard($action, $records);
    }

    private static function guard(MountableAction $action, Collection $records): void
    {
        $blocked = $records->filter(fn (User $user) => $user->hasBusinessRecords());

        if ($blocked->isEmpty()) {
            return;
        }

        Notification::make()
            ->title('Tidak bisa dihapus')
            ->body($blocked->map(fn (User $user) => $user->name)->implode(', ')
                . ' masih punya data (pengajuan, invoice, cuti, aset, riwayat task, dll.) yang akan ikut terhapus. '
                . 'Matikan "Akun aktif" di halaman edit-nya agar tidak bisa login lagi; datanya tetap tersimpan.')
            ->danger()
            ->persistent()
            ->send();

        $action->cancel();
    }
}
