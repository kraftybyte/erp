<?php

namespace App\Filament\Resources\QuotationResource\Pages;

use App\Filament\Resources\QuotationResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Actions;
use Filament\Actions\Action;

class ViewQuotation extends ViewRecord
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('download_pdf')
                ->label('Download PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->url(fn () => route('quotations.pdf', $this->record->id))
                ->openUrlInNewTab()
                ->color('success'),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Eager load items relationship for view
        $this->record->load('items');

        return $data;
    }
}
