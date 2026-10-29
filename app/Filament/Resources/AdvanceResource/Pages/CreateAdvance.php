<?php

namespace App\Filament\Resources\AdvanceResource\Pages;

use App\Filament\Resources\AdvanceResource;
use App\Models\Advance;
use App\Services\AutoApproval;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateAdvance extends CreateRecord
{
    protected static string $resource = AdvanceResource::class;
    /**
     * Pengajuan selalu atas nama user yang login dan dimulai dari Pending.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();
        $data['status']  = Advance::STATUS_PENDING;

        return $data;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return AutoApproval::wasApplied($this->record)
            ? 'Advance terkirim dan langsung disetujui (auto approve)'
            : parent::getCreatedNotificationTitle();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
