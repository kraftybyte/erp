<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;



class ProductResource extends Resource
{
    protected static ?string $model = Product::class;
    protected static ?string $navigationIcon = 'heroicon-o-cube-transparent';

    public static function getNavigationLabel(): string
    {
        return \App\Helpers\NavigationHelper::getLabel('product', 'Product');
    }
    protected static ?int $navigationSort = -4;

    protected static ?string $recordTitleAttribute = 'nama_layanan';

    public static function form(Form $form): Form
    {
        return $form
        ->schema([
            Forms\Components\Wizard::make([
                Forms\Components\Wizard\Step::make('Informasi Layanan')
                    ->schema([
                        Forms\Components\TextInput::make('nama_layanan')
                            ->label('Nama Layanan')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Forms\Components\RichEditor::make('detail_layanan')
                            ->label('Detail Layanan')
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->columns([
                        'sm' => 1,
                        'lg' => 2,
                    ]),
                Forms\Components\Wizard\Step::make('Harga dan Pajak')
                    ->schema([
                        Forms\Components\TextInput::make('harga_layanan')
                            ->label('Harga Layanan')
                            ->numeric()
                            ->required()
                            ->reactive()
                            ->step(1), // Reactive untuk memicu update
                        Forms\Components\Select::make('pajak')
                            ->label('Pajak')
                            ->multiple()
                            ->options([
                                '12' => 'PPN 12%',
                            ])
                            ->reactive()
                        ->afterStateUpdated(function (callable $set, $state, $get) {
                            $harga = $get('harga_layanan') ?? 0;
                            $pajak = $state; // Pajak terbaru
                            $set('total_harga', static::calculateTotalHarga($harga, $pajak));
                        }),
                        Forms\Components\TextInput::make('total_harga')
                            ->label('Total Harga')
                            ->numeric()
                            ->reactive() // Pastikan ini juga reaktif
                            ->afterStateUpdated(function (callable $set, $state, $get) {
                                $harga = $get('harga_layanan') ?? 0;
                                $pajak = $get('pajak') ?? [];
                                $set('total_harga', static::calculateTotalHarga($harga, $pajak));
                            }),
                    ])
                    ->columns([
                        'sm' => 1,
                        'lg' => 2,
                    ]),
            ])
            ->columnSpanFull(),
        ]);
}

public static function calculateTotalHarga($hargaLayanan, $pajak): float
{
    if (!$hargaLayanan || empty($pajak)) {
        return $hargaLayanan ?? 0;
    }

    $pajakValues = array_map('floatval', (array) $pajak);
    $totalPajak = array_sum($pajakValues) / 100;
    $totalHarga = $hargaLayanan + ($hargaLayanan * $totalPajak);

    return round($totalHarga); // Menghilangkan desimal agar bulat
}

protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Hitung nilai total_harga sebelum data disimpan
        $data['total_harga'] = static::calculateTotalHarga($data['harga_layanan'], $data['pajak']);
        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Hitung nilai total_harga sebelum data diperbarui
        $data['total_harga'] = static::calculateTotalHarga($data['harga_layanan'], $data['pajak']);
        return $data;
    }



    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->searchable()
                    ->sortable()
                    ->label('Product ID'),
                Tables\Columns\TextColumn::make('nama_layanan')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('detail_layanan')
                    ->searchable()
                    ->limit(30)
                    ->html(),
                Tables\Columns\TextColumn::make('total_harga')
                    ->searchable()
                    ->sortable()
                    ->money('IDR'),
                Tables\Columns\TextColumn::make('pajak')
                    ->searchable()
                    ->suffix('%'),
            ])
            ->defaultSort('nama_layanan', 'asc')
            ->filters([
                Tables\Filters\SelectFilter::make('pajak')
                    ->label('Pajak')
                    ->options([
                        '12' => 'PPN 12%',
                    ]),
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

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __("CRM");
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
