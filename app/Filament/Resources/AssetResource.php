<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AssetResource\Pages;
use App\Models\Asset;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Tables\Filters\SelectFilter;
use App\Models\User;
use Filament\Forms\Components\Checkbox;


class AssetResource extends Resource
{
    protected static ?string $model = Asset::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationLabel = 'Asset';
    protected static ?int $navigationSort = -0;


    public static function getNavigationGroup(): ?string
    {
        return __("Management");
    }


    public static function form(Form $form): Form
    {
        return $form
            ->schema([

            Forms\Components\TextInput::make('uuid')
                ->label('ID Barang')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('nama_barang')
                ->label('Nama Barang')
                ->required()
                ->maxLength(255),

            Forms\Components\DatePicker::make('tanggal_pembelian')
                ->label('Tanggal Pembelian')
                ->required(),

            Forms\Components\Textarea::make('deskripsi')
                ->label('Deskripsi Barang'),

            Forms\Components\Select::make('pembelian_dari')
                ->label('Pembelian Dari')
                ->options([
                    'Marketplace' => 'Marketplace',
                    'Langsung' => 'Langsung',
                ])
                ->required(),

            Forms\Components\Select::make('kondisi')
                ->label('Kondisi Barang')
                ->options([
                    'Baru' => 'Baru',
                    'Bekas' => 'Bekas',
                ])
                ->required(),

            Forms\Components\DatePicker::make('maintenance_date')
                ->label('Maintenance Terakhir')
                ->nullable(),
                
                Forms\Components\Select::make('penanggung_jawab_id')
                ->label('Nama Penanggung Jawab')
                ->options(fn () => cache()->remember('users_list', 300, fn () => User::all()->mapWithKeys(fn($user) => [$user->id => "{$user->firstname} {$user->lastname}"])))
                ->searchable()
                ->required(),            


            Forms\Components\Checkbox::make('maintenance')
            ->label('Barang Wajib Maintenance?')
            ->nullable(),


            Forms\Components\FileUpload::make('bon_invoice')
                ->label('Bon / Invoice')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                ->maxSize(5120) // 5MB
                ->directory('asset/invoice/' . now()->format('Y/m/d'))
                ->nullable()
                ->validationMessages([
                    'max' => 'Ukuran file maksimal 5MB.',
                    'mimes' => 'Format file harus JPG, PNG, WebP, atau PDF.',
                ]),

            Forms\Components\FileUpload::make('foto')
                ->label('Foto Barang')
                ->image()
                ->imageResizeMaxWidth(1920)
                ->imageResizeMaxHeight(1920)
                ->maxSize(5120) // 5MB
                ->directory('assets')
                ->nullable()
                ->validationMessages([
                    'max' => 'Ukuran file maksimal 5MB.',
                    'image' => 'File harus berupa gambar.',
                ]),

            Forms\Components\TextInput::make('harga_barang')
                ->label('Harga Barang')
                ->numeric()
                ->prefix('Rp')
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
        ->modifyQueryUsing(function (Builder $query) {
            $user = auth()->user();
            if ($user->hasRole(['Director','Finance', 'super_admin'])) {
                return $query->with(['user']);
            }
            return $query->where('penanggung_jawab_id', $user->id)->with(['user']);
        })
            ->columns([
                Tables\Columns\ImageColumn::make('foto')
                    ->label('Foto')
                    ->circular(),
                Tables\Columns\TextColumn::make('uuid')->label('ID Barang')->searchable(),
                Tables\Columns\TextColumn::make('nama_barang')->label('Nama Barang')->searchable(),
                Tables\Columns\TextColumn::make('tanggal_pembelian')->label('Tanggal Pembelian')->date(),
                Tables\Columns\TextColumn::make('pembelian_dari')->label('Pembelian Dari'),
                Tables\Columns\TextColumn::make('kondisi')->label('Kondisi'),
                Tables\Columns\TextColumn::make('user.firstname')
                ->label('Penganggung Jawab')
                ->sortable()
                ->searchable()
                ->alignment('center'),
                Tables\Columns\TextColumn::make('harga_barang')->label('Harga')->money('IDR'),
            ])
            ->filters([
                SelectFilter::make('pembelian_dari')
                    ->label('Filter Pembelian Dari')
                    ->options([
                        'Marketplace' => 'Marketplace',
                        'Langsung' => 'Langsung',
                    ]),

                SelectFilter::make('kondisi')
                    ->label('Filter Kondisi')
                    ->options([
                        'Baru' => 'Baru',
                        'Bekas' => 'Bekas',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAssets::route('/'),
            'create' => Pages\CreateAsset::route('/create'),
            'edit' => Pages\EditAsset::route('/{record}/edit'),
        ];
    }
}
