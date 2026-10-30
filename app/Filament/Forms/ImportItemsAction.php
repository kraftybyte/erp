<?php

namespace App\Filament\Forms;

use App\Helpers\Money;
use App\Services\QuotationItemImport;
use Closure;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Support\Enums\MaxWidth;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Tombol "Import dari Excel" di bagian Items form quotation dan invoice: isi
 * repeater item dari file budget (lihat QuotationItemImport). Dua langkah:
 * pilih file (dan sheet-nya), lalu periksa hasilnya. Baris yang harga × qty-nya
 * tidak sama dengan total di file, atau tanpa harga, ditandai; semua baris bisa
 * diubah atau dihapus. Item hanya dimasukkan ke form; tersimpan setelah tombol
 * Simpan ditekan.
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
            ->modalWidth(MaxWidth::SixExtraLarge)
            ->modalSubmitActionLabel('Import')
            ->steps([
                Step::make('File')
                    ->schema([
                        Forms\Components\Placeholder::make('template')
                            ->hiddenLabel()
                            ->content(fn () => new HtmlString(
                                'Template ERP (satu baris satu item), atau file budget/penawaran apa adanya dengan kolom ITEMS / Nama Barang / Jenis Pekerjaan, Harga, QTY, dan Total; '
                                . 'teks di luar tabel dan kolom lain (mis. USD) diabaikan. '
                                . 'Belum punya file? <a href="' . e(route('import-template.items', $template)) . '" target="_blank" class="font-semibold text-primary-600 underline">Unduh template Excel</a>: '
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
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('sheet', null))
                            ->required(),

                        Forms\Components\Select::make('sheet')
                            ->label('Sheet')
                            ->options(fn (Get $get) => array_combine($names = self::sheetNames($get('file')), $names))
                            ->placeholder('Otomatis: sheet yang berisi tabel item')
                            ->visible(fn (Get $get) => count(self::sheetNames($get('file'))) > 1),

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
                    ->afterValidation(function (Get $get, Set $set) {
                        $result = self::read($get('file'), $get('sheet'), $get('layout'));

                        $set('rows', $result['rows']);
                        $set('expected_total', $result['expected_total']);
                        $set('notes', $result['notes']);
                    }),

                Step::make('Periksa')
                    ->schema([
                        Forms\Components\Placeholder::make('summary')
                            ->hiddenLabel()
                            ->content(fn (Get $get) => self::summary($get)),

                        Forms\Components\Hidden::make('expected_total'),
                        Forms\Components\Hidden::make('notes'),

                        Forms\Components\Repeater::make('rows')
                            ->hiddenLabel()
                            ->schema([
                                Forms\Components\TextInput::make('item')
                                    ->label('Item')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpan(4),
                                Forms\Components\Textarea::make('description')
                                    ->label('Deskripsi')
                                    ->rows(2)
                                    ->autosize()
                                    ->columnSpan(5),
                                Forms\Components\TextInput::make('qty')
                                    ->label('Qty')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->columnSpan(1),
                                Forms\Components\TextInput::make('price')
                                    ->label('Harga')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->columnSpan(2),
                                Forms\Components\Placeholder::make('issue')
                                    ->hiddenLabel()
                                    ->content(fn (Get $get) => self::warningText(implode(' ', $get('issues') ?? [])))
                                    ->visible(fn (Get $get) => filled($get('issues')))
                                    ->columnSpanFull(),
                                Forms\Components\Hidden::make('issues'),
                                // Deskripsi asli (HTML) dipakai bila teksnya tidak diubah.
                                Forms\Components\Hidden::make('html'),
                                Forms\Components\Hidden::make('text'),
                            ])
                            ->columns(12)
                            ->defaultItems(0)
                            ->addable(false)
                            ->reorderable(false)
                            ->itemLabel(fn (array $state) => Money::rupiah((float) ($state['qty'] ?? 0) * (float) ($state['price'] ?? 0))
                                . (filled($state['issues'] ?? null) ? ' · perlu dicek' : '')),
                    ]),
            ])
            ->action(function (array $data, Get $get, Set $set) use ($repeater, $recalculate, $extra) {
                // Tanpa langkah Periksa (mis. dipanggil langsung), file dibaca sekarang.
                $rows  = ($data['rows'] ?? []) ?: self::read($data['file'], $data['sheet'] ?? null, $data['layout'] ?? 'lines')['rows'];
                $vat   = (float) $data['vat_percentage'];
                $items = array_map(fn (array $row) => self::item($row), array_values($rows));

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

                $total = array_sum(array_map(fn (array $item) => $item['price'] * $item['qty'], $items));

                Notification::make()
                    ->title(count($items) . ' item diimpor · ' . Money::rupiah($total) . ($vat > 0 ? " + PPN {$vat}%" : ''))
                    ->body('Periksa lalu tekan Simpan.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Baca file menjadi baris yang bisa diperiksa di langkah kedua.
     *
     * @return array{rows: array<string, array>, notes: array<int, string>, expected_total: ?float}
     *
     * @throws Halt  file tidak terbaca atau tanpa item (pengguna diberi tahu)
     */
    private static function read(mixed $file, ?string $sheet, ?string $layout): array
    {
        $import = app(QuotationItemImport::class);

        try {
            $result = $import->parse(self::uploaded($file)->getRealPath(), $sheet ?: null);
        } catch (\Throwable $e) {
            Notification::make()->title('File tidak bisa dibaca')->body($e->getMessage())->danger()->send();

            throw new Halt();
        }

        if (! $result['items']) {
            Notification::make()->title('Tidak ada item yang terbaca di file ini')->body('Coba pilih sheet lain.')->warning()->send();

            throw new Halt();
        }

        $items = $layout === 'category' ? $import->grouped($result['items']) : $import->lines($result['items']);

        $rows = collect($items)->mapWithKeys(fn (array $item) => [(string) Str::uuid() => [
            'item'        => $item['item'],
            'description' => implode("\n", $item['description_lines']),
            'qty'         => $item['qty'],
            'price'       => $item['price'],
            'issues'      => $item['issues'],
            'html'        => $item['description'],
            'text'        => implode("\n", $item['description_lines']),
        ]])->all();

        return ['rows' => $rows, 'notes' => $result['notes'], 'expected_total' => $result['expected_total']];
    }

    /**
     * Baris hasil pemeriksaan menjadi item form.
     *
     * @return array{item: string, description: string, qty: float, price: float}
     */
    private static function item(array $row): array
    {
        $text = trim(str_replace("\r\n", "\n", (string) ($row['description'] ?? '')));

        return [
            'item'        => trim((string) $row['item']),
            'description' => $text === trim((string) ($row['text'] ?? ''))
                ? (string) ($row['html'] ?? '')
                : implode('<br>', array_map('e', array_values(array_filter(array_map('trim', explode("\n", $text)), fn ($line) => $line !== '')))),
            'qty'         => (float) $row['qty'],
            'price'       => (float) $row['price'],
        ];
    }

    private static function summary(Get $get): HtmlString
    {
        $rows     = collect($get('rows') ?? [])->filter(fn ($row) => is_array($row));
        $total    = $rows->sum(fn (array $row) => (float) ($row['qty'] ?? 0) * (float) ($row['price'] ?? 0));
        $expected = $get('expected_total');
        $flagged  = $rows->filter(fn (array $row) => filled($row['issues'] ?? null))->count();

        $lines = ['<strong>' . $rows->count() . ' item · ' . e(Money::rupiah($total)) . '</strong>'
            . match (true) {
                $expected === null                => '',
                abs($expected - $total) <= 1      => ', sama dengan total di file.',
                default                           => ' · ' . self::warningText('Total di file ' . Money::rupiah($expected) . ', selisih ' . Money::signed($total - $expected) . '.'),
            }];

        if ($flagged) {
            $lines[] = self::warningText("{$flagged} baris ditandai \"perlu dicek\"; betulkan harganya atau hapus barisnya.");
        }

        foreach ($get('notes') ?? [] as $note) {
            $lines[] = self::warningText($note);
        }

        $lines[] = 'Nama, deskripsi, qty, dan harga bisa diubah; baris yang tidak dipakai bisa dihapus lewat ikon tempat sampah.';

        return new HtmlString('<div class="space-y-1 text-sm">' . implode('', array_map(fn ($line) => "<p>{$line}</p>", $lines)) . '</div>');
    }

    private static function warningText(string $text): HtmlString
    {
        return new HtmlString('<span class="fi-color-custom text-custom-600 dark:text-custom-400" style="--c-400:var(--warning-400);--c-600:var(--warning-600);">' . e($text) . '</span>');
    }

    /**
     * @return array<int, string>
     */
    private static function sheetNames(mixed $file): array
    {
        try {
            return ($file = self::uploaded($file)) ? app(QuotationItemImport::class)->sheetNames($file->getRealPath()) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private static function uploaded(mixed $state): ?TemporaryUploadedFile
    {
        $file = is_array($state) ? reset($state) : $state;

        return $file instanceof TemporaryUploadedFile ? $file : null;
    }
}
