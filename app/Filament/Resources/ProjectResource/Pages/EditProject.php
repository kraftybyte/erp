<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\ProjectResource;
use App\Services\ProjectFromInvoice;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProject extends EditRecord
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Untuk project dari invoice: susun ulang Detail Project dari item invoice
            // (mis. setelah invoice direvisi). Baru tersimpan setelah tombol Save.
            Actions\Action::make('detailFromInvoice')
                ->label('Ambil ulang detail dari invoice')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn () => $this->record->invoice && InvoiceResource::canView($this->record->invoice))
                ->requiresConfirmation()
                ->modalDescription(fn () => 'Detail Project di form diganti dengan rincian item invoice ' . $this->record->invoice?->uuid . '. Belum tersimpan sampai Anda menekan Save.')
                ->action(fn () => $this->data['detail_project'] = ProjectFromInvoice::detail($this->record->invoice->load('invoicesItems'))),
            Actions\DeleteAction::make(),
        ];
    }
}
