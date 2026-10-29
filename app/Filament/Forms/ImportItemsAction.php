<?php

namespace App\Filament\Forms;

use App\Services\QuotationItemImport;
use Closure;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Tombol "Import dari Excel" di bagian Items form quotation dan invoice: isi
 * repeater item dari file budget (lihat QuotationItemImport). Item hanya
 * dimasukkan ke form; tersimpan setelah tombol Simpan ditekan.
 */
class ImportItemsAction
{
    /**
     * @param  string  $repeater  state path repeater item, mis. "items" / "invoicesItems"
     * @param  Closure(Get, Set): void  $recalculate  hitung ulang total di form
     * @param  Closure(array): array|null  $extra  kolom tambahan per item, mis. original_price
     * @param  string  $template  template Excel yang ditautkan: quotation | invoice
     */
    public static function make(string $repeater, Closure $recalculate, ?Closure $extra = null, string $template = 'quotation'): Action
    {
        return Action::make('importItems')
            ->label('Import dari Excel')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->modalHeading('Import item dari Excel')
            ->modalDescription('Template ERP (satu baris satu item), atau file budget/penawaran dengan kolom ITEMS / Nama Barang / Jenis Pekerjaan, Harga, QTY, dan Total; kolom lain (mis. USD) diabaikan. Judul bagian menjadi kategori, baris spesifikasi masuk ke deskripsi, dan SUB TOTAL / TOTAL dicocokkan dengan jumlah item.')
            ->modalSubmitActionLabel('Import')
            ->form([
                Forms\Components\Placeholder::make('template')
                    ->hiddenLabel()
                    ->content(fn () => new HtmlString(
                        'Belum punya file? <a href="' . e(route('import-template.items', $template)) . '" target="_blank" class="font-semibold text-primary-600 underline">Unduh template Excel</a>: '
                        . 'kolom Item, Deskripsi, Qty, Satuan, Harga, Total, dengan contoh dan petunjuk pengisian.'
                    )),

                Forms\Components\FileUpload::make('file')
                    ->label('File Excel / CSV')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.ms-excel',
                        'text/csv',
                        'text/plain',
                    ])
                    ->maxSize(10240)
                    ->storeFiles(false)
                    ->required(),

                Forms\Components\TextInput::make('vat_percentage')
                    ->label('PPN (%) untuk semua item')
                    ->numeric()
                    ->default(0)
                    ->required(),

                Forms\Components\Radio::make('layout')
                    ->label('Susunan item')
                    ->options([
                        'lines'    => 'Setiap baris satu item: nama item = kategori (WORK PREPARATION), deskripsi = pekerjaannya',
                        'category' => 'Satu item per kategori: harga = sub total, semua pekerjaannya di deskripsi',
                    ])
                    ->default('lines')
                    ->required(),

                Forms\Components\Toggle::make('replace')
                    ->label('Ganti item yang sudah ada di form')
                    ->default(true),
            ])
            ->action(function (array $data, Get $get, Set $set) use ($repeater, $recalculate, $extra) {
                try {
                    $import = app(QuotationItemImport::class);
                    $result = $import->parse($data['file']->getRealPath());
                } catch (\Throwable $e) {
                    Notification::make()->title('File tidak bisa dibaca')->body($e->getMessage())->danger()->send();

                    return;
                }

                if (! $result['items']) {
                    Notification::make()->title('Tidak ada item yang terbaca di file ini')->warning()->send();

                    return;
                }

                $vat   = (float) $data['vat_percentage'];
                $items = ($data['layout'] ?? 'lines') === 'category' ? $import->grouped($result['items']) : $import->lines($result['items']);

                $imported = collect($items)->mapWithKeys(function (array $item) use ($vat, $extra) {
                    $subtotal = $item['price'] * $item['qty'];

                    return [(string) Str::uuid() => [
                        'is_manual'      => true,
                        'manual_text'    => $item['item'],
                        'product_id'     => null,
                        'item'           => $item['item'],
                        'description'    => $item['description'],
                        'qty'            => $item['qty'],
                        'price'          => $item['price'],
                        'discount'       => 0,
                        'vat_percentage' => $vat,
                        'vat'            => round($subtotal * $vat / 100, 2),
                        'total'          => round($subtotal * (1 + $vat / 100), 2),
                    ] + ($extra ? $extra($item) : [])];
                })->all();

                // Baris kosong bawaan form tidak ikut dipertahankan.
                $existing = $data['replace'] ? [] : array_filter($get($repeater) ?? [], fn ($item) => filled($item['item'] ?? null));

                $set($repeater, $existing + $imported);
                $recalculate($get, $set);

                $rupiah = fn (float $amount) => 'Rp ' . number_format($amount, 0, ',', '.');
                $body   = $result['expected_total'] !== null && ! $result['warnings']
                    ? 'Cocok dengan total di file. Periksa lalu tekan Simpan.'
                    : implode("\n", [...$result['warnings'], 'Periksa lalu tekan Simpan.']);

                Notification::make()
                    ->title(count($items) . ' item diimpor · ' . $rupiah($result['total']) . ($vat > 0 ? " + PPN {$vat}%" : ''))
                    ->body($body)
                    ->{$result['warnings'] ? 'warning' : 'success'}()
                    ->persistent()
                    ->send();
            });
    }
}
