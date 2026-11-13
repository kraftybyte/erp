<?php

namespace App\Filament\Resources;

use App\Exceptions\InvoicePaymentException;
use App\Filament\Actions\ProjectFromInvoiceAction;
use App\Filament\Forms\ImportItemsAction;
use App\Filament\Forms\PaymentTypeFields;
use App\Helpers\AmountSearch;
use App\Models\Invoice;
use App\Models\Deal;
use App\Models\Product;
use App\Models\PaymentAccount;
use App\Filament\Resources\InvoiceResource\Pages;
use App\Filament\Exports\InvoiceExporter;
use App\Services\InvoicePayment;

use Filament\Tables;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\ExportBulkAction;

use Illuminate\Support\Carbon;

use TomatoPHP\FilamentInvoices\Filament\Resources\InvoiceResource as BaseInvoiceResource;
use TomatoPHP\FilamentTypes\Components\TypeColumn;

use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Tables\Table;
use Filament\Tables\Enums\ActionsPosition;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

use Illuminate\Support\Facades\Request;

class InvoiceResource extends BaseInvoiceResource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 5;

    public static function getNavigationLabel(): string
    {
        return \App\Helpers\NavigationHelper::getLabel(
            'invoice',
            'Invoice'
        );
    }

    /**
     * Invoice DP milik client yang sama, untuk dipilih sebagai dasar pelunasan.
     *
     * @return array<int, string>
     */
    public static function dpInvoiceOptions(mixed $dealId, ?string $company, ?int $exceptId = null): array
    {
        return Invoice::query()
            ->where('type', 'sale')
            ->where('type_payment', 'like', 'dp\\_%')
            ->whereNull('deleted_at')
            ->when($exceptId, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->where(fn (Builder $query) => $query
                ->when($dealId, fn (Builder $q) => $q->where('deal_id', $dealId))
                ->when(filled($company), fn (Builder $q) => $q->orWhere('nama_perusahaan', $company))
                ->when(! $dealId && blank($company), fn (Builder $q) => $q->whereRaw('1 = 0')))
            ->orderByDesc('date')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Invoice $invoice) => [
                $invoice->id => $invoice->uuid . ' · DP ' . $invoice->dpPercentage() . '% · '
                    . \App\Helpers\Money::rupiah($invoice->amountDue()) . ' dari ' . \App\Helpers\Money::rupiah($invoice->grand_total),
            ])
            ->all();
    }

    public static function getPages(): array
    {
        return array_merge(parent::getPages(), [
            'index' => \App\Filament\Resources\InvoiceResource\Pages\ListInvoices::route('/'),
            'create' => \App\Filament\Resources\InvoiceResource\Pages\CreateInvoice::route('/create'),
            'edit' => \App\Filament\Resources\InvoiceResource\Pages\EditInvoice::route('/{record}/edit'),
            'view' => \App\Filament\Resources\InvoiceResource\Pages\ViewInvoice::route('/{record}/show'),
        ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    /* =========================================================
       FORM
    ========================================================= */

    public static function form(Form $form): Form
    {
        /*
        |--------------------------------------------------------------------------
        | UPDATE ITEM TOTAL
        |--------------------------------------------------------------------------
        */

        $updateItemTotals = function (
            callable $get,
            callable $set
        ) {

            $price = (float) ($get('price') ?? 0);

            $qty = (float) ($get('qty') ?? 1);

            $discount = (float) ($get('discount') ?? 0);

            $vatPercentage = (float) (
                $get('vat_percentage') ?? 0
            );

            $subtotalItem =
                $price * $qty;

            // Discount adalah TOTAL (bukan per unit)
            $discountedSubtotal =
                max(
                    $subtotalItem - $discount,
                    0
                );

            $vat =
                $discountedSubtotal *
                ($vatPercentage / 100);

            $total =
                $discountedSubtotal + $vat;

            $set(
                'vat',
                round($vat, 2)
            );

            $set(
                'total',
                round($total, 2)
            );
        };

        return $form->schema([

            /* =====================================================
               COMPANY INFORMATION
            ===================================================== */

            Forms\Components\Section::make(
                'Company Information'
            )
                ->schema([

                    /*
                    |--------------------------------------------------------------------------
                    | PENGISI OTOMATIS DARI DEAL
                    |--------------------------------------------------------------------------
                    |
                    | Hanya alat bantu: memilih Deal akan mengisi field-field di bawah, yang
                    | tetap bisa disunting. Tidak wajib — invoice hasil convert quotation
                    | sering tidak punya Deal yang cocok, dan dulu itu membuat form memaksa
                    | pengguna memilih Deal sebelum bisa menyimpan.
                    |--------------------------------------------------------------------------
                    */

                    Forms\Components\Select::make(
                        '_deal_id'
                    )
                        ->label('Ambil dari Deal')
                        ->placeholder('Pilih untuk mengisi otomatis (opsional)')
                        ->helperText('Biarkan kosong bila ingin mengisi sendiri.')
                        ->options(function () {
                            // Hanya kolom yang dipakai, dan dibatasi — Deal::all() memuat
                            // seluruh tabel ke memory setiap form dibuka.
                            return Deal::query()
                                ->select('id', 'nama_pic', 'nama_perusahaan')
                                ->orderBy('nama_perusahaan')
                                ->limit(500)
                                ->get()
                                ->mapWithKeys(fn ($deal) => [
                                    $deal->id => (blank($deal->nama_pic) ? 'Pribadi' : $deal->nama_pic)
                                        . ' (di ' . (blank($deal->nama_perusahaan) ? '-' : $deal->nama_perusahaan) . ')',
                                ]);
                        })
                        ->searchable()
                        ->live()
                        // Harus ikut terkirim: Create/EditInvoice mengubahnya menjadi deal_id.
                        // Dengan dehydrated(false) tautan ke client terhapus setiap simpan.
                        ->afterStateUpdated(
                            function ($state, callable $set) {
                                $deal = Deal::find($state);

                                if ($deal) {
                                    $set('nama_perusahaan', $deal->nama_perusahaan);
                                    $set('nama_pic', $deal->nama_pic);
                                    $set('telephone', $deal->telephone ?? '');
                                    $set('alamat', $deal->alamat ?? '');
                                }
                            }
                        )
                        ->columnSpanFull(),

                    /*
                    |--------------------------------------------------------------------------
                    | DATA PELANGGAN
                    |--------------------------------------------------------------------------
                    |
                    | Field-field ini sebelumnya memakai dehydrated(false), sehingga apa pun
                    | yang diketik pengguna dibuang sebelum sampai ke database — nama invoice
                    | tidak pernah berubah betapa pun seringnya diisi ulang. Keduanya juga
                    | disembunyikan kecuali toggle "Input Manual" menyala, sehingga nama yang
                    | ikut terbawa dari quotation tidak terlihat sama sekali.
                    |--------------------------------------------------------------------------
                    */

                    Forms\Components\TextInput::make(
                        'nama_perusahaan'
                    )
                        ->label('Nama Perusahaan')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make(
                        'nama_pic'
                    )
                        ->label('Nama PIC')
                        ->maxLength(100),

                    Forms\Components\TextInput::make(
                        'telephone'
                    )
                        ->label('Telephone')
                        ->tel()
                        ->maxLength(20),

                    Forms\Components\Textarea::make(
                        'alamat'
                    )
                        ->label('Alamat')
                        ->rows(2)
                        ->columnSpanFull(),

                ])
                ->columns([
                    'default' => 1,
                    'md' => 2,
                    'xl' => 3,
                ])
                ->columnSpanFull(),

            /* =====================================================
               DATA INVOICE
            ===================================================== */

            Forms\Components\Section::make(
                'Data Invoice'
            )
                ->schema([

                    // Hanya pratinjau: nomor final dibuat saat simpan (CreateInvoice), supaya dua
                    // orang yang membuka form bersamaan tidak mendapat nomor yang sama.
                    Forms\Components\TextInput::make(
                        'uuid'
                    )
                        ->dehydrated(false)
                        ->label('UUID')
                        ->default(
                            fn () =>
                                Invoice::generateUuid(
                                    Request::input(
                                        'type',
                                        'sale'
                                    )
                                )
                        )
                        ->readOnly()
                        ->reactive()
                        ->afterStateUpdated(
                            fn (
                                Forms\Set $set,
                                $state
                            ) =>
                                $set(
                                    'uuid',
                                    Invoice::generateUuid(
                                        $state
                                    )
                                )
                        ),

                    Forms\Components\DatePicker::make(
                        'date'
                    )
                        ->label('Tanggal Invoice')
                        ->required()
                        ->default(now()),

                    Forms\Components\DatePicker::make(
                        'due_date'
                    )
                        ->label('Jatuh Tempo')
                        ->required()
                        ->default(
                            now()->addDays(30)
                        ),

                    Forms\Components\Select::make(
                        'type'
                    )
                        ->label('Tipe Invoice')
                        ->required()
                        ->default(fn () => Request::input('type') === 'estimate' ? 'estimate' : 'sale')
                        ->searchable()
                        ->live()
                        // Nomor mengikuti tipe saat membuat: Proforma #PI, Invoice #SC.
                        // Dulu Proforma yang dibuat dari sini tetap bernomor #SC.
                        ->afterStateUpdated(fn (Forms\Set $set, $state, ?Invoice $record) => $record ? null : $set('uuid', Invoice::generateUuid((string) $state)))
                        ->options([

                            'sale' =>
                                'Invoice',

                            'estimate' =>
                                'Proforma Invoice',

                        ]),

                    Forms\Components\Select::make(
                        'status'
                    )
                        ->label('Status')
                        ->required()
                        ->default('draft')
                        ->searchable()
                        ->options([

                            'draft' =>
                                'Draft',

                            'pending' =>
                                'Pending',

                            'paid' =>
                                'Paid',

                            'partial' =>
                                'Partial',

                            'cancelled' =>
                                'Cancelled',

                        ]),

                    /* =================================================
                       PAYMENT TYPE
                    ================================================= */

                    ...PaymentTypeFields::make(),

                    // Pelunasan menagih harga penuh dikurangi DP dari invoice ini
                    // (Invoice::amountDue(), PDF, tombol Bayar).
                    Forms\Components\Select::make('parent_invoice_id')
                        ->label('Invoice DP')
                        ->visible(fn (Get $get) => $get('type_payment') === 'final_payment')
                        ->options(fn (Get $get, ?Invoice $record) => self::dpInvoiceOptions($get('_deal_id'), $get('nama_perusahaan'), $record?->id))
                        ->searchable()
                        ->placeholder('Pilih invoice DP-nya')
                        ->helperText('Sisa tagihan = harga penuh dikurangi DP dari invoice ini.'),

                    Forms\Components\TextInput::make(
                        'po_number'
                    )
                        ->label('Nomor PO'),

                    Forms\Components\TextInput::make(
                        'faktur'
                    )
                        ->label('Nomor Faktur'),

                    Forms\Components\TextInput::make(
                        'delivery_note_number'
                    )
                        ->label(
                            'No. Surat Jalan'
                        ),

                ])
                ->columns(3)
                ->columnSpanFull(),

            /* =====================================================
               ITEMS
            ===================================================== */

            Forms\Components\Section::make(
                'Items'
            )
                ->key('itemsSection')
                ->headerActions([
                    // Harga penuh: invoice DP menyimpan nilai penuh pekerjaan; DP-nya
                    // dihitung dari Total × persentase (Invoice::amountDue()).
                    ImportItemsAction::make(
                        'invoicesItems',
                        fn (Get $get, Set $set) => self::recalculateTotals($get, $set),
                        fn (array $item) => ['original_price' => $item['price']],
                        template: 'invoice',
                    ),
                ])
                ->schema([

                    Forms\Components\Repeater::make(
                        'invoicesItems'
                    )
                        ->relationship()
                        // Invoice dari file budget bisa berisi puluhan item: bisa dilipat, dengan label nama + total.
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => filled($state['item'] ?? null)
                            ? Str::limit((string) $state['item'], 70) . ' — Rp ' . number_format((float) ($state['total'] ?? 0), 0, ',', '.')
                            : null)
                        ->schema([

                            Forms\Components\Hidden::make(
                                'item'
                            )
                                ->required(),

                            /*
                            |--------------------------------------------------------------------------
                            | ORIGINAL PRICE
                            |--------------------------------------------------------------------------
                            */

                            Forms\Components\Hidden::make(
                                'original_price'
                            ),

                            /*
                            |--------------------------------------------------------------------------
                            | MANUAL
                            |--------------------------------------------------------------------------
                            */

                            Forms\Components\Toggle::make(
                                'is_manual'
                            )
                                ->label(
                                    'Manual Input'
                                )
                                ->default(false)
                                ->reactive()
                                ->afterStateUpdated(
                                    function (
                                        $state,
                                        callable $set
                                    ) {

                                        if (! $state) {

                                            $set(
                                                'manual_text',
                                                null
                                            );

                                        } else {

                                            $set(
                                                'product_id',
                                                null
                                            );
                                        }
                                    }
                                ),

                            /*
                            |--------------------------------------------------------------------------
                            | PRODUCT
                            |--------------------------------------------------------------------------
                            */

                            Forms\Components\Select::make(
                                'product_id'
                            )
                                ->label(
                                    'Select Product'
                                )
                                ->options(
                                    Product::query()
                                        ->pluck(
                                            'nama_layanan',
                                            'id'
                                        )
                                )
                                ->searchable()
                                ->preload()
                                ->hidden(
                                    fn ($get) =>
                                        $get(
                                            'is_manual'
                                        )
                                )
                                ->reactive()
                                ->afterStateUpdated(
                                    function (
                                        $state,
                                        callable $set,
                                        callable $get
                                    ) use ($updateItemTotals) {

                                        if (
                                            $product =
                                                Product::find(
                                                    $state
                                                )
                                        ) {

                                            $originalPrice =
                                                (float)
                                                $product
                                                    ->harga_layanan;

                                            $set(
                                                'item',
                                                $product
                                                    ->nama_layanan
                                            );

                                            $set(
                                                'description',
                                                $product
                                                    ->detail_layanan
                                            );

                                            $set(
                                                'original_price',
                                                $originalPrice
                                            );

                                            /*
                                            |--------------------------------------------------------------------------
                                            | HARGA PENUH, JUGA UNTUK INVOICE DP
                                            |--------------------------------------------------------------------------
                                            |
                                            | Invoice DP menyimpan nilai penuh pekerjaan; uang mukanya dihitung
                                            | dari Total × persentase (Invoice::amountDue(), PDF, tombol Bayar).
                                            | Dulu harga item di sini sudah dipotong ke persentase DP, sehingga
                                            | DP terpotong dua kali: DP 50% atas Rp 5 jt tertagih Rp 1,25 jt.
                                            |--------------------------------------------------------------------------
                                            */

                                            $set(
                                                'price',
                                                $originalPrice
                                            );

                                            // Total item ikut dihitung ulang (dulu tertinggal nilai lama).
                                            $updateItemTotals(
                                                $get,
                                                $set
                                            );
                                        }
                                    }
                                )
                                ->columnSpanFull(),

                            /*
                            |--------------------------------------------------------------------------
                            | MANUAL ITEM
                            |--------------------------------------------------------------------------
                            */

                            Forms\Components\TextInput::make(
                                'manual_text'
                            )
                                ->label(
                                    'Item Name (Manual)'
                                )
                                ->hidden(
                                    fn ($get) =>
                                        ! $get(
                                            'is_manual'
                                        )
                                )
                                ->reactive()
                                ->afterStateUpdated(
                                    fn (
                                        $state,
                                        callable $set
                                    ) =>
                                        $set(
                                            'item',
                                            $state
                                        )
                                )
                                ->columnSpanFull(),

                            /*
                            |--------------------------------------------------------------------------
                            | DESCRIPTION
                            |--------------------------------------------------------------------------
                            */

                            Forms\Components\RichEditor::make(
                                'description'
                            )
                                ->label(
                                    'Description'
                                )
                                ->columnSpanFull(),

                            /*
                            |--------------------------------------------------------------------------
                            | QTY
                            |--------------------------------------------------------------------------
                            */

                            Forms\Components\TextInput::make(
                                'qty'
                            )
                                ->label('Qty')
                                ->numeric()
                                ->helperText(
                                    'Isi Kuantiti'
                                )
                                ->required()
                                ->reactive()
                                ->afterStateUpdated(
                                    $updateItemTotals
                                ),

                            /*
                            |--------------------------------------------------------------------------
                            | PRICE
                            |--------------------------------------------------------------------------
                            */

                            Forms\Components\TextInput::make(
                                'price'
                            )
                                ->label('Price')
                                ->numeric()
                                ->prefix('Rp ')
                                ->default(0)
                                ->reactive()
                                ->afterStateUpdated(
                                    $updateItemTotals
                                ),

                            /*
                            |--------------------------------------------------------------------------
                            | DISCOUNT
                            |--------------------------------------------------------------------------
                            */

                            Forms\Components\TextInput::make(
                                'discount'
                            )
                                ->label(
                                    'Discount'
                                )
                                ->numeric()
                                ->prefix('Rp ')
                                ->default(0)
                                ->reactive()
                                ->afterStateUpdated(
                                    $updateItemTotals
                                ),

                            /*
                            |--------------------------------------------------------------------------
                            | VAT %
                            |--------------------------------------------------------------------------
                            */

                            Forms\Components\TextInput::make(
                                'vat_percentage'
                            )
                                ->label(
                                    'VAT %'
                                )
                                ->numeric()
                                ->helperText(
                                    'Isi (0) apabila tidak ada pajak.'
                                )
                                ->required()
                                ->reactive()
                                ->afterStateUpdated(
                                    $updateItemTotals
                                ),

                            /*
                            |--------------------------------------------------------------------------
                            | VAT
                            |--------------------------------------------------------------------------
                            */

                            Forms\Components\TextInput::make(
                                'vat'
                            )
                                ->label('VAT')
                                ->numeric()
                                ->prefix('Rp ')
                                ->disabled()
                                ->dehydrated(),

                            /*
                            |--------------------------------------------------------------------------
                            | TOTAL ITEM
                            |--------------------------------------------------------------------------
                            */

                            Forms\Components\TextInput::make(
                                'total'
                            )
                                ->label('Total')
                                ->numeric()
                                ->prefix('Rp ')
                                ->disabled()
                                ->dehydrated(),

                        ])
                        ->columns(2)
                        ->afterStateUpdated(
                            fn (
                                $get,
                                $set
                            ) =>
                                self::recalculateTotals(
                                    $get,
                                    $set
                                )
                        )
                        ->defaultItems(1)

                        /*
                        |--------------------------------------------------------------------------
                        | LOAD EXISTING ITEM
                        |--------------------------------------------------------------------------
                        */

                        ->mutateRelationshipDataBeforeFillUsing(
                            function (
                                array $data
                            ): array {

                                $itemName =
                                    $data['item']
                                    ?? null;

                                if ($itemName) {

                                    $product =
                                        Product::where(
                                            'nama_layanan',
                                            $itemName
                                        )->first();

                                    if ($product) {

                                        /*
                                        |--------------------------------------------------------------------------
                                        | PRODUCT ITEM
                                        |--------------------------------------------------------------------------
                                        */

                                        $data[
                                            'is_manual'
                                        ] = false;

                                        $data[
                                            'product_id'
                                        ] =
                                            $product->id;

                                        /*
                                        |--------------------------------------------------------------------------
                                        | HARGA ASLI PRODUCT
                                        |--------------------------------------------------------------------------
                                        |
                                        | ONLY set from product if original_price
                                        | is not already stored (from previous DP edit)
                                        |--------------------------------------------------------------------------
                                        */

                                        if (
                                            !isset($data['original_price'])
                                            ||
                                            $data['original_price'] === null
                                        ) {
                                            $data[
                                                'original_price'
                                            ] =
                                                (float)
                                                $product
                                                    ->harga_layanan;
                                        }

                                    } else {

                                        /*
                                        |--------------------------------------------------------------------------
                                        | MANUAL ITEM
                                        |--------------------------------------------------------------------------
                                        */

                                        $data[
                                            'is_manual'
                                        ] = true;

                                        $data[
                                            'manual_text'
                                        ] =
                                            $itemName;

                                        /*
                                        |--------------------------------------------------------------------------
                                        | MANUAL PRICE
                                        |--------------------------------------------------------------------------
                                        |
                                        | ONLY set from current price if original_price
                                        | is not already stored (from previous DP edit)
                                        |--------------------------------------------------------------------------
                                        */

                                        if (
                                            !isset($data['original_price'])
                                            ||
                                            $data['original_price'] === null
                                        ) {
                                            $data[
                                                'original_price'
                                            ] =
                                                (float)
                                                (
                                                    $data[
                                                        'price'
                                                    ] ?? 0
                                                );
                                        }
                                    }
                                }

                                return $data;
                            }
                        )

                        /*
                        |--------------------------------------------------------------------------
                        | SAVE
                        |--------------------------------------------------------------------------
                        */

                        ->mutateRelationshipDataBeforeSaveUsing(
                            function (
                                array $data
                            ): array {

                                unset(
                                    $data[
                                        'is_manual'
                                    ],
                                    $data[
                                        'product_id'
                                    ],
                                    $data[
                                        'manual_text'
                                    ]
                                );

                                return $data;
                            }
                        ),

                ]),

            /* =====================================================
               TOTAL INVOICE
            ===================================================== */

            Forms\Components\Section::make(
                'Total Invoice'
            )
                ->schema([

                    Forms\Components\Grid::make(4)
                        ->schema([

                            Forms\Components\TextInput::make(
                                'subtotal'
                            )
                                ->label(
                                    'Subtotal'
                                )
                                ->numeric()
                                ->prefix('Rp ')
                                ->disabled()
                                ->dehydrated(),

                            Forms\Components\TextInput::make(
                                'total_discount'
                            )
                                ->label(
                                    'Discount'
                                )
                                ->numeric()
                                ->prefix('Rp ')
                                ->disabled()
                                ->dehydrated(),

                            Forms\Components\TextInput::make(
                                'total_vat'
                            )
                                ->label(
                                    'VAT/PPN'
                                )
                                ->numeric()
                                ->prefix('Rp ')
                                ->disabled()
                                ->dehydrated(),

                            Forms\Components\TextInput::make(
                                'grand_total'
                            )
                                ->label(
                                    'Grand Total'
                                )
                                ->numeric()
                                ->prefix('Rp ')
                                ->disabled()
                                ->dehydrated(),

                        ]),

                ]),

            /* =====================================================
               NOTES
            ===================================================== */

            Forms\Components\Section::make(
                'Catatan'
            )
                ->schema([

                    Forms\Components\Textarea::make(
                        'notes'
                    )
                        ->label('Notes')
                        ->rows(3)
                        ->placeholder('Contoh: DP: 50% - Rp 20.000.000')
                        ->hint('Akan terisi otomatis saat DP dibayar'),

                ]),

        ]);
    }

    /* =========================================================
       RECALCULATE TOTALS
    ========================================================= */

    protected static function recalculateTotals(
        callable $get,
        callable $set
    ) {

        $items =
            $get(
                'invoicesItems'
            ) ?? [];

        $subtotal = 0;

        $totalDiscount = 0;

        $totalVat = 0;

        foreach (
            $items
            as $item
        ) {

            $price =
                (float)
                (
                    $item['price']
                    ?? 0
                );

            $qty =
                (float)
                (
                    $item['qty']
                    ?? 1
                );

            $discount =
                (float)
                (
                    $item['discount']
                    ?? 0
                );

            $vatPercentage =
                (float)
                (
                    $item[
                        'vat_percentage'
                    ]
                    ?? 0
                );

            $subtotalItem =
                $price * $qty;

            // Discount adalah TOTAL (bukan per unit)
            $discountedSubtotal =
                max(
                    $subtotalItem -
                    $discount,
                    0
                );

            $vat =
                $discountedSubtotal *
                (
                    $vatPercentage /
                    100
                );

            $subtotal +=
                $subtotalItem;

            // Diskon melebihi baris dipotong sampai 0, sama dengan PDF.
            $totalDiscount +=
                $subtotalItem -
                $discountedSubtotal;

            $totalVat +=
                $vat;
        }

        $grandTotal =
            (
                $subtotal -
                $totalDiscount
            )
            +
            $totalVat;

        $set(
            'subtotal',
            round(
                $subtotal,
                2
            )
        );

        $set(
            'total_discount',
            round(
                $totalDiscount,
                2
            )
        );

        $set(
            'total_vat',
            round(
                $totalVat,
                2
            )
        );

        $set(
            'grand_total',
            round(
                $grandTotal,
                2
            )
        );
    }

    /* =========================================================
       TABLE
    ========================================================= */

    /**
     * Cache pencarian Deal selama satu request.
     *
     * @var array<string, \App\Models\Deal|null>
     */
    protected static array $cacheDeal = [];

    /**
     * Pencarian Deal cadangan untuk invoice yang tidak menyimpan deal_id.
     *
     * Relasi invoice↔deal masih bersandar pada kecocokan nama, jadi hasilnya
     * disimpan per-request agar perusahaan yang sama tidak dicari berulang
     * untuk tiap baris tabel.
     */
    protected static function cariDealCadangan(?string $namaPerusahaan, ?string $namaPic): ?Deal
    {
        $kunci = $namaPerusahaan . '|' . $namaPic;

        if (array_key_exists($kunci, static::$cacheDeal)) {
            return static::$cacheDeal[$kunci];
        }

        $deal = Deal::query()
            ->where('nama_perusahaan', $namaPerusahaan)
            ->when(filled($namaPic), fn ($query) => $query->orderByRaw(
                'CASE WHEN nama_pic = ? THEN 0 ELSE 1 END',
                [$namaPic]
            ))
            ->latest('id')
            ->first();

        return static::$cacheDeal[$kunci] = $deal;
    }

    public static function getEloquentQuery(): Builder
    {
        // Kolom "Customer / PIC" membaca $record->deal dan tombol Project membaca
        // $record->projects; tanpa eager load di sini tiap baris memicu query sendiri.
        return parent::getEloquentQuery()->with(['deal', 'parentInvoice', 'projects:id,invoice_id']);
    }

    public static function table(
        Table $table
    ): Table {

        return $table

            ->columns([

                /*
                |--------------------------------------------------------------------------
                | UUID
                |--------------------------------------------------------------------------
                */

                Tables\Columns\TextColumn::make(
                    'uuid'
                )
                    ->label(
                        trans(
                            'filament-invoices::messages.invoices.columns.uuid'
                        )
                    )
                    // Ringkas ("Proforma · Super Admin") supaya kolom tidak melebar.
                    ->description(
                        fn ($record) => ($record->type === 'sale' ? 'Invoice' : 'Proforma')
                            . ' · ' . ($record->user?->name ?? '-')
                    )
                    ->sortable()
                    ->searchable()
                    ->toggleable(),

                /*
                |--------------------------------------------------------------------------
                | STATUS
                |--------------------------------------------------------------------------
                */

                TypeColumn::make(
                    'status'
                )
                    ->label(
                        trans(
                            'filament-invoices::messages.invoices.columns.status'
                        )
                    )
                    ->sortable()
                    ->searchable(),

                /*
                |--------------------------------------------------------------------------
                | INVOICE TYPE
                |--------------------------------------------------------------------------
                */

                Tables\Columns\BadgeColumn::make(
                    'invoice_type'
                )
                    ->label(
                        'Invoice Type'
                    )
                    ->getStateUsing(
                        fn ($record) =>
                            $record->type ===
                            'sale'
                                ? 'Invoice'
                                : 'Proforma Invoice'
                    )
                    ->colors([
                        'success' =>
                            'sale',

                        'warning' =>
                            'estimate',
                    ])
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                /*
                |--------------------------------------------------------------------------
                | CUSTOMER / PIC
                |--------------------------------------------------------------------------
                */

                Tables\Columns\TextColumn::make(
                    'customer_display'
                )
                    ->label(
                        'Customer / PIC'
                    )
                    ->state(
                        function ($record) {

                            /*
                            |--------------------------------------------------------------------------
                            | CARI DEAL
                            |--------------------------------------------------------------------------
                            |
                            | Sebelumnya blok ini menjalankan sampai 3 query untuk SETIAP baris
                            | tabel (50 baris ≈ 150 query). Sekarang:
                            |   1. deal_id dilayani relasi yang sudah di-eager load (0 query)
                            |   2. pencarian cadangan disimpan di cache per-request, sehingga
                            |      perusahaan yang muncul berkali-kali hanya dicari sekali
                            |--------------------------------------------------------------------------
                            */

                            $deal = $record->deal_id ? $record->deal : null;

                            if (! $deal && filled($record->nama_perusahaan)) {
                                $deal = static::cariDealCadangan(
                                    $record->nama_perusahaan,
                                    $record->nama_pic
                                );
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | PIC
                            |--------------------------------------------------------------------------
                            */

                            $namaPic =
                                $deal?->nama_pic
                                ?? $record->nama_pic
                                ?? null;

                            if (
                                $namaPic === '-'
                                ||
                                blank($namaPic)
                            ) {

                                $namaPic =
                                    'Pribadi';
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | COMPANY
                            |--------------------------------------------------------------------------
                            */

                            $namaPerusahaan =
                                $record->nama_perusahaan;

                            if (
                                $namaPerusahaan === '-'
                                ||
                                blank(
                                    $namaPerusahaan
                                )
                            ) {

                                $namaPerusahaan =
                                    'Pribadi';
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | HASIL
                            |--------------------------------------------------------------------------
                            */

                            return
                                $namaPic
                                . ' - '
                                . $namaPerusahaan;
                        }
                    )
                    ->searchable(
                        query: function (
                            Builder $query,
                            string $search
                        ) {

                            $query->where(
                                'nama_perusahaan',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'nama_pic',
                                'like',
                                "%{$search}%"
                            );
                        }
                    )
                    ->sortable()
                    ->toggleable(),

                /*
                |--------------------------------------------------------------------------
                | PAYMENT TYPE + ORIGINAL PRICE
                |--------------------------------------------------------------------------
                */

                    Tables\Columns\TextColumn::make('type_payment')
                        ->label('Payment')
                        ->formatStateUsing(function ($state, $record) {

                            if ($state === 'full_payment') {
                                return 'Full Payment';
                            }

                            if ($state === 'final_payment') {
                                // Yang ditagih pelunasan: harga penuh − DP (Invoice::amountDue()).
                                return 'Pelunasan • Rp ' . number_format($record->amountDue(), 0, ',', '.');
                            }

                            if ($state && str_starts_with($state, 'dp_')) {

                                $percentage = (int) str_replace(
                                    'dp_',
                                    '',
                                    $state
                                );

                                if ($percentage > 0) {

                                    // Nominal yang ditagihkan lewat invoice DP ini —
                                    // satu sumber dengan PDF dan modal pembayaran.
                                    return 'DP ' . $percentage . '% • Rp ' .
                                        number_format(
                                            $record->amountDue(),
                                            0,
                                            ',',
                                            '.'
                                        );
                                }
                            }

                            return '-';
                        })
                        ->badge()
                        ->color(function ($state) {

                            if ($state === 'full_payment') {
                                return 'success';
                            }

                            if ($state === 'final_payment') {
                                return 'warning';
                            }

                            if ($state && str_starts_with($state, 'dp_')) {
                                return 'info';
                            }

                            return 'gray';
                        })
                        ->searchable(
                            query: function (Builder $query, string $search) {

                                $angka = AmountSearch::digits($search);

                                // "dp", "full", "final"
                                if ($angka === null) {
                                    $query->where('type_payment', 'like', "%{$search}%");

                                    return;
                                }

                                // Nominal DP = grand_total × persen, sama dengan Invoice::amountDue().
                                $query->where('type_payment', 'like', 'dp%')
                                    ->whereRaw(
                                        'CAST(ROUND(grand_total * CAST(SUBSTRING(type_payment, 4) AS SIGNED) / 100) AS CHAR) LIKE ?',
                                        ["%{$angka}%"]
                                    );
                            }
                        )
                        ->sortable()
                        ->toggleable(),



                        /*
                |--------------------------------------------------------------------------
                | GRAND TOTAL
                |--------------------------------------------------------------------------
                */

                Tables\Columns\TextColumn::make(
                    'grand_total'
                )
                    ->label(
                        trans(
                            'filament-invoices::messages.invoices.columns.total'
                        )
                    )
                    ->formatStateUsing(fn ($state, $record) => \App\Helpers\Money::inCurrency($state, $record->currency?->iso))
                    // Invoice DP dan pelunasan: kolom Total berisi harga penuh pekerjaan;
                    // yang ditagih invoice ini ditulis di bawahnya.
                    ->description(fn (Invoice $record) => abs($record->amountDue() - (float) $record->grand_total) > 1
                        ? 'Ditagih ' . \App\Helpers\Money::inCurrency($record->amountDue(), $record->currency?->iso)
                        : null)
                    ->color('success')
                    ->searchable(
                        query: fn (Builder $query, string $search) => AmountSearch::apply($query, $search, 'grand_total')
                    )
                    ->sortable(),


                /*
                |--------------------------------------------------------------------------
                | DATE
                |--------------------------------------------------------------------------
                */

                Tables\Columns\TextColumn::make(
                    'date'
                )
                    ->label(
                        trans(
                            'filament-invoices::messages.invoices.columns.date'
                        )
                    )
                    ->date('Y-m-d')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                /*
                |--------------------------------------------------------------------------
                | DUE DATE
                |--------------------------------------------------------------------------
                */

                Tables\Columns\TextColumn::make(
                    'due_date'
                )
                    ->label(
                        trans(
                            'filament-invoices::messages.invoices.columns.due_date'
                        )
                    )
                    ->tooltip(
                        fn ($record) =>

                            $record->due_date->isFuture()

                                ? $record
                                    ->due_date
                                    ->diffForHumans()

                                : (

                                    $record->due_date
                                        ->isToday()

                                        ? 'Due Today!'

                                        : 'Over Due!'
                                )
                    )
                    ->color(
                        fn ($record) =>

                            $record->due_date->isFuture()

                                ? 'success'

                                : (

                                    $record->due_date
                                        ->isToday()

                                        ? 'warning'

                                        : 'danger'
                                )
                    )
                    ->icon(
                        fn ($record) =>

                            $record->due_date->isFuture()

                                ? 'heroicon-s-check-circle'

                                : (

                                    $record->due_date
                                        ->isToday()

                                        ? 'heroicon-s-exclamation-circle'

                                        : 'heroicon-s-x-circle'
                                )
                    )
                    ->date('Y-m-d')
                    ->sortable()
                    ->toggleable(),

                /*
                |--------------------------------------------------------------------------
                | TELEPHONE
                |--------------------------------------------------------------------------
                */

                Tables\Columns\TextColumn::make(
                    'telephone'
                )
                    ->label(
                        trans(
                            'filament-invoices::messages.invoices.columns.phone'
                        )
                    )
                    ->toggleable(
                        isToggledHiddenByDefault:
                            true
                    )
                    ->searchable(),

                /*
                |--------------------------------------------------------------------------
                | ADDRESS
                |--------------------------------------------------------------------------
                */

                Tables\Columns\TextColumn::make(
                    'alamat'
                )
                    ->label(
                        trans(
                            'filament-invoices::messages.invoices.columns.address'
                        )
                    )
                    ->sortable()
                    ->toggleable(
                        isToggledHiddenByDefault:
                            true
                    ),

                Tables\Columns\TextColumn::make(
                    'nama_pic'
                )
                    ->label('Nama PIC')
                    ->sortable()
                    ->toggleable(
                        isToggledHiddenByDefault:
                            true
                    ),

                
                /*
                |--------------------------------------------------------------------------
                | PAID
                |--------------------------------------------------------------------------
                */

                Tables\Columns\TextColumn::make(
                    'paid'
                )
                    ->label(
                        trans(
                            'filament-invoices::messages.invoices.columns.paid'
                        )
                    )
                    ->formatStateUsing(fn ($state, $record) => \App\Helpers\Money::inCurrency($state, $record->currency?->iso))
                    ->color('info')
                    ->sortable()
                    // Nominal terbayar terlihat dari Status dan Payment; kolomnya bisa dimunculkan.
                    ->toggleable(isToggledHiddenByDefault: true),

                /*
                |--------------------------------------------------------------------------
                | UPDATED
                |--------------------------------------------------------------------------
                */

                Tables\Columns\TextColumn::make(
                    'updated_at'
                )
                    ->label(
                        trans(
                            'filament-invoices::messages.invoices.columns.updated_at'
                        )
                    )
                    ->dateTime()
                    ->sortable()
                    ->toggleable(
                        isToggledHiddenByDefault:
                            true
                    ),

            ])

            /* =====================================================
               FILTERS
            ===================================================== */

            ->filters([

                Tables\Filters\TrashedFilter::make(),

                /*
                |--------------------------------------------------------------------------
                | INVOICE DATE
                |--------------------------------------------------------------------------
                */

                Tables\Filters\Filter::make(
                    'invoice_date'
                )
                    ->label(
                        'Tanggal Invoice'
                    )
                    ->form([

                        Forms\Components\DatePicker::make(
                            'from'
                        )
                            ->label(
                                'Dari Tanggal'
                            )
                            ->placeholder(
                                'Pilih tanggal mulai'
                            ),

                        Forms\Components\DatePicker::make(
                            'until'
                        )
                            ->label(
                                'Sampai Tanggal'
                            )
                            ->placeholder(
                                'Pilih tanggal akhir'
                            ),

                    ])
                    ->query(
                        function (
                            Builder $query,
                            array $data
                        ) {

                            return $query

                                ->when(
                                    $data['from']
                                    ?? null,

                                    fn (
                                        Builder $query,
                                        $date
                                    ) =>
                                        $query->whereDate(
                                            'date',
                                            '>=',
                                            $date
                                        )
                                )

                                ->when(
                                    $data['until']
                                    ?? null,

                                    fn (
                                        Builder $query,
                                        $date
                                    ) =>
                                        $query->whereDate(
                                            'date',
                                            '<=',
                                            $date
                                        )
                                );
                        }
                    ),

                /*
                |--------------------------------------------------------------------------
                | STATUS
                |--------------------------------------------------------------------------
                */

                Tables\Filters\SelectFilter::make(
                    'status'
                )
                    ->options([

                        'draft' =>
                            'Draft',

                        'pending' =>
                            'Pending',

                        'paid' =>
                            'Paid',

                        'partial' =>
                            'Partial',

                        'cancelled' =>
                            'Cancelled',

                    ])
                    ->label('Status')
                    ->searchable(),

                /*
                |--------------------------------------------------------------------------
                | TYPE
                |--------------------------------------------------------------------------
                */

                Tables\Filters\SelectFilter::make(
                    'type'
                )
                    ->options([

                        'sale' =>
                            'Invoice',

                        'estimate' =>
                            'Proforma Invoice',

                    ])
                    ->label('Type')
                    ->searchable(),

                /*
                |--------------------------------------------------------------------------
                | DUE
                |--------------------------------------------------------------------------
                */

                Tables\Filters\Filter::make(
                    'due'
                )
                    ->form([

                        Forms\Components\Toggle::make(
                            'overdue'
                        )
                            ->label(
                                trans(
                                    'filament-invoices::messages.invoices.filters.due.columns.overdue'
                                )
                            ),

                        Forms\Components\Toggle::make(
                            'today'
                        )
                            ->label(
                                trans(
                                    'filament-invoices::messages.invoices.filters.due.columns.today'
                                )
                            ),

                    ])
                    ->label(
                        trans(
                            'filament-invoices::messages.invoices.filters.due.label'
                        )
                    )
                    ->query(
                        function (
                            Builder $query,
                            array $data
                        ) {

                            return $query

                                ->when(
                                    $data['overdue']
                                    ?? false,

                                    function (
                                        Builder $query
                                    ) {

                                        $query->whereDate(
                                            'due_date',
                                            '<',
                                            Carbon::today()
                                        );
                                    }
                                )

                                ->when(
                                    $data['today']
                                    ?? false,

                                    function (
                                        Builder $query
                                    ) {

                                        $query->whereDate(
                                            'due_date',
                                            Carbon::today()
                                        );
                                    }
                                );
                        }
                    ),

            ])

            /* =====================================================
               EXPORT
            ===================================================== */
->headerActions([

    Tables\Actions\Action::make('exportInvoice')
        ->label('Export Invoice')
        ->icon('heroicon-o-arrow-down-tray')

        ->form([

            Forms\Components\DatePicker::make('date_from')
                ->label('Dari Tanggal')
                ->default(now()->startOfMonth())
                ->required(),

            Forms\Components\DatePicker::make('date_to')
                ->label('Sampai Tanggal')
                ->default(now())
                ->required(),

        ])

        ->action(function (array $data) {

            $dateFrom = $data['date_from'];
            $dateTo = $data['date_to'];

            /*
            |--------------------------------------------------------------------------
            | GET INVOICE
            |--------------------------------------------------------------------------
            */

            $invoices = Invoice::query()
                ->with([
                    'invoicesItems',
                    'invoiceLogs',
                ])
                ->whereDate('date', '>=', $dateFrom)
                ->whereDate('date', '<=', $dateTo)
                ->orderBy('date')
                ->orderBy('id')
                ->get();

            /*
            |--------------------------------------------------------------------------
            | FILE NAME
            |--------------------------------------------------------------------------
            */

            $filename =
                'invoice-'
                . Carbon::parse($dateFrom)->format('Ymd')
                . '-'
                . Carbon::parse($dateTo)->format('Ymd')
                . '.csv';

            /*
            |--------------------------------------------------------------------------
            | HEADERS
            |--------------------------------------------------------------------------
            */

            $headers = [
                'Content-Type' =>
                    'text/csv; charset=UTF-8',

                'Content-Disposition' =>
                    'attachment; filename="' . $filename . '"',

                'Cache-Control' =>
                    'no-store, no-cache, must-revalidate',

                'Pragma' =>
                    'no-cache',
            ];

            /*
            |--------------------------------------------------------------------------
            | DOWNLOAD
            |--------------------------------------------------------------------------
            */

            return response()->streamDownload(

                function () use ($invoices) {

                    $handle = fopen(
                        'php://output',
                        'w'
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | UTF-8 BOM
                    |--------------------------------------------------------------------------
                    */

                    fwrite(
                        $handle,
                        "\xEF\xBB\xBF"
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | SEPARATOR
                    |--------------------------------------------------------------------------
                    |
                    | Gunakan TAB agar Excel otomatis memisahkan
                    | setiap kolom.
                    |
                    */

                    $separator = "\t";

                    /*
                    |--------------------------------------------------------------------------
                    | HEADER
                    |--------------------------------------------------------------------------
                    */

                    fputcsv(
                        $handle,
                        [
                            'Tanggal',
                            'No. SJ',
                            'No.Invoice',
                            'Faktur Pajak',
                            'Customer',
                            'Nama Barang',
                            'Description',
                            'Qty',
                            'Satuan',
                            'Harga /qty',
                            'Harga Jual / DPP',
                            'PPN',
                            'Total',
                            'Tgl Jatuh Tempo',
                            'Tgl Lunas',
                        ],
                        $separator
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | TRACK INVOICE YANG SUDAH DITAMPILKAN
                    |--------------------------------------------------------------------------
                    */

                    $shownInvoices = [];

                    /*
                    |--------------------------------------------------------------------------
                    | LOOP INVOICE
                    |--------------------------------------------------------------------------
                    */

                    foreach ($invoices as $invoice) {

                        /*
                        |--------------------------------------------------------------------------
                        | INVOICE KEY
                        |--------------------------------------------------------------------------
                        */

                        $invoiceKey =
                            $invoice->uuid
                            ?: $invoice->id;

                        /*
                        |--------------------------------------------------------------------------
                        | PAYMENT TERAKHIR
                        |--------------------------------------------------------------------------
                        */

                        $lastPayment = $invoice
                            ->invoiceLogs
                            ->where('type', 'payment')
                            ->sortByDesc('created_at')
                            ->first();

                        $paidDate = $lastPayment?->created_at;

                        /*
                        |--------------------------------------------------------------------------
                        | FORMAT TANGGAL
                        |--------------------------------------------------------------------------
                        */

                        $invoiceDate = $invoice->date
                            ? Carbon::parse(
                                $invoice->date
                            )->format('d/m/Y')
                            : '';

                        $dueDate = $invoice->due_date
                            ? Carbon::parse(
                                $invoice->due_date
                            )->format('d/m/Y')
                            : '';

                        $paymentDate = $paidDate
                            ? Carbon::parse(
                                $paidDate
                            )->format('d/m/Y')
                            : '';

                        /*
                        |--------------------------------------------------------------------------
                        | CEK APAKAH INVOICE SUDAH DITAMPILKAN
                        |--------------------------------------------------------------------------
                        */

                        $isFirstInvoiceRow =
                            !isset(
                                $shownInvoices[$invoiceKey]
                            );

                        /*
                        |--------------------------------------------------------------------------
                        | TANPA ITEM
                        |--------------------------------------------------------------------------
                        */

                        if (
                            $invoice
                                ->invoicesItems
                                ->isEmpty()
                        ) {

                            fputcsv(
                                $handle,
                                [

                                    $isFirstInvoiceRow
                                        ? $invoiceDate
                                        : '',

                                    $isFirstInvoiceRow
                                        ? (
                                            $invoice
                                                ->delivery_note_number
                                            ?? ''
                                        )
                                        : '',

                                    $isFirstInvoiceRow
                                        ? (
                                            $invoice
                                                ->uuid
                                            ?? ''
                                        )
                                        : '',

                                    $isFirstInvoiceRow
                                        ? (
                                            $invoice
                                                ->faktur
                                            ?? ''
                                        )
                                        : '',

                                    $isFirstInvoiceRow
                                        ? (
                                            $invoice
                                                ->nama_perusahaan
                                            ?? ''
                                        )
                                        : '',

                                    '', // Nama Barang
                                    '', // Description
                                    '', // Qty
                                    '', // Satuan
                                    '', // Harga /qty
                                    '', // Harga Jual / DPP
                                    '', // PPN

                                    $invoice
                                        ->grand_total
                                    ?? 0,

                                    $isFirstInvoiceRow
                                        ? $dueDate
                                        : '',

                                    $isFirstInvoiceRow
                                        ? $paymentDate
                                        : '',

                                ],
                                $separator
                            );

                            $shownInvoices[$invoiceKey] = true;

                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | ITEM
                        |--------------------------------------------------------------------------
                        */

                        foreach (
                            $invoice
                                ->invoicesItems
                            as $item
                        ) {

                            /*
                            |--------------------------------------------------------------------------
                            | PRICE
                            |--------------------------------------------------------------------------
                            */

                            $price = (float) (
                                $item->price ?? 0
                            );

                            /*
                            |--------------------------------------------------------------------------
                            | QTY
                            |--------------------------------------------------------------------------
                            */

                            $qty = (float) (
                                $item->qty ?? 0
                            );

                            /*
                            |--------------------------------------------------------------------------
                            | DISCOUNT
                            |--------------------------------------------------------------------------
                            */

                            $discount = (float) (
                                $item->discount ?? 0
                            );

                            /*
                            |--------------------------------------------------------------------------
                            | VAT
                            |--------------------------------------------------------------------------
                            */

                            $vat = (float) (
                                $item->vat ?? 0
                            );

                            /*
                            |--------------------------------------------------------------------------
                            | DPP
                            |--------------------------------------------------------------------------
                            */

                            // Discount adalah TOTAL (bukan per unit)
                            $dpp = max(
                                (
                                    $price * $qty
                                ) - $discount,
                                0
                            );

                            /*
                            |--------------------------------------------------------------------------
                            | CLEAN DESCRIPTION
                            |--------------------------------------------------------------------------
                            */

                            $description =
                                html_entity_decode(
                                    strip_tags(
                                        $item->description ?? ''
                                    ),
                                    ENT_QUOTES | ENT_HTML5,
                                    'UTF-8'
                                );

                            /*
                            |--------------------------------------------------------------------------
                            | DATA INVOICE
                            |--------------------------------------------------------------------------
                            |
                            | Hanya baris pertama yang berisi:
                            |
                            | Tanggal
                            | No. SJ
                            | No.Invoice
                            | Faktur Pajak
                            | Customer
                            | Tgl Jatuh Tempo
                            | Tgl Lunas
                            |
                            |--------------------------------------------------------------------------
                            */

                            if (
                                $isFirstInvoiceRow
                            ) {

                                $tanggal =
                                    $invoiceDate;

                                $noSJ =
                                    $invoice
                                        ->delivery_note_number
                                    ?? '';

                                $noInvoice =
                                    $invoice
                                        ->uuid
                                    ?? '';

                                $fakturPajak =
                                    $invoice
                                        ->faktur
                                    ?? '';

                                $customer =
                                    $invoice
                                        ->nama_perusahaan
                                    ?? '';

                                $tanggalJatuhTempo =
                                    $dueDate;

                                $tanggalLunas =
                                    $paymentDate;

                                $isFirstInvoiceRow = false;

                                $shownInvoices[
                                    $invoiceKey
                                ] = true;

                            } else {

                                $tanggal = '';
                                $noSJ = '';
                                $noInvoice = '';
                                $fakturPajak = '';
                                $customer = '';
                                $tanggalJatuhTempo = '';
                                $tanggalLunas = '';
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | WRITE ROW
                            |--------------------------------------------------------------------------
                            */

                            fputcsv(
                                $handle,
                                [

                                    /*
                                    | Invoice
                                    */

                                    $tanggal,

                                    $noSJ,

                                    $noInvoice,

                                    $fakturPajak,

                                    $customer,

                                    /*
                                    | Item
                                    */

                                    $item->item
                                        ?? '',

                                    $description,

                                    $item->qty
                                        ?? 0,

                                    'pcs',

                                    $price,

                                    $dpp,

                                    $vat,

                                    $item->total
                                        ?? 0,

                                    /*
                                    | Payment
                                    */

                                    $tanggalJatuhTempo,

                                    $tanggalLunas,

                                ],
                                $separator
                            );
                        }
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | CLOSE
                    |--------------------------------------------------------------------------
                    */

                    fclose($handle);
                },

                $filename,

                $headers
            );
        }),

])
            ->actionsPosition(
                ActionsPosition::BeforeColumns
            )

            ->defaultSort(
                'created_at',
                'desc'
            )

            /* =====================================================
               ACTIONS
            ===================================================== */

            ->actions([

                /*
                |--------------------------------------------------------------------------
                | CONVERT TO INVOICE
                |--------------------------------------------------------------------------
                */

                Tables\Actions\Action::make(
                    'convert_to_invoice'
                )
                    ->hidden(
                        fn ($record) =>
                            $record->type !==
                            'estimate'
                    )
                    ->authorize('update')
                    ->requiresConfirmation()
                    ->iconButton()
                    ->color('success')
                    ->form([

                        Forms\Components\TextInput::make(
                            'faktur'
                        )
                            ->label(
                                'Faktur Pajak'
                            )
                            ->numeric(),

                        Forms\Components\TextInput::make(
                            'delivery_note_number'
                        )
                            ->label(
                                'No. Surat Jalan'
                            ),

                    ])
                    ->action(
                        function (
                            array $data,
                            Invoice $record
                        ) {

                            app(\App\Services\ProformaConversion::class)->toInvoice($record, [
                                'faktur'               => $data['faktur'] ?? null,
                                'delivery_note_number' => $data['delivery_note_number'] ?? null,
                            ]);

                            Notification::make()
                                ->title(
                                    'Converted to Invoice'
                                )
                                ->body(
                                    'The estimate has been successfully converted to an invoice.'
                                )
                                ->success()
                                ->send();
                        }
                    )
                    ->icon(
                        'heroicon-s-check-circle'
                    )
                    ->label(
                        'Convert to Invoice'
                    )
                    ->tooltip(
                        'Convert to Invoice'
                    ),

                /*
                |--------------------------------------------------------------------------
                | PAYMENT
                |--------------------------------------------------------------------------
                */

                Tables\Actions\Action::make(
                    'pay'
                )
                    // Visibilitas diatur sekali saja di akhir rantai: hidden() yang dipanggil
                    // dua kali membuat yang pertama tertimpa dan tidak pernah berlaku.
                    ->authorize('update')
                    ->requiresConfirmation()
                    ->iconButton()
                    ->color('info')
                    ->fillForm(
                        function ($record) {

                            /*
                            |------------------------------------------------------------
                            | NOMINAL YANG DITAGIHKAN
                            |------------------------------------------------------------
                            |
                            | Untuk invoice DP, yang ditagih adalah uang mukanya —
                            | persentase dari harga penuh — bukan seluruh grand_total.
                            | Sebelumnya modal ini berlabel "DP 20% Amount" tetapi
                            | mengisi nominalnya dengan harga penuh, sehingga angka di
                            | layar tidak cocok dengan yang tercetak di invoice.
                            |------------------------------------------------------------
                            */

                            $grandTotal = (float) $record->grand_total;
                            $paid = (float) $record->paid;
                            $persenDp = $record->dpPercentage();

                            $ditagihkan = $record->amountDue();
                            $amount = max($ditagihkan - $paid, 0);

                            $typeLabel = $persenDp !== null
                                ? 'DP ' . $persenDp . '% Amount'
                                : match ($record->type_payment) {
                                    'final_payment' => 'Final Payment Amount',
                                    default => 'Full Payment Amount',
                                };

                            return [
                                'total_label' => $typeLabel,
                                'grand_total' => $ditagihkan,
                                'paid' => $paid,
                                'amount' => $amount,
                            ];
                        }
                    )
                    ->form([

                        Forms\Components\TextInput::make(
                            'total_label'
                        )
                            ->label('Type')
                            ->disabled()
                            ->dehydrated(),

                        Forms\Components\TextInput::make(
                            'grand_total'
                        )
                            ->label(
                                trans(
                                    'filament-invoices::messages.invoices.actions.total'
                                )
                            )
                            ->numeric()
                            ->disabled(),

                        Forms\Components\TextInput::make(
                            'paid'
                        )
                            ->label(
                                trans(
                                    'filament-invoices::messages.invoices.actions.paid'
                                )
                            )
                            ->numeric()
                            ->disabled(),

                        Forms\Components\Select::make(
                            'payment_account_id'
                        )
                            ->label(
                                'Bayar dari Rekening'
                            )
                            ->options(
                                PaymentAccount::where(
                                    'is_active',
                                    true
                                )
                                    ->pluck(
                                        'name',
                                        'id'
                                    )
                                    ->toArray()
                            )
                            ->searchable()
                            ->required(),

                        Forms\Components\TextInput::make(
                            'amount'
                        )
                            ->label(
                                fn ($get) => $get('total_label') ?? 'Amount'
                            )
                            ->helperText(
                                fn ($get) => 'Sisa yang harus dibayar: Rp ' . number_format((float) ($get('grand_total') ?? 0) - (float) ($get('paid') ?? 0), 0, ',', '.')
                            )
                            ->numeric()
                            // Untuk invoice DP, sisanya dihitung dari uang muka yang
                            // ditagihkan, bukan dari harga penuh pekerjaan.
                            ->default(
                                fn ($record) => max($record->amountDue() - (float) $record->paid, 0)
                            ),

                    ])
                    ->action(
                        function (array $data, Invoice $record) {
                            // Satu jalur dengan bot Telegram: validasi sisa tagihan, status,
                            // transaksi rekening, dan log ada di InvoicePayment.
                            try {
                                app(InvoicePayment::class)->pay(
                                    $record,
                                    (float) $data['amount'],
                                    PaymentAccount::findOrFail($data['payment_account_id']),
                                    auth()->user()
                                );
                            } catch (InvoicePaymentException $e) {
                                Notification::make()
                                    ->title('Gagal')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title('Pembayaran Berhasil')
                                ->body('Anda telah melakukan pembayaran sebesar ' . number_format((float) $data['amount'], 2) . ' ' . (optional($record->currency)->iso ?? 'id'))
                                ->success()
                                ->send();
                        }
                    )
                    ->icon(
                        'heroicon-s-credit-card'
                    )
                    ->label(
                        trans(
                            'filament-invoices::messages.invoices.actions.pay.label'
                        )
                    )
                    ->modalHeading(
                        trans(
                            'filament-invoices::messages.invoices.actions.pay.label'
                        )
                    )
                    ->tooltip(
                        trans(
                            'filament-invoices::messages.invoices.actions.pay.label'
                        )
                    )
                    /*
                    |--------------------------------------------------------------------------
                    | VISIBILITAS TOMBOL BAYAR
                    |--------------------------------------------------------------------------
                    |
                    | Perbandingan nominal harus numerik. grand_total dibaca dari MySQL
                    | sebagai string ("1110000.00") sedangkan paid berupa float, sehingga
                    | perbandingan identik (===) selalu false dan tombol tetap muncul pada
                    | invoice yang sudah lunas.
                    |--------------------------------------------------------------------------
                    */
                    ->hidden(
                        fn ($record) =>

                            $record->type !== 'sale'

                            ||

                            (float) $record->paid >= $record->amountDue()

                            ||

                            in_array($record->status, ['paid', 'cancelled'], true)
                    ),

                /*
                |--------------------------------------------------------------------------
                | DOWNLOAD
                |--------------------------------------------------------------------------
                |
                | PDF yang sama dengan tombol Download di halaman View. Selalu tampil,
                | tepat sebelum ⋮, jadi posisinya sama di setiap baris.
                |--------------------------------------------------------------------------
                */

                Tables\Actions\Action::make('download')
                    ->iconButton()
                    ->color('gray')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->label('Download PDF')
                    ->tooltip('Download PDF')
                    ->url(fn (Invoice $record) => route('invoices.download', $record))
                    ->openUrlInNewTab(),

                /*
                | Aksi lain di menu ⋮. Dulu tujuh ikon berjejer di depan setiap baris
                | sehingga tabel 750px lebih lebar dari layar; Convert dan Bayar tetap
                | tampil karena itu langkah kerja berikutnya.
                */
                Tables\Actions\ActionGroup::make([

                    /*
                    |--------------------------------------------------------------------------
                    | DUPLICATE
                    |--------------------------------------------------------------------------
                    */

                    Tables\Actions\Action::make(
                        'duplicate'
                    )
                        ->color('gray')
                        ->icon(
                            'heroicon-m-document-duplicate'
                        )
                        ->label(
                            'Duplikat'
                        )
                        ->authorize('create')
                        ->action(
                            function (Invoice $record) {
                                $newInvoice = $record->duplicateAsDraft();

                                Notification::make()
                                    ->title('Invoice diduplikat')
                                    ->body('Invoice baru: ' . $newInvoice->uuid)
                                    ->success()
                                    ->send();

                                return redirect()->to(InvoiceResource::getUrl('edit', ['record' => $newInvoice]));
                            }
                        ),

                    // Invoice pelunasan yang langsung tertaut ke invoice DP ini, jadi
                    // sisa tagihannya (harga penuh − DP) dihitung benar di PDF dan ERP.
                    Tables\Actions\Action::make('pelunasan')
                        ->label('Buat pelunasan')
                        ->icon('heroicon-m-banknotes')
                        ->color('gray')
                        ->authorize('create')
                        ->visible(fn (Invoice $record) => $record->type === 'sale'
                            && $record->dpPercentage() !== null
                            && ! $record->childInvoices()->where('type_payment', 'final_payment')->exists())
                        ->requiresConfirmation()
                        ->modalHeading('Buat invoice pelunasan')
                        ->modalDescription(fn (Invoice $record) => 'Invoice baru menagih sisa ' . (100 - $record->dpPercentage()) . '% ('
                            . \App\Helpers\Money::rupiah((float) $record->grand_total - $record->amountDue()) . ') dan tertaut ke ' . $record->uuid . '.')
                        ->modalSubmitActionLabel('Buat')
                        ->action(function (Invoice $record) {
                            $final = $record->duplicateAsDraft([
                                'type_payment'      => 'final_payment',
                                'parent_invoice_id' => $record->id,
                            ]);

                            Notification::make()
                                ->title('Invoice pelunasan dibuat')
                                ->body($final->uuid . ' · sisa ' . \App\Helpers\Money::rupiah($final->amountDue()))
                                ->success()
                                ->send();

                            return redirect()->to(InvoiceResource::getUrl('edit', ['record' => $final]));
                        }),

                    /*
                    |--------------------------------------------------------------------------
                    | PROJECT
                    |--------------------------------------------------------------------------
                    |
                    | Buat project langsung dari invoice ini (cukup pilih kategori), atau
                    | buka project yang sudah dibuat darinya.
                    |--------------------------------------------------------------------------
                    */

                    ProjectFromInvoiceAction::table(),

                    /*
                    |--------------------------------------------------------------------------
                    | VIEW
                    |--------------------------------------------------------------------------
                    */

                    Tables\Actions\ViewAction::make()
                        ->label('Detail'),

                    /*
                    |--------------------------------------------------------------------------
                    | EDIT
                    |--------------------------------------------------------------------------
                    */

                    Tables\Actions\EditAction::make(),

                    /*
                    |--------------------------------------------------------------------------
                    | DELETE
                    |--------------------------------------------------------------------------
                    */

                    Tables\Actions\DeleteAction::make()
                        ->icon(
                            'heroicon-m-archive-box'
                        )
                        ->label('Arsipkan')
                        ->modalHeading(
                            trans(
                                'filament-invoices::messages.invoices.actions.archive_invoice'
                            )
                        ),

                    /*
                    |--------------------------------------------------------------------------
                    | FORCE DELETE
                    |--------------------------------------------------------------------------
                    */

                    Tables\Actions\ForceDeleteAction::make()
                        ->label('Hapus permanen'),

                    /*
                    |--------------------------------------------------------------------------
                    | RESTORE
                    |--------------------------------------------------------------------------
                    */

                    Tables\Actions\RestoreAction::make()
                        ->label('Pulihkan'),
                ]),
            ])

            /* =====================================================
               BULK ACTIONS
            ===================================================== */

            ->bulkActions([

                Tables\Actions\BulkActionGroup::make([

                    /*
                    |--------------------------------------------------------------------------
                    | EXPORT SELECTED
                    |--------------------------------------------------------------------------
                    */

                    ExportBulkAction::make(
                        'export_selected'
                    )
                        ->label(
                            'Export Selected'
                        )
                        ->icon(
                            'heroicon-o-arrow-down-tray'
                        )
                        ->exporter(
                            InvoiceExporter::class
                        ),

                    /*
                    |--------------------------------------------------------------------------
                    | CHANGE STATUS
                    |--------------------------------------------------------------------------
                    */

                    Tables\Actions\BulkAction::make(
                        'status'
                    )
                        ->label(
                            'Change Status'
                        )
                        ->tooltip(
                            'Change Status'
                        )
                        ->icon(
                            'heroicon-s-cursor-arrow-rays'
                        )
                        ->deselectRecordsAfterCompletion()
                        ->authorize(fn () => auth()->user()?->can('update_invoice'))
                        ->form([

                            Forms\Components\Select::make(
                                'status'
                            )
                                ->searchable()
                                ->options([

                                    'draft' =>
                                        'Draft',

                                    'pending' =>
                                        'Pending',

                                    'paid' =>
                                        'Paid',

                                    'partial' =>
                                        'Partial',

                                    'cancelled' =>
                                        'Cancelled',

                                ])
                                ->label(
                                    'Status'
                                )
                                ->default(
                                    'draft'
                                )
                                ->required(),

                        ])
                        ->action(
                            function (
                                array $data,
                                Collection $records
                            ) {

                                $records->each(
                                    fn ($record) =>
                                        $record->update([
                                            'status' =>
                                                $data[
                                                    'status'
                                                ]
                                        ])
                                );

                                Notification::make()
                                    ->title(
                                        'Status Updated'
                                    )
                                    ->body(
                                        'Status has been updated for selected records.'
                                    )
                                    ->success()
                                    ->send();
                            }
                        ),

                    /*
                    |--------------------------------------------------------------------------
                    | DELETE
                    |--------------------------------------------------------------------------
                    */

                    Tables\Actions\DeleteBulkAction::make(),

                    /*
                    |--------------------------------------------------------------------------
                    | FORCE DELETE
                    |--------------------------------------------------------------------------
                    */

                    Tables\Actions\ForceDeleteBulkAction::make(),

                    /*
                    |--------------------------------------------------------------------------
                    | RESTORE
                    |--------------------------------------------------------------------------
                    */

                    Tables\Actions\RestoreBulkAction::make(),

                ]),

            ]);
    }
}