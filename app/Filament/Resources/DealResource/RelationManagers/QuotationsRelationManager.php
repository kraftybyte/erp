<?php

namespace App\Filament\Resources\DealResource\RelationManagers;

use App\Helpers\ColorHelper;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use App\Models\Product;
use App\Models\Leads;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Models\Quotation;

class QuotationsRelationManager extends RelationManager
{
    protected static string $relationship = 'quotations';
    

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('nama_perusahaan')
                ->options(Leads::query()->pluck('nama_perusahaan', 'nama_perusahaan')->toArray())
                ->searchable()
                ->afterStateUpdated(function (callable $get, callable $set) {
                    if ($forName = $get('nama_perusahaan')) {
                        $lead = Leads::where('nama_perusahaan', $forName)->first();
                        if ($lead) {
                            $set('telephone', $lead->telephone);
                            $set('nama_pic', $lead->nama_pic);
                            $set('email', $lead->email);
                        }
                    }
                })
                ->required()
                ->reactive(),
            
            Forms\Components\TextInput::make('telephone')->readonly(),
            Forms\Components\TextInput::make('nama_pic')->readonly(),
            Forms\Components\TextInput::make('email')->readonly(),
            Forms\Components\DatePicker::make('date')
                ->label('Quotation Date')
                ->required(),
            
            Forms\Components\DatePicker::make('due_date')
                ->label('Due Date')
                ->required(),
            
            Forms\Components\Select::make('status')
                ->options([
                    'Pending' => 'Pending',
                    'Approved' => 'Approved',
                    'Rejected' => 'Rejected',
                ])
                ->default('Pending'),
                Forms\Components\Repeater::make('items')
                ->relationship()
                ->schema([
                    Forms\Components\Select::make('item')
                        ->required()
                        ->label('Item Name')
                        ->options(Product::query()->pluck('nama_layanan', 'nama_layanan')->toArray())
                        ->searchable()
                        ->afterStateUpdated(function (callable $get, callable $set) {
                            if ($itemName = $get('item')) {
                                $product = Product::where('nama_layanan', $itemName)->first();
                                if ($product) {
                                    $set('Description', $product->detail_layanan);
                                    $set('price', $product->harga_layanan);
                                }
                            }
                        }),
                    Forms\Components\TextArea::make('Description')->reactive(),
                    Forms\Components\TextInput::make('price')
                        ->numeric()
                        ->default(0)
                        ->reactive()
                        ->live()
                        ->afterStateUpdated(function ($state, callable $get, callable $set) {
                            $total = $get('qty') * $state - $get('discount');
                            $vat = $total * ($get('vat_percentage') / 100);
                            $set('vat', round($vat, 2));
                            $set('total', round($total + $vat, 2));
                        }),
                    Forms\Components\TextInput::make('discount')->numeric()->default(0)->live(),
                    Forms\Components\TextInput::make('vat_percentage')
                        ->numeric()
                        ->label('VAT (%)')
                        ->default(0)
                        ->rules(['min:0', 'max:100'])
                        ->live(),
                    Forms\Components\TextInput::make('qty')
                        ->numeric()
                        ->default(1)
                        ->live()
                        ->afterStateUpdated(function ($state, callable $get, callable $set) {
                            $total = $state * $get('price') - $get('discount');
                            $vat = $total * ($get('vat_percentage') / 100);
                            $set('vat', round($vat, 2));
                            $set('total', round($total + $vat, 2));
                        }),
                    Forms\Components\TextInput::make('vat')
                        ->numeric()
                        ->disabled()
                        ->label('Total VAT')
                        ->dehydrated(),
                    Forms\Components\TextInput::make('total')
                        ->numeric()
                        ->disabled()
                        ->label('Sub Total Item')
                        ->dehydrated(),
                ])
                ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                    $total = ($data['qty'] * $data['price']);
                    $vat = $total * ($data['vat_percentage'] / 100);
                    $data['vat'] = round($vat, 2);
                    $data['total'] = round($total + $vat - $data['discount'], 2);
                    return $data;
                    
                })
                ->columns(2)
                ->columnSpanFull()
                ->reactive()
                ->afterStateUpdated(function (callable $get, callable $set) {
                    $items = $get('items') ?? [];
                    $subtotal = collect($items)->sum(function ($item) {
                        return $item['price'] * $item['qty'];
                    });
            
                    $totalVat = collect($items)->sum('vat');
                    $totalDiscount = collect($items)->sum('discount');
                    $grandTotal = $subtotal + $totalVat - $totalDiscount;
            
                    $set('subtotal', round($subtotal, 2));
                    $set('total_vat', round($totalVat, 2));
                    $set('total_discount', round($totalDiscount, 2));
                    $set('grand_total', round($grandTotal, 2));
            
                }),
            
            Forms\Components\Section::make('Total Quotation')
                ->schema([
                    Forms\Components\TextInput::make('subtotal')
                        ->numeric()
                        ->disabled()
                        ->dehydrated()
                        ->default(0),
                    Forms\Components\TextInput::make('total_vat')
                        ->numeric()
                        ->disabled()
                        ->dehydrated()
                        ->default(0),
                    Forms\Components\TextInput::make('total_discount')
                        ->numeric()
                        ->disabled()
                        ->dehydrated()
                        ->default(0),
                    Forms\Components\TextInput::make('grand_total')
                        ->numeric()
                        ->disabled()
                        ->dehydrated()
                        ->default(0)
                        ->columnSpanFull(),
                ])
                ->columns(3),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('nama_perusahaan')
            ->columns([
                Tables\Columns\TextColumn::make('id') ->label('IDç'),
                Tables\Columns\TextColumn::make('nama_perusahaan')->label('Nama Perusahaan')
                    ->url(fn ($record) => url("/quotations/{$record->id}/download")),
                    Tables\Columns\TextColumn::make('status')
                        ->badge()
                        ->color(fn ($record) => ColorHelper::quotationStatus($record->status)),
                    Tables\Columns\TextColumn::make('grand_total')
                        ->label('Total Quotation')
                        ->money('IDR'),
            ])
            ->filters([])
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

    public function canViewAny(): bool
    {
        return true;
    }

    public function canCreate(): bool
    {
        return true;
    }

    public function canEdit($record): bool
    {
        return true;
    }

    public function canDelete($record): bool
    {
        return true;
    }
}
