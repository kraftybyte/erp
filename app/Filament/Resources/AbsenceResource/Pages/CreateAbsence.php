<?php

namespace App\Filament\Resources\AbsenceResource\Pages;

use App\Filament\Resources\AbsenceResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use App\Models\Absence;

class CreateAbsence extends CreateRecord
{
    protected static string $resource = AbsenceResource::class;

    // Head, Director, dan super_admin diberi tahu oleh AbsenceObserver::created.

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
