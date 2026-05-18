<?php

namespace App\Filament\Resources\AbsenceResource\Pages;

use App\Filament\Resources\AbsenceResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use App\Models\User;
use App\Models\Absence;

class CreateAbsence extends CreateRecord
{
    protected static string $resource = AbsenceResource::class;

    protected function afterCreate(): void
    {

        // Kirim notifikasi ke Director dan super_admin
        $recipients = User::role(['Head', 'Director', 'super_admin'])->get();
        $user = auth()->user();
        $senderName = "{$user->firstname} {$user->lastname}";


        Notification::make()
            ->title('Pengajuan Absensi Baru')
            ->body("{$senderName} baru saja mengajukan absensi.")
            ->success()
            ->sendToDatabase($recipients);
    }

    protected function getCreatedNotification(): ?Notification
    {
        // Ini yang tampil ke user yang bikin record
        return Notification::make()
            ->title('Berhasil Mengajukan Izin')
            ->body('Pengajuan izinmu berhasil dikirim.')
            ->success();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
