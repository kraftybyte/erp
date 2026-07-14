<?php

namespace App\Filament\Resources\ContractResource\Pages;

use App\Filament\Resources\ContractResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewContract extends ViewRecord
{
    protected static string $resource = ContractResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('previewHtml')
                ->label('Preview HTML')
                ->icon('heroicon-o-document-arrow-up')
                ->color('info')
                ->url(fn () => route('filament.admin.resources.contracts.preview', $this->record))
                ->openUrlInNewTab(),

            Actions\Action::make('downloadPdf')
                ->label('Download PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->url(fn () => route('filament.admin.resources.contracts.download-pdf', $this->record)),

            Actions\EditAction::make(),
        ];
    }

    protected function fillFormWithDataAndRelationships(): void
    {
        // Load relationships before filling form
        $this->record->load([
            'feeItems',
            'thirdPartyItems',
            'sowCategories' => function ($query) {
                $query->with('children')->orderBy('sort_order');
            },
            'deal',
        ]);

        parent::fillFormWithDataAndRelationships();
    }
}
