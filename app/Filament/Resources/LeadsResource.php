<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LeadsResource\Pages;
use App\Helpers\ColorHelper;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use App\Models\Leads;
use App\Models\Deal;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Builder;




class LeadsResource extends Resource
{
    protected static ?string $model = Leads::class;
    protected static ?int $navigationSort = -3;
    protected static ?string $navigationIcon = 'heroicon-s-cursor-arrow-ripple';

    protected static ?string $recordTitleAttribute = 'nama_pic';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('nama_pic')
                ->required()
                ->maxLength(255),

                Forms\Components\TextInput::make('nama_perusahaan')
                ->required()
                ->maxLength(255),

                Forms\Components\TextInput::make('email')
                ->required()
                ->maxLength(255),

                Forms\Components\TextInput::make('telephone')
                ->required()
                ->maxLength(255)
                ->tel()
                ->telRegex('/^[+]*[(]{0,1}[0-9]{1,4}[)]{0,1}[-\s\.\/0-9]*$/'),

                Forms\Components\Select::make('source')
                ->required()
                ->options([
                    'Website' => 'Website',
                    'Social Media' => 'Social Media',
                    'Email' => 'Email Marketing',
                    'Event' => 'Event atau Aktivitas Offline',
                    'Referral' => 'Referral',
                    'Partner' => 'Partner',
                ]),
                Forms\Components\Select::make('status')
                ->label ('Status Leads')
                ->required()
                ->options([
                    'Dalam Komunikasi' => 'Dalam Komunikasi',
                    'Proposal Sudah Dikirim' => 'Proposal Sudah Dikirim',
                    'Menang' => 'Menang',
                    'Kalah' => 'Kalah',
                ]),

                Forms\Components\Select::make('product_id')
                    ->label('Layanan')
                    ->relationship('product', 'nama_layanan')
                    ->searchable()
                    ->multiple()
                    ->required(),
            ]);
    }


    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn(Builder $query) => $query->with(['product']))
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->searchable()
                    ->sortable()
                    ->label('Leads ID'),
                Tables\Columns\TextColumn::make('nama_pic')
                    ->searchable()
                    ->label('Nama PIC'),
                Tables\Columns\TextColumn::make('nama_perusahaan')
                    ->searchable()
                    ->label('Nama Perusahaan'),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->label('Email'),
                Tables\Columns\TextColumn::make('telephone')
                    ->searchable()
                    ->label('No. Telphone'),
                Tables\Columns\TextColumn::make('status')
                    ->searchable()
                    ->badge()
                    ->color(fn ($record) => ColorHelper::leadsStatus($record->status)),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d/m/Y')
                    ->label('Tanggal Dibuat')
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                //
            ])
            ->filters([
                //
            ])
            ->actions([

                // Tambahkan action untuk replikasi data ke Deals
                Tables\Actions\Action::make('Convert')
                    ->modalHeading('Convert to Deal')
                    ->color('success')
                    ->tooltip('Convert to Deals')
                    ->icon('heroicon-m-check')
                    ->iconButton()
                    ->modalWidth('lg')
                    ->form(function (Form $form) {
                        return $form
                            ->schema([
                                Forms\Components\Textarea::make('alamat')
                                    ->label('Tambah Alamat Lengkap')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('NPWP')
                                    ->label('Tambah No. NPWP'),
                                Forms\Components\Select::make('status')
                                    ->label('Deal Status')
                                    ->required()
                                    ->options([
                                        'Negosiasi' => 'Negosiasi',
                                        'Menunggu Pembayaran' => 'Menunggu Pembayaran',
                                        'Dalam Tahap Project' => 'Dalam Tahap Project',
                                        'Pending' => 'Pending',
                                    ]),
                            ]);
                    })
                    ->action(function (Leads $record, array $data) {

                        $deal = Deal::create([
                            'alamat' => $data['alamat'],
                            'NPWP' => $data['NPWP'],
                            'status' => $data['status'],
                            'lead_id' => $record->id,
                            'nama_pic' => $record->nama_pic,
                            'nama_perusahaan' => $record->nama_perusahaan,
                            'email' => $record->email,
                            'source' => $record->source,
                            'telephone' => $record->telephone,
                        ]);

                        $deal->product()->attach($record->product->pluck('id')->toArray());
                        $record->update([
                            'is_converted' => true,
                            'status' => 'Converted',
                        ]);
                    })
                    ->visible(fn (Leads $record) => !$record->is_converted),

                // Action untuk perubahan status
                Tables\Actions\Action::make('status_change')
                    ->modalHeading('Change Status')
                    ->color('info')
                    ->tooltip('Change Status')
                    ->icon('heroicon-m-arrows-right-left')
                    ->iconButton()
                    ->modalWidth('lg')
                    ->form(function (Form $form) {
                        return $form
                            ->schema([
                                Forms\Components\Select::make('status')
                                    ->label('Update Status')
                                    ->required()
                                    ->options([
                                        'Dalam Komunikasi' => 'Dalam Komunikasi',
                                        'Proposal Sudah Dikirim' => 'Proposal Sudah Dikirim',
                                        'Menang' => 'Menang',
                                        'Kalah' => 'Kalah',
                                        'Converted' => 'Converted',
                                    ]),
                            ]);
                    })
                    ->action(function (Leads $record, array $data) {
                        $record->update([
                            'status' => $data['status'],
                        ]);
                        session()->flash('success', 'Leads successfully Change Status!');
                    }),


                Tables\Actions\ViewAction::make()->hiddenLabel()->tooltip('Detail'),
                Tables\Actions\EditAction::make()->hiddenLabel()->tooltip('Edit'),
                Tables\Actions\DeleteAction::make()->hiddenLabel()->tooltip('Delete'),

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
            'index' => Pages\ListLeads::route('/'),
            'create' => Pages\CreateLeads::route('/create'),
            'edit' => Pages\EditLeads::route('/{record}/edit'),
            'view' => Pages\ViewLeads::route('/{record}'),
        ];
    }
}
