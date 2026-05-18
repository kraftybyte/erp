<?php

namespace App\Filament\Resources\DealResource\Pages;

use App\Filament\Resources\DealResource;
use App\Imports\DealImport;
use App\Exports\SampleExport;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;


class ListDeals extends ListRecords
{
    protected static string $resource = DealResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\Action::make('download_sample')
                ->label('Download Sample')
                ->color('gray')
                ->icon('heroicon-m-arrow-down-tray')
                ->action(function () {
                    return Excel::download(new SampleExport(), 'sample_deals.xlsx');
                }),
            Actions\Action::make('import_excel')
                ->label('Import Excel')
                ->color('info')
                ->icon('heroicon-m-clipboard')
                ->form([
                    \Filament\Forms\Components\FileUpload::make('file')
                        ->label('File Excel')
                        ->acceptedFileTypes(['xlsx', 'xls', 'csv'])
                        ->required(),
                ])
                ->action(function (array $data) {
                    $path = storage_path('app/' . $data['file']);

                    Excel::import(new DealImport(), $path);

                    \Filament\Notifications\Notification::make()
                        ->title('Import Berhasil')
                        ->body('Data deal berhasil diimpor.')
                        ->success()
                        ->send();
                })
                ->modalHeading('Import Data Deal')
                ->modalSubmitActionLabel('Import'),
        ];
    }
}