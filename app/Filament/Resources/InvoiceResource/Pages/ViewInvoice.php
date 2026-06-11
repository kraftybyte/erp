<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use App\Filament\Resources\InvoiceResource;
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
}
