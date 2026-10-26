<?php

namespace App\Filament\Resources;

use App\Models\Invoice;
use App\Models\Deal;
use App\Models\Product;
use App\Models\PaymentAccount;
use App\Models\Transaction;
use App\Filament\Resources\InvoiceResource\Pages;
use App\Filament\Exports\InvoiceExporter;

use Filament\Tables;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\ExportBulkAction;

use Illuminate\Support\Carbon;

use TomatoPHP\FilamentInvoices\Filament\Resources\InvoiceResource as BaseInvoiceResource;
use TomatoPHP\FilamentTypes\Components\TypeColumn;

use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Tables\Enums\ActionsPosition;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

use Illuminate\Support\Facades\DB;
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
                        ->dehydrated(false)
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

                    Forms\Components\TextInput::make(
                        'uuid'
                    )
                        ->unique(
                            ignoreRecord: true
                        )
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
                        ->default('sale')
                        ->searchable()
                        ->live()
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

                    Forms\Components\Select::make(
                        'type_payment'
                    )
                        ->label(
                            'Tipe Pembayaran'
                        )
                        ->required()
                        ->searchable()
                        ->options([

                            'full_payment' =>
                                'Full Payment',

                            'final_payment' =>
                                'Final Payment',

                            'dp_10' =>
                                'Down Payment 10%',

                            'dp_20' =>
                                'Down Payment 20%',

                            'dp_30' =>
                                'Down Payment 30%',

                            'dp_40' =>
                                'Down Payment 40%',

                            'dp_50' =>
                                'Down Payment 50%',

                            'dp_60' =>
                                'Down Payment 60%',

                            'dp_70' =>
                                'Down Payment 70%',

                            'dp_80' =>
                                'Down Payment 80%',

                            'dp_90' =>
                                'Down Payment 90%',

                        ]),

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
                ->schema([

                    Forms\Components\Repeater::make(
                        'invoicesItems'
                    )
                        ->relationship()
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
                                    ) {

                                        if (
                                            $product =
                                                Product::find(
                                                    $state
                                                )
                                        ) {

                                            /*
                                            |--------------------------------------------------------------------------
                                            | ORIGINAL PRICE
                                            |--------------------------------------------------------------------------
                                            */

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
                                            | PAYMENT TYPE
                                            |--------------------------------------------------------------------------
                                            */

                                            $typePayment =
                                                $get(
                                                    '../../type_payment'
                                                );

                                            /*
                                            |--------------------------------------------------------------------------
                                            | FULL PAYMENT
                                            |--------------------------------------------------------------------------
                                            */

                                            if (
                                                $typePayment ===
                                                    'full_payment'
                                                ||
                                                blank(
                                                    $typePayment
                                                )
                                            ) {

                                                $set(
                                                    'price',
                                                    $originalPrice
                                                );

                                                return;
                                            }

                                            /*
                                            |--------------------------------------------------------------------------
                                            | DP
                                            |--------------------------------------------------------------------------
                                            */

                                            if (
                                                str_starts_with(
                                                    $typePayment ?? '',
                                                    'dp_'
                                                )
                                            ) {

                                                $percentage =
                                                    (float)
                                                    str_replace(
                                                        'dp_',
                                                        '',
                                                        $typePayment
                                                    );

                                                $dpPrice =
                                                    $originalPrice
                                                    *
                                                    (
                                                        $percentage
                                                        / 100
                                                    );

                                                $set(
                                                    'price',
                                                    round(
                                                        $dpPrice,
                                                        2
                                                    )
                                                );

                                                return;
                                            }

                                            $set(
                                                'price',
                                                $originalPrice
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

            $totalDiscount +=
                $discount;

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

    /**
     * Angka dari satu kata pencarian, untuk mencari nominal: "Rp79.960.250",
     * "79.960.250,00", dan "79960250" menjadi "79960250". Null bila kata itu
     * bukan nominal (nama perusahaan, nomor invoice, dll).
     */
    protected static function angkaPencarian(string $kata): ?string
    {
        $kata = preg_replace('/^rp\.?/i', '', trim($kata));
        $kata = preg_replace('/,\d{1,2}$/', '', $kata);

        return preg_match('/^\d[\d.,]*$/', $kata)
            ? str_replace(['.', ','], '', $kata)
            : null;
    }

    public static function getEloquentQuery(): Builder
    {
        // Kolom "Customer / PIC" membaca $record->deal; tanpa eager load di sini
        // tiap baris memicu query sendiri.
        return parent::getEloquentQuery()->with('deal');
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
                    ->description(
                        fn ($record) =>
                            (
                                $record->type ===
                                'sale'
                                    ? 'Invoice'
                                    : 'Proforma Invoice'
                            )
                            . ' '
                            .
                            trans(
                                'filament-invoices::messages.invoices.columns.by'
                            )
                            . ' '
                            .
                            (
                                $record->user?->name
                                ?? '-'
                            )
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
                    ->toggleable(),

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
                                return 'Final Payment';
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

                                $angka = static::angkaPencarian($search);

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
                    ->money(
                        locale: 'id',
                        currency: fn ($record) =>
                            $record
                                ->currency?->iso
                            ?? 'IDR'
                    )
                    ->color('success')
                    ->searchable(
                        query: function (Builder $query, string $search) {

                            // Filament mencari per kata dan setiap kata harus cocok;
                            // "Rp" pada "Rp 79.960.250" bukan syarat pencarian.
                            if (preg_match('/^rp\.?$/i', $search)) {
                                $query->whereRaw('1 = 1');

                                return;
                            }

                            $angka = static::angkaPencarian($search);

                            if ($angka !== null) {
                                $query->whereRaw('CAST(ROUND(grand_total) AS CHAR) LIKE ?', ["%{$angka}%"]);
                            }
                        }
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
                    ->toggleable(),

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
                    ->money(
                        locale: 'id',
                        currency: fn ($record) =>
                            $record
                                ->currency?->iso
                            ?? 'IDR'
                    )
                    ->color('info')
                    ->sortable(),

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

                            $newUuid =
                                Invoice::generateUuid(
                                    'sale'
                                );

                            $record->update([

                                'type' =>
                                    'sale',

                                'uuid' =>
                                    $newUuid,

                                'faktur' =>
                                    $data[
                                        'faktur'
                                    ],

                                'delivery_note_number' =>
                                    $data[
                                        'delivery_note_number'
                                    ]
                                    ?? null,

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
                | DUPLICATE
                |--------------------------------------------------------------------------
                */

                Tables\Actions\Action::make(
                    'duplicate'
                )
                    ->iconButton()
                    ->color('warning')
                    ->icon(
                        'heroicon-s-document-duplicate'
                    )
                    ->label(
                        'Duplicate'
                    )
                    ->tooltip(
                        'Duplicate Invoice'
                    )
                    ->action(
                        function (Invoice $record) {

                            // Generate new UUID with next sequence
                            $newUuid = Invoice::generateUuid(
                                $record->type ?? 'sale'
                            );

                            // Create duplicate
                            $newInvoice = Invoice::create([
                                'uuid' => $newUuid,
                                'date' => now(),
                                'due_date' => now()->addDays(30),
                                'type' => $record->type ?? 'sale',
                                'status' => 'draft',
                                'type_payment' => $record->type_payment ?? 'full_payment',
                                'nama_perusahaan' => $record->nama_perusahaan,
                                'nama_pic' => $record->nama_pic,
                                'telephone' => $record->telephone,
                                'alamat' => $record->alamat,
                                'deal_id' => $record->deal_id,
                                'po_number' => null,
                                'faktur' => null,
                                'delivery_note_number' => null,
                                'subtotal' => $record->subtotal,
                                'total_discount' => $record->total_discount,
                                'total_vat' => $record->total_vat,
                                'grand_total' => $record->grand_total,
                                'paid' => 0,
                                'currency_id' => $record->currency_id,
                                'notes' => $record->notes,
                                'user_id' => auth()->id(),
                            ]);

                            // Copy items
                            foreach ($record->invoicesItems as $item) {
                                $newInvoice->invoicesItems()->create([
                                    'item' => $item->item,
                                    'description' => $item->description,
                                    'qty' => $item->qty,
                                    'price' => $item->price,
                                    'discount' => $item->discount,
                                    'vat_percentage' => $item->vat_percentage,
                                    'vat' => $item->vat,
                                    'total' => $item->total,
                                    'original_price' => $item->original_price,
                                ]);
                            }

                            Notification::make()
                                ->title(
                                    'Invoice Duplicated'
                                )
                                ->body(
                                    'New invoice created: ' . $newUuid
                                )
                                ->success()
                                ->send();

                            // Redirect to edit the new invoice
                            return redirect()->to(
                                InvoiceResource::getUrl('edit', [
                                    'record' => $newInvoice
                                ])
                            );
                        }
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
                        function (
                            array $data,
                            Invoice $record
                        ) {

                            DB::transaction(
                                function () use (
                                    $data,
                                    $record
                                ) {

                                    /*
                                    |--------------------------------------------------------------------------
                                    | NOMINAL YANG DITAGIHKAN
                                    |--------------------------------------------------------------------------
                                    |
                                    | Untuk invoice DP, tagihannya adalah uang muka — bukan
                                    | grand_total yang menyimpan harga penuh pekerjaan. Memakai
                                    | grand_total di sini membuat invoice DP tidak pernah bisa
                                    | berstatus lunas sampai klien membayar seluruh pekerjaan.
                                    |--------------------------------------------------------------------------
                                    */

                                    $ditagihkan = $record->amountDue();

                                    $remainingAmount =
                                        $ditagihkan
                                        -
                                        $record
                                            ->paid;

                                    if (
                                        $data[
                                            'amount'
                                        ]
                                        >
                                        $remainingAmount
                                    ) {

                                        Notification::make()
                                            ->title(
                                                'Gagal'
                                            )
                                            ->body(
                                                'Jumlah pembayaran melebihi total yang harus dibayar.'
                                            )
                                            ->danger()
                                            ->send();

                                        return;
                                    }

                                    $newPaidAmount =
                                        $record
                                            ->paid
                                        +
                                        $data[
                                            'amount'
                                        ];

                                    /*
                                    |--------------------------------------------------------------------------
                                    | STATUS
                                    |--------------------------------------------------------------------------
                                    */

                                    $newStatus =
                                        $newPaidAmount >= $ditagihkan
                                            ? 'paid'
                                            : 'partial';

                                    /*
                                    |--------------------------------------------------------------------------
                                    | CATATAN DP
                                    |--------------------------------------------------------------------------
                                    |
                                    | Dicatat sebagai keterangan yang terbaca manusia. Angka yang
                                    | dipakai PDF pelunasan diambil dari parent_invoice_id, bukan
                                    | dari teks ini.
                                    |--------------------------------------------------------------------------
                                    */

                                    $dpPercentage = $record->dpPercentage();

                                    $notesUpdate = null;
                                    if (
                                        $dpPercentage !== null
                                        && $newStatus === 'paid'
                                        && $record->paid < $ditagihkan
                                    ) {
                                        $notesUpdate = "DP: {$dpPercentage}% - Rp " . number_format($newPaidAmount, 0, ',', '.') . "\n";
                                    }

                                    $record->update([

                                        'paid' =>
                                            $newPaidAmount,

                                        'status' =>
                                            $newStatus,

                                        'notes' =>
                                            $notesUpdate ?? $record->notes,

                                    ]);

                                    /*
                                    |--------------------------------------------------------------------------
                                    | PAYMENT META
                                    |--------------------------------------------------------------------------
                                    */

                                    $record
                                        ->invoiceMetas()
                                        ->create([

                                            'key' =>
                                                'payments',

                                            'value' =>
                                                $data[
                                                    'amount'
                                                ],

                                        ]);

                                    $currencyIso =
                                        optional(
                                            $record
                                                ->currency
                                        )->iso
                                        ?? 'id';

                                    /*
                                    |--------------------------------------------------------------------------
                                    | PAYMENT ACCOUNT
                                    |--------------------------------------------------------------------------
                                    */

                                    $paymentAccount =
                                        PaymentAccount::find(
                                            $data[
                                                'payment_account_id'
                                            ]
                                        );

                                    if (
                                        $paymentAccount
                                    ) {

                                        /*
                                        |--------------------------------------------------------------------------
                                        | CREATE TRANSACTION (PEMASUKAN)
                                        |--------------------------------------------------------------------------
                                        */

                                        Transaction::create([
                                            'payment_account_id' => $data['payment_account_id'],
                                            // Kolomnya bernama created_by, bukan user_id — nilai
                                            // yang dikirim ke kolom yang tidak ada akan dibuang
                                            // mass assignment tanpa peringatan.
                                            'created_by' => auth()->id(),
                                            'type' => 'in',
                                            'category' => 'invoice',
                                            'amount' => $data['amount'],
                                            // Agar transaksi bisa ditelusuri balik ke invoicenya,
                                            // sama seperti advance dan expense.
                                            'reference_type' => 'invoice',
                                            'reference_id' => $record->id,
                                            'transaction_date' => now(),
                                            'description' => 'Pembayaran Invoice ' . $record->uuid . ' dari ' . ($record->nama_perusahaan ?? '-'),
                                        ]);

                                        /*
                                        |--------------------------------------------------------------------------
                                        | SYNC SALDO
                                        |--------------------------------------------------------------------------
                                        |
                                        | Pembayaran invoice = uang MASUK, jadi saldo naik.
                                        | Dihitung ulang dari transaksi (initial + in - out) agar
                                        | current_balance tidak pernah menyimpang dari mutasi.
                                        |--------------------------------------------------------------------------
                                        */

                                        $paymentAccount
                                            ->syncBalanceFromTransactions();
                                    }

                                    /*
                                    |--------------------------------------------------------------------------
                                    | INVOICE LOG
                                    |--------------------------------------------------------------------------
                                    */

                                    $record
                                        ->invoiceLogs()
                                        ->create([

                                            'log' =>
                                                'Paid '
                                                .
                                                number_format(
                                                    $data[
                                                        'amount'
                                                    ],
                                                    2
                                                )
                                                .
                                                ' '
                                                .
                                                $currencyIso
                                                .
                                                ' from '
                                                .
                                                (
                                                    $paymentAccount
                                                        ?->name
                                                    ??
                                                    'Unknown'
                                                )
                                                .
                                                ' By: '
                                                .
                                                auth()
                                                    ->user()
                                                    ->name,

                                            'type' =>
                                                'payment',

                                        ]);

                                    Notification::make()
                                        ->title(
                                            'Pembayaran Berhasil'
                                        )
                                        ->body(
                                            'Anda telah melakukan pembayaran sebesar '
                                            .
                                            number_format(
                                                $data[
                                                    'amount'
                                                ],
                                                2
                                            )
                                            .
                                            ' '
                                            .
                                            $currencyIso
                                        )
                                        ->success()
                                        ->send();
                                }
                            );
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
                | VIEW
                |--------------------------------------------------------------------------
                */

                Tables\Actions\ViewAction::make()
                    ->iconButton()
                    ->tooltip(
                        trans(
                            'filament-invoices::messages.invoices.actions.view_invoice'
                        )
                    ),

                /*
                |--------------------------------------------------------------------------
                | EDIT
                |--------------------------------------------------------------------------
                */

                Tables\Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip(
                        trans(
                            'filament-invoices::messages.invoices.actions.edit_invoice'
                        )
                    ),

                /*
                |--------------------------------------------------------------------------
                | DELETE
                |--------------------------------------------------------------------------
                */

                Tables\Actions\DeleteAction::make()
                    ->iconButton()
                    ->icon(
                        'heroicon-s-archive-box'
                    )
                    ->label(
                        trans(
                            'filament-invoices::messages.invoices.actions.archive_invoice'
                        )
                    )
                    ->modalHeading(
                        trans(
                            'filament-invoices::messages.invoices.actions.archive_invoice'
                        )
                    )
                    ->tooltip(
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
                    ->iconButton()
                    ->tooltip(
                        trans(
                            'filament-invoices::messages.invoices.actions.delete_invoice_forever'
                        )
                    ),

                /*
                |--------------------------------------------------------------------------
                | RESTORE
                |--------------------------------------------------------------------------
                */

                Tables\Actions\RestoreAction::make()
                    ->iconButton()
                    ->tooltip(
                        trans(
                            'filament-invoices::messages.invoices.actions.restore_invoice'
                        )
                    ),

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