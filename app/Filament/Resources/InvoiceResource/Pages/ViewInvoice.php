<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use App\Filament\Resources\InvoiceResource;
use App\Models\Deal;
use Filament\Actions;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected static string $view = 'filament.invoices.pages.view-invoice';

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->icon('heroicon-o-pencil'),
            Actions\DeleteAction::make()->icon('heroicon-o-trash'),
            Actions\Action::make('download-pdf')
                ->label('Download Invoice')
                ->icon('heroicon-o-arrow-down-on-square-stack')
                ->color('info')
                ->url(fn () => route('invoices.download', $this->record))
                ->openUrlInNewTab(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Eager load invoicesItems relationship for view
        $this->record->load('invoicesItems');

        /*
        | Halaman tampilan harus menunjukkan apa yang tercetak di invoice.
        |
        | Sebelumnya nama di sini diganti dengan nama Deal yang kebetulan cocok,
        | sehingga layar bisa menampilkan nama yang berbeda dari yang tertulis di
        | PDF yang sudah dikirim ke klien.
        */
        $data['_deal_id'] = $this->record->deal_id;

        return $data;
    }
}
