<?php

namespace App\Filament\Resources;

use App\Models\Invoice;
use App\Models\PaymentAccount;
use App\Models\Transaction;
use App\Models\Deal;
use App\Models\Product;
use Filament\Tables;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use TomatoPHP\FilamentInvoices\Filament\Resources\InvoiceResource as BaseInvoiceResource;
use TomatoPHP\FilamentInvoices\Models\InvoicesItem;
use TomatoPHP\FilamentTypes\Models\Type;
use TomatoPHP\FilamentCurrencies\Models\Currency;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Extended InvoiceResource with PaymentAccount integration
 *
 * Note: Uses local App\Models\Invoice which extends TomatoPHP's Invoice
 * This ensures payment_account_id is in the fillable array
 */
class InvoiceResource extends BaseInvoiceResource
{
    // Override to use local Invoice model with payment_account_id support
    protected static ?string $model = Invoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 5;

    /**
     * Override pages to use custom ViewInvoice
     */
    public static function getPages(): array
    {
        return array_merge(parent::getPages(), [
            'index' => \App\Filament\Resources\InvoiceResource\Pages\ListInvoices::route('/'),
            'create' => \App\Filament\Resources\InvoiceResource\Pages\CreateInvoice::route('/create'),
            'edit' => \App\Filament\Resources\InvoiceResource\Pages\EditInvoice::route('/{record}/edit'),
            'view' => \App\Filament\Resources\InvoiceResource\Pages\ViewInvoice::route('/{record}/show'),
        ]);
    }

    /**
     * Override getRelations
     */
    public static function getRelations(): array
    {
        return array_merge(parent::getRelations(), []);
    }

    /**
     * Override form to ensure all fields are properly configured
     */
    public static function form(Form $form): Form
    {
        $types = Type::query()
            ->where('for', 'invoices')
            ->where('type', 'type');

        $statues = Type::query()
            ->where('for', 'invoices')
            ->where('type', 'status');

        return $form
            ->schema([
                Forms\Components\TextInput::make('uuid')
                    ->unique(ignoreRecord: true)
                    ->label('UUID')
                    ->default(fn() => self::generateUuid(request()->input('type', 'invoice')))
                    ->readOnly()
                    ->columnSpanFull()
                    ->maxLength(255)
                    ->reactive()
                    ->afterStateUpdated(fn(Forms\Set $set, $state) => $set('uuid', self::generateUuid($state))),

                Forms\Components\Grid::make(['sm' => 1, 'lg' => 2])->schema([
                    // Customer Data Section
                    Forms\Components\Section::make('Data Pelanggan')
                        ->schema([
                            Forms\Components\TextInput::make('nama_perusahaan')
                                ->label('Nama Perusahaan'),
                            Forms\Components\TextInput::make('telephone')
                                ->label('Telephone'),
                            Forms\Components\Textarea::make('alamat')
                                ->label('Alamat'),
                        ])
                        ->columns(1)
                        ->columnSpan(1),

                    // Invoice Data Section
                    Forms\Components\Section::make('Data Invoice')
                        ->schema([
                            Forms\Components\DatePicker::make('date')
                                ->label('Tanggal Invoice')
                                ->required()
                                ->default(Carbon::now()),
                            Forms\Components\DatePicker::make('due_date')
                                ->label('Jatuh Tempo')
                                ->required()
                                ->default(Carbon::now()->addDays(30)),
                            Forms\Components\Select::make('type')
                                ->label('Tipe')
                                ->required()
                                ->default('sale')
                                ->searchable()
                                ->options([
                                    'sale' => 'Invoice',
                                    'estimate' => 'Proforma Invoice',
                                ])
                                ->reactive(),
                            Forms\Components\Select::make('status')
                                ->label('Status')
                                ->required()
                                ->default('draft')
                                ->searchable()
                                ->options([
                                    'draft' => 'Draft',
                                    'pending' => 'Pending',
                                    'paid' => 'Paid',
                                    'partial' => 'Partial',
                                    'cancelled' => 'Cancelled',
                                ]),
                            Forms\Components\TextInput::make('type_payment')
                                ->label('Type Payment')
                                ->placeholder('Full DP, Half DP, dll'),
                            Forms\Components\TextInput::make('po_number')
                                ->label('PO Number'),
                            Forms\Components\TextInput::make('faktur')
                                ->label('No. Faktur'),
                        ])
                        ->columns(2)
                        ->columnSpan(1),
                ]),

                // Financial Section
                Forms\Components\Section::make('Data Keuangan')
                    ->schema([
                        Forms\Components\Grid::make(3)->schema([
                            Forms\Components\TextInput::make('subtotal')
                                ->label('Subtotal')
                                ->numeric()
                                ->prefix('Rp ')
                                ->default(0),
                            Forms\Components\TextInput::make('total_discount')
                                ->label('Discount')
                                ->numeric()
                                ->prefix('Rp ')
                                ->default(0),
                            Forms\Components\TextInput::make('total_vat')
                                ->label('VAT/PPN')
                                ->numeric()
                                ->prefix('Rp ')
                                ->default(0),
                        ]),
                        Forms\Components\Grid::make(3)->schema([
                            Forms\Components\TextInput::make('total')
                                ->label('Total')
                                ->numeric()
                                ->prefix('Rp ')
                                ->default(0),
                            Forms\Components\TextInput::make('paid')
                                ->label('Paid')
                                ->numeric()
                                ->prefix('Rp ')
                                ->default(0),
                            Forms\Components\TextInput::make('grand_total')
                                ->label('Grand Total')
                                ->numeric()
                                ->prefix('Rp ')
                                ->default(0),
                        ]),
                    ]),

                // Payment Account Section
                Forms\Components\Section::make('Rekening Pembayaran')
                    ->schema([
                        Forms\Components\Select::make('payment_account_id')
                            ->label('Rekening')
                            ->options(PaymentAccount::where('is_active', true)->pluck('name', 'id')->toArray())
                            ->searchable(),
                    ]),

                // Section Items
                Forms\Components\Section::make('Item')
                    ->schema([
                        Forms\Components\Repeater::make('invoicesItems')
                            ->relationship()
                            ->schema([
                                Forms\Components\TextInput::make('item')
                                    ->label('Item')
                                    ->required()
                                    ->columnSpanFull(),
                                Forms\Components\Textarea::make('description')
                                    ->label('Description')
                                    ->columnSpanFull(),
                                Forms\Components\Grid::make(4)->schema([
                                    Forms\Components\TextInput::make('qty')
                                        ->label('Qty')
                                        ->numeric()
                                        ->default(1)
                                        ->reactive(),
                                    Forms\Components\TextInput::make('price')
                                        ->label('Price')
                                        ->numeric()
                                        ->prefix('Rp ')
                                        ->default(0)
                                        ->reactive(),
                                    Forms\Components\TextInput::make('discount')
                                        ->label('Discount')
                                        ->numeric()
                                        ->prefix('Rp ')
                                        ->default(0)
                                        ->reactive(),
                                    Forms\Components\TextInput::make('total')
                                        ->label('Total')
                                        ->numeric()
                                        ->prefix('Rp ')
                                        ->default(0)
                                        ->reactive(),
                                ]),
                            ])
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Override table to show PaymentAccount column
     */
    public static function table(Table $table): Table
    {
        return parent::table($table);
    }
}