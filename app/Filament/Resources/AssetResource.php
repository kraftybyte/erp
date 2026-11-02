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
use App\Helpers\Money;
use App\Services\AssetDepreciation;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Get;


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
                ->maxSize(2048) // 2MB (sesuaikan dengan PHP limit)
                ->directory('asset/invoice/' . now()->format('Y/m/d'))
                ->nullable()
                ->validationMessages([
                    'max' => 'Ukuran file maksimal 2MB.',
                    'mimes' => 'Format file harus JPG, PNG, WebP, atau PDF.',
                ]),

            Forms\Components\FileUpload::make('foto')
                ->label('Foto Barang')
                ->image()
                ->maxSize(2048) // 2MB (sesuaikan dengan PHP limit)
                ->directory('assets')
                ->nullable()
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->validationMessages([
                    'max' => 'Ukuran file maksimal 2MB.',
                    'image' => 'File harus berupa gambar.',
                ]),

            Forms\Components\TextInput::make('harga_barang')
                ->label('Harga Barang')
                ->numeric()
                ->prefix('Rp')
                ->required(),

            Forms\Components\Section::make('Penyusutan')
                ->description('Menurut kelompok harta pajak. Dihitung mulai bulan pembelian, tanpa nilai sisa.')
                ->schema([
                    Forms\Components\Select::make('kelompok_harta')
                        ->label('Kelompok harta')
                        ->options(AssetDepreciation::groupOptions())
                        ->live()
                        ->helperText(fn (Get $get) => isset(AssetDepreciation::GROUPS[$get('kelompok_harta')])
                            ? 'Contoh: ' . AssetDepreciation::GROUPS[$get('kelompok_harta')]['hint'] . '.'
                            : 'Komputer, laptop, monitor, kamera: Kelompok 1. Furnitur kantor, AC, kendaraan: Kelompok 2.'),

                    Forms\Components\Select::make('metode_penyusutan')
                        ->label('Metode')
                        ->options(AssetDepreciation::METHODS)
                        ->default('garis_lurus')
                        ->selectablePlaceholder(false)
                        ->required()
                        ->helperText('Garis lurus: sama besar tiap tahun. Saldo menurun: besar di awal. Bangunan selalu garis lurus.'),

                    Forms\Components\DatePicker::make('tanggal_dilepas')
                        ->label('Dijual / dibuang pada')
                        ->afterOrEqual('tanggal_pembelian')
                        ->helperText('Kosongkan selama aset masih dimiliki. Penyusutan berhenti setelah bulan ini.'),
                ])
                ->columns(3)
                ->columnSpanFull(),
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
                Tables\Columns\TextColumn::make('uuid')->label('ID Barang')->searchable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('nama_barang')->label('Nama Barang')->searchable(),
                Tables\Columns\TextColumn::make('tanggal_pembelian')->label('Tanggal Pembelian')->date(),
                Tables\Columns\TextColumn::make('pembelian_dari')->label('Pembelian Dari')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('kondisi')->label('Kondisi'),
                Tables\Columns\TextColumn::make('user.firstname')
                ->label('Penanggung Jawab')
                ->sortable()
                ->searchable()
                ->alignment('center'),
                Tables\Columns\TextColumn::make('harga_barang')->label('Harga')->rupiah(),
                Tables\Columns\TextColumn::make('kelompok_harta')
                    ->label('Kelompok')
                    ->formatStateUsing(fn (?string $state) => AssetDepreciation::GROUPS[$state]['label'] ?? 'Belum diisi')
                    ->default('Belum diisi')
                    ->badge()
                    ->color(fn (?string $state) => isset(AssetDepreciation::GROUPS[$state]) ? 'gray' : 'warning')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('nilai_buku')
                    ->label('Nilai buku')
                    ->state(fn (Asset $record) => app(AssetDepreciation::class)->at($record)['book'])
                    ->formatStateUsing(fn ($state) => Money::rupiah($state))
                    ->description(fn (Asset $record) => self::depreciationNote(app(AssetDepreciation::class)->at($record)))
                    ->alignEnd(),
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

                SelectFilter::make('kelompok_harta')
                    ->label('Kelompok harta')
                    ->options(AssetDepreciation::groupOptions()),
            ])
            ->actions([
                Tables\Actions\Action::make('penyusutan')
                    ->label('Penyusutan')
                    ->icon('heroicon-o-calculator')
                    ->color('gray')
                    ->modalHeading(fn (Asset $record) => 'Penyusutan ' . $record->nama_barang)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->modalContent(function (Asset $record) {
                        $depreciation = app(AssetDepreciation::class);
                        $state        = $depreciation->at($record);

                        return view('filament.resources.asset-resource.schedule', [
                            'state' => $state,
                            'rows'  => $depreciation->yearly($record),
                            'note'  => self::depreciationNote($state, long: true)
                                . ' ' . (AssetDepreciation::GROUPS[$record->kelompok_harta]['label'] ?? '')
                                . (isset(AssetDepreciation::GROUPS[$record->kelompok_harta]) ? ', ' . strtolower(AssetDepreciation::METHODS[$record->metode_penyusutan] ?? '') . '.' : ''),
                        ]);
                    }),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Keterangan singkat di bawah nilai buku.
     */
    public static function depreciationNote(array $state, bool $long = false): string
    {
        return match ($state['status']) {
            'tanpa_kelompok'   => $long ? 'Kelompok harta belum diisi, jadi penyusutannya belum dihitung. Isi lewat Edit.' : 'Isi kelompok harta',
            'tidak_disusutkan' => 'Tidak disusutkan',
            'belum_dibeli'     => 'Belum dibeli',
            'dilepas'          => 'Sudah dijual / dibuang',
            'habis'            => 'Sudah habis disusutkan',
            default            => 'Susut ' . Money::rupiah($state['monthly']) . '/bulan'
                . ($long ? ', sisa ' . $state['remaining_months'] . ' bulan.' : ''),
        };
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
