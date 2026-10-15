<?php

namespace App\Filament\Resources\DealResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\TextInput;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn; 
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use TomatoPHP\FilamentTypes\Components\TypeColumn;
use TomatoPHP\FilamentTypes\Models\Type;
use TomatoPHP\FilamentTypes\Models\Status;
use App\Models\Deal;
use App\Models\Product;
use carbon\Carbon;
use TomatoPHP\FilamentLocations\Models\Currency;
use App\Providers\Company;
use App\Models\Invoice;

    class InvoicesRelationManager extends RelationManager
    {
        protected static string $relationship = 'invoices';
        protected static bool $isLazy = false;


    public function form(Form $form): Form
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
                ->disabled(fn(Invoice $invoice) => $invoice->exists)
                ->label(trans('filament-invoices::messages.invoices.columns.uuid'))
                ->default(function () {
                    $prefix = request()->input('type', 'invoice') === 'sale' ? '#DG/' : '#PI/';
                    $datePart = now()->format('my/');
                    $lastInvoice = Invoice::where('uuid', 'like', $prefix . $datePart . '%')->latest('id')->first();
                    $lastNumber = $lastInvoice ? intval(substr($lastInvoice->uuid, -5)) : 0;
                    $newNumber = str_pad($lastNumber + 1, 5, '0', STR_PAD_LEFT);
                    return $prefix . $datePart . $newNumber;
                })
                ->required()
                ->columnSpanFull()
                ->maxLength(255)
                ->reactive()
                ->afterStateUpdated(function (Forms\Set $set, $state) {
                    $prefix = $state === 'sale' ? '#DG/' : '#PI/';
                    $datePart = now()->format('my/');
                    $lastInvoice = Invoice::where('uuid', 'like', $prefix . $datePart . '%')->latest('id')->first();
                    $lastNumber = $lastInvoice ? intval(substr($lastInvoice->uuid, -5)) : 0;
                    $newNumber = str_pad($lastNumber + 1, 5, '0', STR_PAD_LEFT);
                    $set('uuid', $prefix . $datePart . $newNumber);
                }),

                Forms\Components\Grid::make(['sm' => 1, 'lg' => 2])->schema([

                // Billed From - Deals Table
                Forms\Components\Section::make(trans('filament-invoices::messages.invoices.sections.billed_from.title'))
                    ->schema([
                        Forms\Components\Select::make('for_id')
                            ->label('Billed From')
                            ->required()
                            ->searchable()
                            ->live()
                            ->options(Deal::query()->pluck('nama_perusahaan', 'id')->toArray())
                            ->afterStateUpdated(function (Forms\Get $get, Forms\Set $set) {
                                $forId = $get('for_id');
                                if ($forId) {
                                    $deal = Deal::find($forId);
                                    $set('nama_perusahaan', $deal->nama_perusahaan);
                                    $set('telephone', $deal->telephone);
                                    $set('alamat', $deal->alamat);
                                }
                            })
                            ->dehydrated(fn($state) => !is_null($state))
                            ->columnSpanFull()
                            ->formatStateUsing(fn ($state, $record) => $record ? $record->nama_perusahaan : $state),
                    ])
                    ->columns(1)
                    ->columnSpan(3)
                    ->collapsible()
                    ->columnSpanFull()
                    ->collapsed(false),

                // Invoice Data Section
                Forms\Components\Section::make(trans('filament-invoices::messages.invoices.sections.invoice_data.title'))
                    ->schema([
                        Forms\Components\DatePicker::make('date')
                            ->label(trans('filament-invoices::messages.invoices.sections.invoice_data.columns.date'))
                            ->required()
                            ->default(Carbon::now()),
                        Forms\Components\DatePicker::make('due_date')
                            ->label(trans('filament-invoices::messages.invoices.sections.invoice_data.columns.due_date'))
                            ->required()
                            ->default(Carbon::now()),
                        Forms\Components\Select::make('type')
                            ->label(trans('filament-invoices::messages.invoices.sections.invoice_data.columns.type'))
                            ->required()
                            ->default('estimate')
                            ->searchable()
                            ->options($types->pluck('name', 'key')->toArray())
                            ->reactive()
                            ->afterStateUpdated(function (Forms\Set $set, $state) {
                                $prefix = $state === 'estimate' ? '#PI/' : '#DG/';
                                $datePart = now()->format('my/');
                                $lastInvoice = Invoice::where('uuid', 'like', $prefix . $datePart . '%')->latest('id')->first();
                                $lastNumber = $lastInvoice ? intval(substr($lastInvoice->uuid, -5)) : 0;
                                $newNumber = str_pad($lastNumber + 1, 5, '0', STR_PAD_LEFT);
                                $set('uuid', $prefix . $datePart . $newNumber);
                            }),
                        Forms\Components\Select::make('status')
                            ->label(trans('filament-invoices::messages.invoices.sections.invoice_data.columns.status'))
                            ->required()
                            ->default('draft')
                            ->searchable()
                            ->options($statues->pluck('name', 'key')->toArray()),
                            Forms\Components\TextInput::make('type_payment')
                            ->label('Type Payment')
                            ->required(),
                        Forms\Components\Select::make('currency_id')
                            ->label(trans('filament-invoices::messages.invoices.sections.invoice_data.columns.currency'))
                            ->required()
                            ->columnSpanFull()
                            ->default(Currency::query()->where('iso', 'IDR')->first()?->id)
                            ->searchable()
                            ->options(Currency::query()->pluck('name', 'id')->toArray()),
                    ])
                    ->columns(2)
                    ->columnSpan(1)
                    ->collapsible()
                    ->collapsed(fn($record) => $record),

                // Customer Data Section
                Forms\Components\Section::make(trans('filament-invoices::messages.invoices.sections.customer_data.title'))
                    ->schema([
                        Forms\Components\TextInput::make('nama_perusahaan')
                            ->label('Nama Perusahaan'),
                        Forms\Components\TextInput::make('telephone')
                            ->label('Telephone'),
                        Forms\Components\Textarea::make('alamat')
                            ->label('Alamat'),
                    ])
                    ->columns(1)
                    ->columnSpan(1)
                    ->collapsible()
                    ->collapsed(fn($record) => $record),
            ]),
            Forms\Components\Repeater::make('items')
            ->hiddenLabel()
            ->collapsible()
            ->collapsed(fn($record) => $record)
            ->cloneable()
            ->relationship('invoicesItems')
            ->label(trans('filament-invoices::messages.invoices.columns.items'))
            ->itemLabel(trans('filament-invoices::messages.invoices.columns.item'))
            ->schema([
        Forms\Components\TextInput::make('item')
            ->label('Nama Layanan')
            ->columnSpan(4)
            ->required()
            ->datalist(Product::query()->pluck('nama_layanan', 'id')->toArray())
            ->live()
            ->reactive()
            ->afterStateUpdated(function (Forms\Set $set, $state) {
                if ($state) {
                    $product = Product::find($state);
                    if ($product) {
                        $set('description', $product->detail_layanan);
                        $set('price', $product->total_harga);
                    }
                }
            }),
        Forms\Components\TextInput::make('description')
            ->label(trans('filament-invoices::messages.invoices.columns.description'))
            ->columnSpan(8),
        Forms\Components\TextInput::make('qty')
            ->live()
            ->columnSpan(2)
            ->label(trans('filament-invoices::messages.invoices.columns.qty'))
            ->default(1)
            ->numeric()
            ->reactive(),
        Forms\Components\TextInput::make('price')
            ->label(trans('filament-invoices::messages.invoices.columns.price'))
            ->columnSpan(3)
            ->default(0)
            ->numeric(),
        Forms\Components\TextInput::make('discount')
            ->label(trans('filament-invoices::messages.invoices.columns.discount'))
            ->columnSpan(2) 
            ->default(0)
            ->numeric(),
        Forms\Components\TextInput::make('vat')
            ->label(trans('Vat %'))
            ->columnSpan(2)
            ->default(0)
            ->numeric(),
        Forms\Components\TextInput::make('total')
            ->label(trans('filament-invoices::messages.invoices.columns.total'))
            ->columnSpan(3)
            ->default(0)
            ->numeric(),
    ])
    ->lazy()
    ->afterStateUpdated(function (Forms\Get $get, Forms\Set $set) {
        $items = $get('items') ?? []; // Pastikan 'items' adalah array, jika null berikan array kosong
        $total = 0;
        $discount = 0;
        $vat = 0;
        $collectItems = [];

        foreach ($items as $invoiceItem) {
            // Kalkulasi total, discount, vat, dll
            $itemVat = (float)$invoiceItem['vat'] / 100; // Ubah VAT menjadi persentase
            $itemTotal = ((intval($invoiceItem['price']) - intval($invoiceItem['discount'])) * intval($invoiceItem['qty']));
            $vatAmount = $itemTotal * $itemVat;
            $getTotal = $itemTotal + $vatAmount;
            $total += $getTotal;
            $invoiceItem['total'] = $getTotal;
            $discount += (intval($invoiceItem['discount']) * intval($invoiceItem['qty']));
            $vat += $vatAmount;

            $collectItems[] = $invoiceItem;
        }

        // Set nilai total, discount, vat, dan items
        $set('total', $total);
        $set('discount', $discount);
        $set('vat', $vat);
        $set('items', $collectItems);
    })
    ->columns(12)
    ->columnSpanFull(),

Forms\Components\Section::make(trans('filament-invoices::messages.invoices.sections.totals.title'))
    ->schema([
        Forms\Components\TextInput::make('vat')
            ->disabled()
            ->label(trans('filament-invoices::messages.invoices.columns.vat'))
            ->numeric()
            ->default(0),
        Forms\Components\TextInput::make('discount')
            ->disabled()
            ->label(trans('filament-invoices::messages.invoices.columns.discount'))
            ->numeric()
            ->default(0),
        Forms\Components\TextInput::make('total')
            ->disabled()
            ->label(trans('filament-invoices::messages.invoices.columns.total'))
            ->numeric()
            ->default(0),
        Forms\Components\Textarea::make('notes')
            ->label(trans('filament-invoices::messages.invoices.columns.notes'))
            ->columnSpanFull(),
    ])
    ->collapsible()
    ->collapsed(fn($record) => $record),
            ]); 
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('uuid')
                ->columns([
                    Tables\Columns\TextColumn::make('uuid')
                        ->label(trans('filament-invoices::messages.invoices.columns.uuid'))
                        ->description(fn($record) => $record->type . ' ' . trans('filament-invoices::messages.invoices.columns.by') . ' ' . $record->user?->name)
                        ->sortable()
                        ->searchable()
                        ->toggleable()
                        ->url(fn ($record) => url("/admin/invoices/{$record->id}/show")),
                    Tables\Columns\BadgeColumn::make('invoice_type')
                        ->label('Invoice Type')
                        ->getStateUsing(fn($record) => $record->type === 'sale' ? 'Invoice' : 'Proforma Invoice')
                        ->colors([
                            'success' => 'sale',
                            'warning' => 'estimate',
                        ])
                        ->sortable()
                        ->toggleable(),
                    Tables\Columns\TextColumn::make('nama_perusahaan')
                        ->label('Billed From')
                        ->sortable()
                        ->toggleable(),
                    Tables\Columns\TextColumn::make('date')
                        ->label(trans('filament-invoices::messages.invoices.columns.date'))
                        ->date('Y-m-d')
                        ->sortable()
                        ->toggleable(),
                    Tables\Columns\TextColumn::make('due_date')
                        ->label(trans('filament-invoices::messages.invoices.columns.due_date'))
                        ->tooltip(fn($record) => !$record->due_date ? 'No due date' : ($record->due_date->isFuture() ? $record->due_date->diffForHumans() : ($record->due_date->isToday() ? 'Due Today!' : 'Over Due!')))
                        ->color(fn($record) => !$record->due_date ? 'gray' : ($record->due_date->isFuture() ? 'success' : ($record->due_date->isToday() ? 'warning' : 'danger')))
                        ->icon(fn($record) => !$record->due_date ? null : ($record->due_date->isFuture() ? 'heroicon-s-check-circle' : ($record->due_date->isToday() ? 'heroicon-s-exclamation-circle' : 'heroicon-s-x-circle')))
                        ->date('Y-m-d')
                        ->sortable()
                        ->toggleable(),
                    TypeColumn::make('status')
                        ->label(trans('filament-invoices::messages.invoices.columns.status'))
                        ->sortable()
                        ->searchable(),
                    Tables\Columns\TextColumn::make('telephone')
                        ->label(trans('filament-invoices::messages.invoices.columns.phone'))
                        ->toggleable(isToggledHiddenByDefault: true)
                        ->searchable(),
                    Tables\Columns\TextColumn::make('alamat')
                        ->label(trans('filament-invoices::messages.invoices.columns.address'))
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),
                    Tables\Columns\TextColumn::make('total')
                        ->label(trans('filament-invoices::messages.invoices.columns.total'))
                        ->money(locale: 'en', currency: (fn($record) => $record->currency?->iso))
                        ->color('success')
                        ->sortable(),
                    Tables\Columns\TextColumn::make('paid')
                        ->label(trans('filament-invoices::messages.invoices.columns.paid'))
                        ->money(locale: 'en', currency: (fn($record) => $record->currency?->iso))
                        ->color('info')
                        ->sortable(),
                    Tables\Columns\TextColumn::make('updated_at')
                        ->label(trans('filament-invoices::messages.invoices.columns.updated_at'))
                        ->dateTime()
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
