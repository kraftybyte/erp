<?php

namespace App\Filament\Exports;

use App\Models\Invoice;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class InvoiceExporter extends Exporter
{
    protected static ?string $model = Invoice::class;

    public static function getColumns(): array
{
    return [
        ExportColumn::make('date')
            ->label('Tanggal'),

        ExportColumn::make('delivery_note_number')
            ->label('No. SJ'),

        ExportColumn::make('uuid')
            ->label('No. Invoice'),

        ExportColumn::make('tax_invoice_number')
            ->label('Faktur Pajak'),

        ExportColumn::make('customer_name')
            ->label('Customer'),

        ExportColumn::make('item_name')
            ->label('Nama Barang'),

        ExportColumn::make('quantity')
            ->label('Qty'),

        ExportColumn::make('unit')
            ->label('Satuan'),

        ExportColumn::make('unit_price')
            ->label('Harga /qty'),

        ExportColumn::make('dpp')
            ->label('Harga Jual / DPP'),

        ExportColumn::make('vat')
            ->label('PPN'),

        ExportColumn::make('total')
            ->label('Total'),

        ExportColumn::make('due_date')
            ->label('Tgl Jatuh Tempo'),

        ExportColumn::make('paid_at')
            ->label('Tgl Lunas'),
    ];
}

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Export invoice selesai. '
            . number_format($export->successful_rows)
            . ' data berhasil diexport.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '
                . number_format($failedRowsCount)
                . ' data gagal.';
        }

        return $body;
    }
}