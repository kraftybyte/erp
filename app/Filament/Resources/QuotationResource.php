<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QuotationResource\Pages;
use App\Helpers\AmountSearch;
use App\Helpers\ColorHelper;
use App\Models\Quotation;
use App\Models\Product;
use App\Models\Leads;
use App\Models\Deal;
use App\Settings\GeneralSettings;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Actions\Action;
use Filament\Notifications\Notification;
use App\Models\Invoice;
use App\Models\InvoicesItem;
use App\Services\QuotationItemImport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QuotationResource extends Resource
{
    protected static ?string $model = Quotation::class;
    protected static ?string $navigationIcon = 'heroicon-o-arrows-up-down';
    protected static ?int $navigationSort = -1;

    public static function getNavigationGroup(): ?string
    {
        return __("CRM");
    }

    public static function getNavigationLabel(): string
    {
        return trans('Quotation');
    }

    /* ===============================
       UUID GENERATOR
    =============================== */

    /*
    | Generator nomor quotation ada di Quotation::generateUuid() — itu yang
    | dipakai form. Versi yang dulu berdiri di sini tidak pernah dipanggil dan
    | formatnya pun berbeda (#DG/1026/… alih-alih #DG/0132610/…), jadi dihapus
    | agar tidak ada dua sumber penomoran yang saling bertentangan.
    */

    /* ===============================
       FORM
    =============================== */

    public static function form(Form $form): Form
    {
        $updateItemTotals = function (callable $get, callable $set) {
            $price = floatval($get('price') ?? 0);
            $qty = floatval($get('qty') ?? 1);
            $discount = floatval($get('discount') ?? 0);  // Discount TOTAL
            $vatPercentage = floatval($get('vat_percentage') ?? 0);

            $subtotalItem = $price * $qty;
            // Discount adalah TOTAL (bukan per unit)
            $discountedSubtotal = max($subtotalItem - $discount, 0);
            $vat = $discountedSubtotal * ($vatPercentage / 100);
            $total = $discountedSubtotal + $vat;

            $set('vat', round($vat, 2));
            $set('total', round($total, 2));
        };

        return $form->schema([

            Forms\Components\TextInput::make('uuid')
                ->unique(ignoreRecord: true)
                ->readOnly()
                ->default(fn () => Quotation::generateUuid())
                ->required()
                ->columnSpanFull(),

            Forms\Components\Section::make('Company Information')
                ->schema([

                    /*
                    |--------------------------------------------------------------------------
                    | PENGISI OTOMATIS
                    |--------------------------------------------------------------------------
                    |
                    | Dropdown ini hanya alat bantu dan tidak ikut disimpan.
                    |
                    | Sebelumnya dropdown-nya menempel langsung pada kolom nama_perusahaan
                    | dengan nilai gabungan "Perusahaan|PIC", lalu menimpa dirinya sendiri
                    | dengan nama perusahaan saja. Akibatnya nilai tersimpan tidak pernah
                    | cocok dengan daftar opsi, sehingga setiap kali quotation dibuka
                    | kembali field wajib ini tampil kosong dan harus dipilih ulang.
                    |--------------------------------------------------------------------------
                    */

                    Forms\Components\Select::make('_kontak')
                        ->label('Ambil dari Leads / Deal')
                        ->placeholder('Pilih untuk mengisi otomatis (opsional)')
                        ->helperText('Biarkan kosong bila ingin mengisi sendiri.')
                        ->options(function () {
                            $options = [];

                            $tambah = function ($baris) use (&$options) {
                                $perusahaan = $baris->nama_perusahaan;
                                $pic = $baris->nama_pic ?: '-';

                                if (blank($perusahaan)) {
                                    return;
                                }

                                $key = $perusahaan . '|' . $pic;
                                $options[$key] ??= $perusahaan . ' — ' . $pic;
                            };

                            \App\Models\Leads::select('nama_perusahaan', 'nama_pic')
                                ->orderBy('nama_perusahaan')
                                ->limit(500)
                                ->get()
                                ->each($tambah);

                            \App\Models\Deal::select('nama_perusahaan', 'nama_pic')
                                ->orderBy('nama_perusahaan')
                                ->limit(500)
                                ->get()
                                ->each($tambah);

                            ksort($options);

                            return $options;
                        })
                        ->searchable()
                        ->live()
                        ->dehydrated(false)
                        ->afterStateUpdated(function ($state, callable $set) {
                            if (blank($state)) {
                                return;
                            }

                            [$namaPerusahaan, $namaPic] = array_pad(explode('|', $state, 2), 2, '-');

                            $set('nama_perusahaan', $namaPerusahaan);
                            $set('nama_pic', $namaPic === '-' ? null : $namaPic);

                            $kontak = \App\Models\Leads::where('nama_perusahaan', $namaPerusahaan)
                                ->where('nama_pic', $namaPic)
                                ->first()
                                ?? \App\Models\Deal::where('nama_perusahaan', $namaPerusahaan)
                                    ->where('nama_pic', $namaPic)
                                    ->first();

                            $set('telephone', $kontak->telephone ?? null);
                            $set('email', $kontak->email ?? null);
                        })
                        ->columnSpanFull(),

                    /*
                    |--------------------------------------------------------------------------
                    | DATA KLIEN
                    |--------------------------------------------------------------------------
                    |
                    | Field sungguhan: terisi otomatis saat dropdown dipilih, tetap bisa
                    | disunting, dan tampil apa adanya saat quotation dibuka kembali.
                    |--------------------------------------------------------------------------
                    */

                    Forms\Components\TextInput::make('nama_perusahaan')
                        ->label('Nama Klien')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('nama_pic')
                        ->label('Nama PIC')
                        ->maxLength(255),

                    Forms\Components\TextInput::make('telephone')
                        ->label('Telephone')
                        ->tel()
                        ->maxLength(50),

                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->maxLength(255),
                ])
                ->columns(2),

            Forms\Components\Section::make('Date Information')
                ->schema([
                    Forms\Components\DatePicker::make('date')
                        ->default(now())
                        ->required(),

                    Forms\Components\DatePicker::make('due_date')
                        ->default(now()->addDays(14))
                        ->required(),

                    Forms\Components\Select::make('status')
                        ->options([
                            'Pending' => 'Pending',
                            'Approved' => 'Approved',
                            'Rejected' => 'Rejected',
                            'Converted' => 'Converted',
                        ])
                        ->default('Pending')
                        ->native(false),
                ])
                ->columns(3),

            /* ===============================
               REPEATER ITEMS
            =============================== */

            Forms\Components\Section::make('Items')
                ->key('itemsSection')
                ->headerActions([
                    static::importItemsAction(),
                ])
                ->schema([
                    Forms\Components\Repeater::make('items')
                        ->relationship()
                        // Quotation dari file budget bisa berisi puluhan item: bisa dilipat, dengan label nama + total.
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => filled($state['item'] ?? null)
                            ? Str::limit((string) $state['item'], 70) . ' — Rp ' . number_format((float) ($state['total'] ?? 0), 0, ',', '.')
                            : null)
                        ->schema([

                            // The actual item name stored in DB
                            Forms\Components\Hidden::make('item')
                                ->required(),

                            // Toggle untuk manual input
                            Forms\Components\Toggle::make('is_manual')
                                ->label('Manual Input')
                                ->default(false)
                                ->reactive()
                                ->afterStateUpdated(function ($state, callable $set) {
                                    if (!$state) {
                                        // Switching to product mode - clear manual item
                                        $set('manual_text', null);
                                    } else {
                                        // Switching to manual mode - clear product selection
                                        $set('product_id', null);
                                    }
                                }),

                            // Select untuk pilih dari Product
                            Forms\Components\Select::make('product_id')
                                ->label('Select Product')
                                ->options(Product::query()->pluck('nama_layanan', 'id'))
                                ->searchable()
                                ->preload()
                                ->hidden(fn ($get) => $get('is_manual'))
                                ->reactive()
                                ->afterStateUpdated(function ($state, callable $set) {
                                    if ($product = Product::find($state)) {
                                        $set('item', $product->nama_layanan);
                                        $set('description', $product->detail_layanan);
                                        $set('price', $product->harga_layanan);
                                    }
                                })
                                ->columnSpanFull(),

                            // Text input untuk manual item
                            Forms\Components\TextInput::make('manual_text')
                                ->label('Item Name (Manual)')
                                ->hidden(fn ($get) => !$get('is_manual'))
                                ->reactive()
                                ->afterStateUpdated(fn ($state, callable $set) => $set('item', $state))
                                ->columnSpanFull(),

                            // Deskripsi
                            Forms\Components\RichEditor::make('description')
                                ->columnSpanFull(),

                            // Qty
                            Forms\Components\TextInput::make('qty')
                                ->numeric()
                                ->helperText('Wajib dimasukan Quantity produknya.')
                                ->reactive()
                                ->required()
                                ->afterStateUpdated($updateItemTotals),

                            // Discount
                            Forms\Components\TextInput::make('discount')
                                ->numeric()
                                ->default(0)
                                ->reactive()
                                ->afterStateUpdated($updateItemTotals),

                            // VAT Percentage
                            Forms\Components\TextInput::make('vat_percentage')
                                ->numeric()
                                ->reactive()
                                ->helperText('Isi (0) apabila tidak ada pajak.')
                                ->required()
                                ->afterStateUpdated($updateItemTotals),

                            // Price
                            Forms\Components\TextInput::make('price')
                                ->numeric()
                                ->default(0)
                                ->reactive()
                                ->afterStateUpdated($updateItemTotals),

                            // VAT (calculated)
                            Forms\Components\TextInput::make('vat')
                                ->numeric()
                                ->disabled()
                                ->dehydrated(),

                            // Total (calculated)
                            Forms\Components\TextInput::make('total')
                                ->numeric()
                                ->disabled()
                                ->dehydrated(),
                        ])
                        ->columns(2)
                        ->afterStateUpdated(fn ($get, $set) => self::recalculateTotals($get, $set))
                        ->defaultItems(1)
                        ->mutateRelationshipDataBeforeFillUsing(function (array $data): array {
                            // Determine if this item is manual or from product
                            $itemName = $data['item'] ?? null;
                            if ($itemName) {
                                $product = Product::where('nama_layanan', $itemName)->first();
                                if ($product) {
                                    $data['is_manual'] = false;
                                    $data['product_id'] = $product->id;
                                } else {
                                    $data['is_manual'] = true;
                                    $data['manual_text'] = $itemName;
                                }
                            }
                            return $data;
                        })
                        ->mutateRelationshipDataBeforeSaveUsing(function (array $data): array {
                            // Remove helper fields before saving
                            unset($data['is_manual'], $data['product_id'], $data['manual_text']);
                            return $data;
                        }),
                ]),

            Forms\Components\Section::make('Total Quotation')
                ->schema([
                    Forms\Components\TextInput::make('subtotal')->disabled()->dehydrated(),
                    Forms\Components\TextInput::make('total_discount')->disabled()->dehydrated(),
                    Forms\Components\TextInput::make('total_vat')->disabled()->dehydrated(),
                    Forms\Components\TextInput::make('grand_total')->disabled()->dehydrated()->columnSpanFull(),
                ])
                ->columns(3),
        ]);
    }

    /**
     * Isi item dari file Excel/CSV budget (lihat QuotationItemImport). Item
     * hanya dimasukkan ke form; tersimpan setelah tombol Simpan ditekan.
     */
    protected static function importItemsAction(): Forms\Components\Actions\Action
    {
        return Forms\Components\Actions\Action::make('importItems')
            ->label('Import dari Excel')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->modalHeading('Import item dari Excel')
            ->modalDescription('File budget dengan kolom ITEMS, PRICE/QTY, QTY, DUR, dan TOTAL; kolom lain (mis. USD) diabaikan. Judul bagian (mis. FLOORING) menjadi kategori, baris spesifikasi masuk ke deskripsi, qty = QTY × DUR, dan SUB TOTAL / GRAND TOTAL dicocokkan dengan jumlah item.')
            ->modalSubmitActionLabel('Import')
            ->form([
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

                Forms\Components\Toggle::make('group')
                    ->label('Satu item per kategori (mis. FLOORING), rinciannya di deskripsi')
                    ->helperText('Matikan untuk mengimpor setiap baris sebagai item sendiri.')
                    ->default(true),

                Forms\Components\Toggle::make('replace')
                    ->label('Ganti item yang sudah ada di form')
                    ->default(true),
            ])
            ->action(function (array $data, Get $get, Set $set) {
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
                $items = ($data['group'] ?? true) ? $import->grouped($result['items']) : $result['items'];

                $imported = collect($items)->mapWithKeys(function (array $item) use ($vat) {
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
                    ]];
                })->all();

                // Baris kosong bawaan form tidak ikut dipertahankan.
                $existing = $data['replace'] ? [] : array_filter($get('items') ?? [], fn ($item) => filled($item['item'] ?? null));

                $set('items', $existing + $imported);
                self::recalculateTotals($get, $set);

                $rupiah = fn (float $amount) => 'Rp ' . number_format($amount, 0, ',', '.');
                $body   = $result['expected_total'] !== null && ! $result['warnings']
                    ? 'Cocok dengan GRAND TOTAL di file. Periksa lalu tekan Simpan.'
                    : implode("\n", [...$result['warnings'], 'Periksa lalu tekan Simpan.']);

                Notification::make()
                    ->title(count($items) . ' item diimpor · ' . $rupiah($result['total']) . ($vat > 0 ? " + PPN {$vat}%" : ''))
                    ->body($body)
                    ->{$result['warnings'] ? 'warning' : 'success'}()
                    ->persistent()
                    ->send();
            });
    }

    protected static function recalculateTotals(callable $get, callable $set)
    {
        $items = $get('items') ?? [];

        $subtotal = 0;
        $totalDiscount = 0;
        $totalVat = 0;

        foreach ($items as $item) {
            $price = floatval($item['price'] ?? 0);
            $qty = floatval($item['qty'] ?? 1);
            $discount = floatval($item['discount'] ?? 0);  // Discount TOTAL
            $vatPercentage = floatval($item['vat_percentage'] ?? 0);

            $subtotalItem = $price * $qty;
            // Discount adalah TOTAL (bukan per unit)
            $discountedSubtotal = max($subtotalItem - $discount, 0);
            $vat = $discountedSubtotal * ($vatPercentage / 100);

            $subtotal += $subtotalItem;
            $totalDiscount += $discount;  // Total discount
            $totalVat += $vat;
        }

        $grandTotal = ($subtotal - $totalDiscount) + $totalVat;

        $set('subtotal', round($subtotal, 2));
        $set('total_discount', round($totalDiscount, 2));
        $set('total_vat', round($totalVat, 2));
        $set('grand_total', round($grandTotal, 2));
    }

    /* =========================================================
     * TABLE
     * ========================================================= */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn(Builder $query) => $query->with(['items']))
            ->columns([
                Tables\Columns\TextColumn::make('uuid')->label('ID')->searchable(),

                Tables\Columns\TextColumn::make('nama_perusahaan')
                ->formatStateUsing(function ($state, $record) {
                    $namaPic = $record->nama_pic === '-' || empty($record->nama_pic)
                        ? '(Pribadi)'
                        : $record->nama_pic;

                    return $namaPic . ' - ' . $state;
                })
                ->limit(20)
                ->tooltip(function ($record) {
                    $namaPic = $record->nama_pic === '-' || empty($record->nama_pic)
                        ? '(Pribadi)'
                        : $record->nama_pic;

                    return $namaPic . ' - ' . $record->nama_perusahaan;
                })
                ->searchable(
                    query: fn (Builder $query, string $search) => $query
                        ->where('nama_perusahaan', 'like', "%{$search}%")
                        ->orWhere('nama_pic', 'like', "%{$search}%")
                ),


                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn ($record) => ColorHelper::quotationStatus($record->status))
                    ->searchable(),

                Tables\Columns\TextColumn::make('grand_total')
                    ->label('Total Quotation')
                    ->money('IDR')
                    ->searchable(
                        query: fn (Builder $query, string $search) => AmountSearch::apply($query, $search, 'grand_total')
                    ),
            ])

            ->actions([

                /* =========================================================
                 * CONVERT TO PROFORMA INVOICE
                 * ========================================================= */
                Action::make('ConvertToEstimate')
                    ->tooltip('Convert to Proforma Invoice')
                    ->icon('heroicon-m-check')
                    ->iconButton()
                    ->color('primary')
                    ->requiresConfirmation()
                    ->hidden(fn($record) => $record->status === 'Converted')
                    ->action(function (Quotation $record) {

                        /*
                        | Utamakan tautan yang sudah tersimpan di quotation; kecocokan
                        | nama hanya dipakai bila quotation lama belum punya deal_id.
                        | Mencocokkan nama perusahaan saja bisa menarik Deal milik PIC
                        | yang berbeda ketika satu perusahaan punya beberapa Deal.
                        */
                        $deal = $record->deal_id
                            ? Deal::find($record->deal_id)
                            : Deal::where('nama_perusahaan', $record->nama_perusahaan)
                                ->when(
                                    filled($record->nama_pic),
                                    fn ($query) => $query->orderByRaw(
                                        'CASE WHEN nama_pic = ? THEN 0 ELSE 1 END',
                                        [$record->nama_pic]
                                    )
                                )
                                ->first();

                        /* ========= DB TRANSACTION ========= */
                        DB::transaction(function () use ($record, $deal) {

                            /* ========= GENERATE PROFORMA UUID (inside transaction, locked) ========= */
                            /*
                            |--------------------------------------------------------------------------
                            | NOMOR INVOICE
                            |--------------------------------------------------------------------------
                            |
                            | Dulu blok ini menyusun nomornya sendiri dengan format tanggal 'my'
                            | (MMYY), sementara Invoice::generateUuid() memakai 'ym' (YYMM). Untuk
                            | bulan yang sama keduanya menghasilkan pola berbeda — mis. Juli 2026
                            | menjadi 0132607 dan 0130726 — sehingga penomoran terpecah dua seri
                            | dan nomor urut yang sama terpakai dua kali dalam satu periode.
                            |
                            | Sekarang keduanya memakai satu sumber yang sama.
                            |--------------------------------------------------------------------------
                            */
                            $uuid = Invoice::generateUuid('estimate');

                            $invoice = Invoice::create([
                                'uuid'            => $uuid,
                                'for_type'        => 'quotation',
                                'for_id'          => $record->id,
                                'user_id'         => auth()->id(),
                                'deal_id'         => $deal?->id, // Simpan deal_id
                                'nama_perusahaan' => $record->nama_perusahaan,
                                'nama_pic'        => $deal?->nama_pic ?? $record->nama_pic,
                                'telephone'       => $deal?->telephone ?? $record->telephone,
                                'alamat'          => $deal?->alamat
                                    ?? $record->alamat
                                    ?? '-',
                                'type'            => 'estimate',
                                'type_payment'    => 'full_payment',
                                'status'          => 'draft',
                                'subtotal'        => $record->subtotal,
                                'total_discount'  => $record->total_discount,
                                'total_vat'       => $record->total_vat,
                                'grand_total'     => $record->grand_total,
                                'paid'            => 0,
                                'date'            => now(),
                                'due_date'        => $record->due_date,
                                'is_activated'    => 1,
                                'currency_id'     => 66,
                            ]);

                            /* ========= COPY ITEMS ========= */
                            foreach ($record->items as $item) {
                                InvoicesItem::create([
                                    'invoice_id'     => $invoice->id,
                                    'item'           => $item->item,
                                    'description'    => $item->description,
                                    'qty'            => $item->qty,
                                    'price'          => $item->price,
                                    'original_price' => $item->price, // Simpan harga asli untuk referensi
                                    'discount'       => $item->discount,
                                    'vat'            => $item->vat,
                                    'vat_percentage' => $item->vat_percentage,
                                    'total'          => $item->total,
                                ]);
                            }

                            /* ========= UPDATE QUOTATION ========= */
                            $record->update([
                                'status' => 'Converted',
                            ]);
                        });

                        /* ========= NOTIFICATION ========= */
                        Notification::make()
                            ->title('Quotation Converted to Proforma Invoice')
                            ->body('Proforma Invoice successfully created!')
                            ->success()
                            ->send();
                    }),

                /* DOWNLOAD */
                Action::make('Download')
                    ->tooltip('Download PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('danger')
                    ->url(fn(Quotation $record) =>
                        route('quotation.download', $record)
                    )
                    ->openUrlInNewTab(),

                Tables\Actions\ViewAction::make()
                    ->hiddenLabel()
                    ->tooltip('Detail'),

                Tables\Actions\EditAction::make()
                    ->hiddenLabel()
                    ->tooltip('Edit'),

                Tables\Actions\DeleteAction::make()
                    ->hiddenLabel()
                    ->tooltip('Delete'),
            ])

            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'Pending' => 'Pending',
                        'Approved' => 'Approved',
                        'Rejected' => 'Rejected',
                        'Converted' => 'Converted',
                    ]),
            ])

            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }


    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuotations::route('/'),
            'create' => Pages\CreateQuotation::route('/create'),
            'edit' => Pages\EditQuotation::route('/{record}/edit'),
        ];
    }
}