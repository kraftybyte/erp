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

        // Find matching Deal based on nama_perusahaan and nama_pic
        $deal = Deal::where('nama_perusahaan', $this->record->nama_perusahaan)
            ->where('nama_pic', $this->record->nama_pic)
            ->first();

        // Set _deal_id for display purposes
        $data['_deal_id'] = $deal?->id;

        // If deal found, use Deal's data
        if ($deal) {
            $data['is_manual_company'] = false;
            $data['nama_perusahaan'] = $deal->nama_perusahaan ?? '-';
            $data['nama_pic'] = $deal->nama_pic ?? '-';
            $data['telephone'] = $deal->telephone ?? '';
            $data['alamat'] = $deal->alamat ?? '';
        } else {
            $data['is_manual_company'] = true;
        }

        return $data;
    }
}
