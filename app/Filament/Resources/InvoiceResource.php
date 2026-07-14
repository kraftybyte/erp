<?php

namespace App\Filament\Resources;

use App\Models\Invoice;
use App\Models\Deal;
use App\Models\Product;
use App\Models\PaymentAccount;
use App\Filament\Resources\InvoiceResource\Pages;
use Filament\Tables;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use TomatoPHP\FilamentInvoices\Filament\Resources\InvoiceResource as BaseInvoiceResource;
use TomatoPHP\FilamentInvoices\Models\InvoicesItem;
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
        return \App\Helpers\NavigationHelper::getLabel('invoice', 'Invoice');
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

            // UUID
            Forms\Components\TextInput::make('uuid')
                ->unique(ignoreRecord: true)
                ->label('UUID')
                ->default(fn() => Invoice::generateUuid(Request::input('type', 'sale')))
                ->readOnly()
                ->columnSpanFull()
                ->reactive()
                ->afterStateUpdated(fn(Forms\Set $set, $state) => $set('uuid', Invoice::generateUuid($state))),

            /* ===============================
               COMPANY INFORMATION
            =============================== */
            Forms\Components\Section::make('Company Information')
                ->schema([
                    Forms\Components\Select::make('nama_perusahaan')
                        ->label('Nama Perusahaan')
                        ->options(Deal::query()->pluck('nama_perusahaan', 'nama_perusahaan'))
                        ->searchable()
                        ->reactive()
                        ->afterStateUpdated(function ($state, callable $set) {
                            if ($deal = Deal::where('nama_perusahaan', $state)->first()) {
                                $set('telephone', $deal->telephone);
                                $set('alamat', $deal->alamat);
                            }
                        })
                        ->required(),

                    Forms\Components\TextInput::make('telephone')
                        ->label('Telephone'),

                    Forms\Components\Textarea::make('alamat')
                        ->label('Alamat')
                        ->rows(2),
                ])
                ->columns(3)
                ->columnSpanFull(),

            /* ===============================
               DATA INVOICE
            =============================== */
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
                ->columns(4)
                ->columnSpanFull(),

            /* ===============================
               REPEATER ITEMS
            =============================== */
            Forms\Components\Section::make('Items')
                ->schema([
                    Forms\Components\Repeater::make('invoicesItems')
                        ->relationship()
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
                                        $set('manual_text', null);
                                    } else {
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
                                ->label('Description')
                                ->columnSpanFull(),

                            // Qty
                            Forms\Components\TextInput::make('qty')
                                ->label('Qty')
                                ->numeric()
                                ->default(1)
                                ->reactive()
                                ->afterStateUpdated($updateItemTotals),

                            // Price
                            Forms\Components\TextInput::make('price')
                                ->label('Price')
                                ->numeric()
                                ->prefix('Rp ')
                                ->default(0)
                                ->reactive()
                                ->afterStateUpdated($updateItemTotals),

                            // Discount
                            Forms\Components\TextInput::make('discount')
                                ->label('Discount')
                                ->numeric()
                                ->prefix('Rp ')
                                ->default(0)
                                ->reactive()
                                ->afterStateUpdated($updateItemTotals),

                            // VAT Percentage
                            Forms\Components\TextInput::make('vat_percentage')
                                ->label('VAT %')
                                ->numeric()
                                ->default(0)
                                ->reactive()
                                ->afterStateUpdated($updateItemTotals),

                            // VAT (calculated)
                            Forms\Components\TextInput::make('vat')
                                ->label('VAT')
                                ->numeric()
                                ->prefix('Rp ')
                                ->disabled()
                                ->dehydrated(),

                            // Total (calculated)
                            Forms\Components\TextInput::make('total')
                                ->label('Total')
                                ->numeric()
                                ->prefix('Rp ')
                                ->disabled()
                                ->dehydrated(),
                        ])
                        ->columns(2)
                        ->afterStateUpdated(fn ($get, $set) => self::recalculateTotals($get, $set))
                        ->defaultItems(1)
                        ->mutateRelationshipDataBeforeFillUsing(function (array $data): array {
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
                            unset($data['is_manual'], $data['product_id'], $data['manual_text']);
                            return $data;
                        }),
                ]),

            /* ===============================
               TOTAL INVOICE
            =============================== */
            Forms\Components\Section::make('Total Invoice')
                ->schema([
                    Forms\Components\Grid::make(4)->schema([
                        Forms\Components\TextInput::make('subtotal')
                            ->label('Subtotal')
                            ->numeric()
                            ->prefix('Rp ')
                            ->disabled()
                            ->dehydrated(),

                        Forms\Components\TextInput::make('total_discount')
                            ->label('Discount')
                            ->numeric()
                            ->prefix('Rp ')
                            ->disabled()
                            ->dehydrated(),

                        Forms\Components\TextInput::make('total_vat')
                            ->label('VAT/PPN')
                            ->numeric()
                            ->prefix('Rp ')
                            ->disabled()
                            ->dehydrated(),

                        Forms\Components\TextInput::make('grand_total')
                            ->label('Grand Total')
                            ->numeric()
                            ->prefix('Rp ')
                            ->disabled()
                            ->dehydrated(),
                    ]),
                ]),
        ]);
    }

    protected static function recalculateTotals(callable $get, callable $set)
    {
        $items = $get('invoicesItems') ?? [];

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

    /* ===============================
       TABLE
    =============================== */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('uuid')
                    ->label(trans('filament-invoices::messages.invoices.columns.uuid'))
                    ->description(fn($record) =>
                        ($record->type === 'sale' ? 'Invoice' : 'Proforma Invoice')
                        . ' ' . trans('filament-invoices::messages.invoices.columns.by')
                        . ' ' . $record->user?->name
                    )
                    ->sortable()
                    ->searchable()
                    ->toggleable(),
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
                    ->tooltip(fn($record) => $record->due_date->isFuture() ? $record->due_date->diffForHumans() : ($record->due_date->isToday() ? 'Due Today!' : 'Over Due!'))
                    ->color(fn($record) => $record->due_date->isFuture() ? 'success' : ($record->due_date->isToday() ? 'warning' : 'danger'))
                    ->icon(fn($record) => $record->due_date->isFuture() ? 'heroicon-s-check-circle' : ($record->due_date->isToday() ? 'heroicon-s-exclamation-circle' : 'heroicon-s-x-circle'))
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
                Tables\Columns\TextColumn::make('grand_total')
                    ->label(trans('filament-invoices::messages.invoices.columns.total'))
                    ->money(locale: 'id', currency: (fn($record) => $record->currency?->iso))
                    ->color('success')
                    ->sortable(),
                Tables\Columns\TextColumn::make('paid')
                    ->label(trans('filament-invoices::messages.invoices.columns.paid'))
                    ->money(locale: 'id', currency: (fn($record) => $record->currency?->iso))
                    ->color('info')
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(trans('filament-invoices::messages.invoices.columns.updated_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TrashedFilter::make(),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'pending' => 'Pending',
                        'paid' => 'Paid',
                        'partial' => 'Partial',
                        'cancelled' => 'Cancelled',
                    ])
                    ->label('Status')
                    ->searchable(),
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'sale' => 'Invoice',
                        'estimate' => 'Proforma Invoice',
                    ])
                    ->label('Type')
                    ->searchable(),
                Tables\Filters\Filter::make('due')
                    ->form([
                        Forms\Components\Toggle::make('overdue')
                            ->label(trans('filament-invoices::messages.invoices.filters.due.columns.overdue')),
                        Forms\Components\Toggle::make('today')
                            ->label(trans('filament-invoices::messages.invoices.filters.due.columns.today')),
                    ])
                    ->label(trans('filament-invoices::messages.invoices.filters.due.label'))
                    ->query(function (Builder $query, array $data) {
                        return $query->when($data['overdue'], function (Builder $query, $value) {
                            if ($value) {
                                $query->whereDate('due_date', '<', Carbon::now());
                            }
                        })->when($data['today'], function (Builder $query, $value) {
                            if ($value) {
                                $query->whereDate('due_date', Carbon::today());
                            }
                        });
                    }),
            ])
            ->actionsPosition(ActionsPosition::BeforeColumns)
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('convert_to_invoice')
                    ->hidden(fn($record) => $record->type !== 'estimate')
                    ->requiresConfirmation()
                    ->iconButton()
                    ->color('success')
                    ->form([
                        Forms\Components\TextInput::make('faktur')
                            ->label('Faktur Pajak')
                            ->required()
                            ->numeric(),
                    ])
                    ->action(function (array $data, Invoice $record) {
                        $newUuid = self::generateUuid('sale');

                        $record->update([
                            'type' => 'sale',
                            'uuid' => $newUuid,
                            'faktur' => $data['faktur'],
                        ]);

                        Notification::make()
                            ->title('Converted to Invoice')
                            ->body('The estimate has been successfully converted to an invoice.')
                            ->success()
                            ->send();
                    })
                    ->icon('heroicon-s-check-circle')
                    ->label('Convert to Invoice')
                    ->tooltip('Convert to Invoice'),
                Tables\Actions\Action::make('pay')
                    ->hidden(fn($record) => ($record->grand_total === $record->paid) || $record->status === 'paid' || $record->status === 'estimate')
                    ->requiresConfirmation()
                    ->iconButton()
                    ->color('info')
                    ->fillForm(fn($record) => [
                        'grand_total' => $record->grand_total,
                        'paid' => $record->paid,
                        'amount' => $record->total - $record->paid,
                    ])
                    ->form([
                        Forms\Components\TextInput::make('grand_total')
                            ->label(trans('filament-invoices::messages.invoices.actions.total'))
                            ->numeric()
                            ->disabled(),

                        Forms\Components\TextInput::make('paid')
                            ->label(trans('filament-invoices::messages.invoices.actions.paid'))
                            ->numeric()
                            ->disabled(),

                        Forms\Components\Select::make('payment_account_id')
                            ->label('Bayar dari Rekening')
                            ->options(PaymentAccount::where('is_active', true)->pluck('name', 'id')->toArray())
                            ->searchable()
                            ->required(),

                        Forms\Components\TextInput::make('amount')
                            ->label(trans('filament-invoices::messages.invoices.actions.amount'))
                            ->required()
                            ->numeric(),
                    ])
                    ->action(function (array $data, Invoice $record) {
                        \DB::transaction(function () use ($data, $record) {
                            $remainingAmount = $record->grand_total - $record->paid;
                            if ($data['amount'] > $remainingAmount) {
                                Notification::make()
                                    ->title('Gagal')
                                    ->body('Jumlah pembayaran melebihi total yang harus dibayar.')
                                    ->danger()
                                    ->send();
                                return;
                            }

                            $newPaidAmount = $record->paid + $data['amount'];
                            $record->update([
                                'paid' => $newPaidAmount,
                                'status' => 'paid'
                            ]);

                            $record->invoiceMetas()->create([
                                'key' => 'payments',
                                'value' => $data['amount']
                            ]);

                            $currencyIso = optional($record->currency)->iso ?? 'id';

                            // Update payment account balance
                            $paymentAccount = PaymentAccount::find($data['payment_account_id']);
                            if ($paymentAccount) {
                                $paymentAccount->decrement('current_balance', $data['amount']);
                            }

                            $record->invoiceLogs()->create([
                                'log' => 'Paid ' . number_format($data['amount'], 2) . ' ' . $currencyIso . ' from ' . ($paymentAccount?->name ?? 'Unknown') . ' By: ' . auth()->user()->name,
                                'type' => 'payment',
                            ]);

                            Notification::make()
                                ->title('Pembayaran Berhasil')
                                ->body('Anda telah melakukan pembayaran sebesar ' . number_format($data['amount'], 2) . ' ' . $currencyIso)
                                ->success()
                                ->send();
                        });
                    })
                    ->icon('heroicon-s-credit-card')
                    ->label(trans('filament-invoices::messages.invoices.actions.pay.label'))
                    ->modalHeading(trans('filament-invoices::messages.invoices.actions.pay.label'))
                    ->tooltip(trans('filament-invoices::messages.invoices.actions.pay.label'))
                    ->hidden(fn($record) => $record->type !== 'sale' || ($record->grand_total === $record->paid) || $record->status === 'paid'),
                Tables\Actions\ViewAction::make()
                    ->iconButton()
                    ->tooltip(trans('filament-invoices::messages.invoices.actions.view_invoice')),
                Tables\Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip(trans('filament-invoices::messages.invoices.actions.edit_invoice')),
                Tables\Actions\DeleteAction::make()
                    ->iconButton()
                    ->icon('heroicon-s-archive-box')
                    ->label(trans('filament-invoices::messages.invoices.actions.archive_invoice'))
                    ->modalHeading(trans('filament-invoices::messages.invoices.actions.archive_invoice'))
                    ->tooltip(trans('filament-invoices::messages.invoices.actions.archive_invoice')),
                Tables\Actions\ForceDeleteAction::make()
                    ->iconButton()
                    ->tooltip(trans('filament-invoices::messages.invoices.actions.delete_invoice_forever')),
                Tables\Actions\RestoreAction::make()
                    ->iconButton()
                    ->tooltip(trans('filament-invoices::messages.invoices.actions.restore_invoice')),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('status')
                        ->label('Change Status')
                        ->tooltip('Change Status')
                        ->icon('heroicon-s-cursor-arrow-rays')
                        ->deselectRecordsAfterCompletion()
                        ->form([
                            Forms\Components\Select::make('status')
                                ->searchable()
                                ->options([
                                    'draft' => 'Draft',
                                    'pending' => 'Pending',
                                    'paid' => 'Paid',
                                    'partial' => 'Partial',
                                    'cancelled' => 'Cancelled',
                                ])
                                ->label('Status')
                                ->default('draft')
                                ->required(),
                        ])
                        ->action(function (array $data, Collection $records) {
                            $records->each(fn($record) => $record->update(['status' => $data['status']]));

                            Notification::make()
                                ->title('Status Updated')
                                ->body('Status has been updated for selected records.')
                                ->success()
                                ->send();
                        }),
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make()
                ]),
            ]);
    }
}