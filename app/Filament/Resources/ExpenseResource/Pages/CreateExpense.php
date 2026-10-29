<?php

namespace App\Filament\Resources\ExpenseResource\Pages;

use App\Filament\Resources\ExpenseResource;
use App\Services\AutoApproval;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;
    /**
     * Pengajuan selalu atas nama user yang login dan dimulai dari Pending.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();
        $data['status']  = 'Pending';

        return $data;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return AutoApproval::wasApplied($this->record)
            ? 'Expense terkirim dan langsung disetujui (auto approve)'
            : parent::getCreatedNotificationTitle();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
