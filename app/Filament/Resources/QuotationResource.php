<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QuotationResource\Pages;
use App\Helpers\ColorHelper;
use App\Models\Quotation;
use App\Models\Product;
use App\Models\Leads;
use App\Models\Deal;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Actions\Action;
use Filament\Notifications\Notification;
use TomatoPHP\FilamentInvoices\Models\Invoice;
use TomatoPHP\FilamentInvoices\Models\InvoicesItem;
use Illuminate\Database\Eloquent\Builder;

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

    public static function generateUuid(): string
    {
        $prefix = '#DG/';
        $datePart = now()->format('my') . '/';

        $lastQuotation = Quotation::where('uuid', 'like', $prefix . $datePart . '%')
            ->latest('id')
            ->first();

        $lastNumber = $lastQuotation ? intval(substr($lastQuotation->uuid, -5)) : 0;
        $newNumber = str_pad($lastNumber + 1, 5, '0', STR_PAD_LEFT);

        return $prefix . $datePart . $newNumber;
    }

    /* ===============================
       FORM
    =============================== */

    public static function form(Form $form): Form
    {
        $updateItemTotals = function (callable $get, callable $set) {
            $price = floatval($get('price') ?? 0);
            $qty = floatval($get('qty') ?? 1);
            $discount = floatval($get('discount') ?? 0);
            $vatPercentage = floatval($get('vat_percentage') ?? 0);

            $subtotalItem = $price * $qty;
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
                    Forms\Components\Select::make('nama_perusahaan')
                        ->options(Leads::query()->pluck('nama_perusahaan', 'nama_perusahaan'))
                        ->searchable()
                        ->reactive()
                        ->afterStateUpdated(function ($state, callable $set) {
                            if ($lead = Leads::where('nama_perusahaan', $state)->first()) {
                                $set('telephone', $lead->telephone);
                                $set('nama_pic', $lead->nama_pic);
                                $set('email', $lead->email);
                            }
                        })
                        ->required(),

                    Forms\Components\TextInput::make('telephone'),
                    Forms\Components\TextInput::make('nama_pic'),
                    Forms\Components\TextInput::make('email'),
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
               REPEATER ITEMS (FINAL FIXED)
            =============================== */

            Forms\Components\Section::make('Items')
                ->schema([
                    Forms\Components\Repeater::make('items')
                        ->relationship()
                        ->schema([

                            Forms\Components\Toggle::make('manual_input')
                                ->label('Manual Input')
                                ->default(false)
                                ->reactive()
                                ->afterStateHydrated(function ($state, callable $set, callable $get) {
                                    $itemName = $get('item');

                                    if ($itemName && Product::where('nama_layanan', $itemName)->exists()) {
                                        $set('manual_input', false);
                                        $set('product_name', $itemName);
                                    } else {
                                        $set('manual_input', true);
                                        $set('manual_item', $itemName);
                                    }
                                }),

                            Forms\Components\Select::make('product_name')
                                ->label('Item Name')
                                ->options(Product::query()->pluck('nama_layanan', 'nama_layanan'))
                                ->searchable()
                                ->hidden(fn ($get) => $get('manual_input'))
                                ->reactive()
                                ->afterStateUpdated(function ($state, callable $set) {
                                    if ($product = Product::where('nama_layanan', $state)->first()) {
                                        $set('item', $product->nama_layanan);
                                        $set('Description', $product->detail_layanan);
                                        $set('price', $product->harga_layanan);
                                    }
                                })
                                ->columnSpanFull(),

                            Forms\Components\TextInput::make('manual_item')
                                ->label('Item Name')
                                ->hidden(fn ($get) => !$get('manual_input'))
                                ->reactive()
                                ->afterStateUpdated(fn ($state, callable $set) => $set('item', $state))
                                ->columnSpanFull(),

                            Forms\Components\Hidden::make('item')
                                ->required()
                                ->dehydrated(),

                            Forms\Components\RichEditor::make('Description')
                                ->columnSpanFull(),

                            Forms\Components\TextInput::make('qty')
                                ->numeric()
                                ->default(1)
                                ->reactive()
                                ->afterStateUpdated($updateItemTotals),

                            Forms\Components\TextInput::make('discount')
                                ->numeric()
                                ->default(0)
                                ->reactive()
                                ->afterStateUpdated($updateItemTotals),

                            Forms\Components\TextInput::make('vat_percentage')
                                ->numeric()
                                ->default(0)
                                ->reactive()
                                ->afterStateUpdated($updateItemTotals),

                            Forms\Components\TextInput::make('price')
                                ->numeric()
                                ->default(0)
                                ->reactive()
                                ->afterStateUpdated($updateItemTotals),

                            Forms\Components\TextInput::make('vat')
                                ->numeric()
                                ->disabled()
                                ->dehydrated(),

                            Forms\Components\TextInput::make('total')
                                ->numeric()
                                ->disabled()
                                ->dehydrated(),
                        ])
                        ->columns(2)
                        ->reactive()
                        ->afterStateUpdated(fn ($get, $set) => self::recalculateTotals($get, $set))
                        ->defaultItems(1),
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

    protected static function recalculateTotals(callable $get, callable $set)
    {
        $items = $get('items') ?? [];

        $subtotal = 0;
        $totalDiscount = 0;
        $totalVat = 0;

        foreach ($items as $item) {
            $price = floatval($item['price'] ?? 0);
            $qty = floatval($item['qty'] ?? 1);
            $discount = floatval($item['discount'] ?? 0);
            $vatPercentage = floatval($item['vat_percentage'] ?? 0);

            $subtotalItem = $price * $qty;
            $discountedSubtotal = max($subtotalItem - $discount, 0);
            $vat = $discountedSubtotal * ($vatPercentage / 100);

            $subtotal += $subtotalItem;
            $totalDiscount += $discount;
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
                Tables\Columns\TextColumn::make('uuid')->label('ID'),

                Tables\Columns\TextColumn::make('nama_perusahaan')
                ->limit(20)->tooltip(fn ($record) => $record->nama_perusahaan),


                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn ($record) => ColorHelper::quotationStatus($record->status)),

                Tables\Columns\TextColumn::make('grand_total')
                    ->label('Total Quotation')
                    ->money('IDR'),
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

                        $deal = Deal::where(
                            'nama_perusahaan',
                            $record->nama_perusahaan
                        )->first();

                        /* ========= GENERATE PROFORMA UUID ========= */
                        $prefix = '#PI/';
                        $datePart = now()->format('my/');

                        $lastInvoice = Invoice::where(
                            'uuid',
                            'like',
                            $prefix . '013' . $datePart . '%'
                        )
                            ->latest('uuid')
                            ->first();

                        $lastNumber = $lastInvoice
                            ? intval(substr($lastInvoice->uuid, -5))
                            : 0;

                        $newNumber = str_pad(
                            $lastNumber + 1,
                            5,
                            '0',
                            STR_PAD_LEFT
                        );

                        $uuid = $prefix . '013' . $datePart . $newNumber;

                        /* ========= DB TRANSACTION ========= */
                        \DB::transaction(function () use ($record, $uuid, $deal) {

                            $invoice = Invoice::create([
                                'uuid'            => $uuid,
                                'for_type'        => 'quotation',
                                'for_id'          => $record->id,
                                'user_id'         => auth()->id(),
                                'nama_perusahaan' => $record->nama_perusahaan,
                                'telephone'       => $record->telephone,
                                'alamat'          => $deal?->alamat
                                    ?? $record->alamat
                                    ?? '-',
                                'type'            => 'estimate',
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
                                    'description'    => $item->Description,
                                    'qty'            => $item->qty,
                                    'price'          => $item->price,
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
                    ->icon('heroicon-m-download')
                    ->iconButton()
                    ->color('primary')
                    ->icon('heroicon-o-document-arrow-down')
                    ->url(fn(Quotation $record) =>
                        route('quotation.download', $record)
                    )
                    ->openUrlInNewTab()
                    ->color('danger'),

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