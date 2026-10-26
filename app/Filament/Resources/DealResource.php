<?php

namespace App\Filament\Resources;

use App\Helpers\ColorHelper;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\Deal;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use App\Filament\Resources\DealResource\Pages;
use App\Filament\Resources\DealResource\RelationManagers\InvoicesRelationManager;
use App\Filament\Resources\DealResource\RelationManagers\QuotationsRelationManager;
use App\Filament\Resources\DealResource\RelationManagers\ProjectRelationManager;
use App\Filament\Resources\DealResource\RelationManagers\TaskRelationManager;
use App\Filament\Resources\DealResource\RelationManagers\ContractsRelationManager;
use Illuminate\Database\Eloquent\Builder;

class DealResource extends Resource
{
    protected static ?string $model = Deal::class;
    protected static ?string $navigationIcon = 'heroicon-o-briefcase';
    protected static ?int $navigationSort = -2;
    protected static ?string $recordTitleAttribute = 'nama_perusahaan';

    public static function getNavigationLabel(): string
    {
        return trans('Client');
    }

    public static function getNavigationGroup(): ?string
    {
        return __("CRM");
    }

    /* ================= FORM ================= */

    public static function form(Form $form): Form
    {
        return $form->schema([

            Forms\Components\Section::make('Informasi Perusahaan')
                ->columns(2)
                ->schema([
                    /*
                    | Perusahaan & PIC dipilih dari data induk (menu Perusahaan).
                    | Teks nama_perusahaan/nama_pic tetap terisi otomatis dari
                    | pilihan ini (lihat CompanyDirectory::syncDeal), sehingga
                    | quotation, invoice, dan PDF tetap membacanya seperti biasa.
                    */
                    Forms\Components\Select::make('company_id')
                        ->label('Perusahaan')
                        ->relationship('company', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->createOptionForm(CompanyResource::companyFields())
                        ->live()
                        ->afterStateUpdated(function ($state, Set $set, Get $get) {
                            $set('contact_id', null);

                            $company = Company::find($state);

                            if ($company && (blank($get('alamat')) || $get('alamat') === '-') && filled($company->alamat)) {
                                $set('alamat', $company->alamat);
                            }

                            if ($company && blank($get('NPWP')) && filled($company->npwp)) {
                                $set('NPWP', $company->npwp);
                            }
                        }),

                    Forms\Components\Select::make('contact_id')
                        ->label('PIC untuk order ini')
                        ->relationship('contact', 'name', fn (Builder $query, Get $get) => $query->where('company_id', $get('company_id')))
                        ->searchable()
                        ->preload()
                        ->placeholder('Tanpa PIC (klien pribadi)')
                        ->helperText('Satu perusahaan bisa punya PIC berbeda di setiap order.')
                        ->disabled(fn (Get $get) => blank($get('company_id')))
                        ->createOptionForm(CompanyResource::contactFields())
                        ->createOptionUsing(fn (array $data, Get $get) => CompanyContact::create($data + ['company_id' => $get('company_id')])->getKey())
                        ->rules([fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                            if (filled($value) && ! CompanyContact::whereKey($value)->where('company_id', $get('company_id'))->exists()) {
                                $fail('PIC ini bukan milik perusahaan yang dipilih.');
                            }
                        }])
                        ->live()
                        ->afterStateUpdated(function ($state, Set $set) {
                            $contact = CompanyContact::find($state);

                            if (filled($contact?->telephone)) {
                                $set('telephone', $contact->telephone);
                            }

                            if (filled($contact?->email)) {
                                $set('email', $contact->email);
                            }
                        }),

                    Forms\Components\TextInput::make('email')
                        ->email()
                        ->maxLength(255)
                        // Dulu default '-', yang ditolak aturan email sehingga form tidak
                        // bisa disimpan sebelum kolom ini dikosongkan. Kosong tetap disimpan '-'.
                        ->dehydrateStateUsing(fn ($state) => blank($state) ? '-' : $state)
                        ->rules([
                            'nullable',
                            'email',
                        ]),

                    Forms\Components\TextInput::make('telephone')
                        ->required()
                        ->tel()
                        ->maxLength(255)
                        ->telRegex('/^[+]*[(]{0,1}[0-9]{1,4}[)]{0,1}[-\s\.\/0-9]*$/'),

                    Forms\Components\TextInput::make('NPWP')
                        ->label('No. NPWP'),

                    Forms\Components\Textarea::make('alamat')
                    ->default('-')
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Informasi Deal')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('product_id')
                        ->label('Layanan')
                        ->relationship('product', 'nama_layanan')
                        ->searchable()
                        ->multiple(),

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
                        ->label('Status Deal')
                        ->required()
                        ->options([
                            'Negosiasi' => 'Negosiasi',
                            'Menunggu Pembayaran' => 'Menunggu Pembayaran',
                            'Dalam Tahap Project' => 'Dalam Tahap Project',
                            'Selesai' => 'Selesai',
                            'Hold' => 'Hold',
                        ]),
                ]),

          Forms\Components\Section::make('Catatan')
                    ->schema([
                        Forms\Components\MarkdownEditor::make('notes')
                            ->helperText('Catatan untuk customer ini.')
                            ->default('-')
                            ->maxLength(500),
                    ]),
        ]);
    }

    /* ================= TABLE ================= */

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn(Builder $query) => $query->with(['product']))
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('Deals ID')
                    ->sortable()
                    ->weight('bold'),

                    
                Tables\Columns\TextColumn::make('nama_perusahaan')
                    ->searchable(['nama_perusahaan', 'nama_pic'])
                    ->weight('medium')
                    ->limit(25)
                    ->formatStateUsing(function ($state, $record) {
                        $namaPic = ($record->nama_pic === '-' || blank($record->nama_pic))
                            ? '(Pribadi)'
                            : $record->nama_pic;

                        return $namaPic . ' - ' . $record->nama_perusahaan;
                    })
                    ->tooltip(function ($record) {
                        $namaPic = ($record->nama_pic === '-' || blank($record->nama_pic))
                            ? '(Pribadi)'
                            : $record->nama_pic;

                        return $namaPic . ' - ' . $record->nama_perusahaan;
                    }),
                    
                Tables\Columns\TextColumn::make('product.nama_layanan')
                    ->badge()
                    ->label('Layanan'),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn ($record) => ColorHelper::dealStatus($record->status)),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Tanggal Deal')
                    ->dateTime('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])

            ->filters([
                // Semua order satu perusahaan, dari PIC mana pun.
                Tables\Filters\SelectFilter::make('company_id')
                    ->label('Perusahaan')
                    ->relationship('company', 'name')
                    ->searchable(),
            ])

            ->actions([

                Tables\Actions\Action::make('status_change')
                    ->tooltip('Change Status')
                    ->icon('heroicon-m-arrows-right-left')
                    ->iconButton()
                    ->color('info')
                    ->modalHeading('Change Status')
                    ->modalWidth('lg')
                    ->form([
                        Forms\Components\Select::make('status')
                            ->label('Update Status')
                            ->required()
                            ->options([
                                'Negosiasi' => 'Negosiasi',
                                'Menunggu Pembayaran' => 'Menunggu Pembayaran',
                                'Dalam Tahap Project' => 'Dalam Tahap Project',
                                'Selesai' => 'Selesai',
                                'Hold' => 'Hold',
                            ]),
                    ])
                    ->action(fn (Deal $record, array $data) =>
                        $record->update(['status' => $data['status']])
                    ),

                Tables\Actions\Action::make('add_notes')
                    ->tooltip('Add Notes')
                    ->icon('heroicon-m-information-circle')
                    ->iconButton()
                    ->color('info')
                    ->modalHeading('Add Notes')
                    ->modalWidth('lg')
                    ->form([
                        Forms\Components\MarkdownEditor::make('notes')
                            ->helperText('Tambah catatan untuk data customer ini')
                            ->maxLength(500),
                    ])
                    ->action(fn (Deal $record, array $data) =>
                        $record->update(['notes' => $data['notes']])
                    ),

                Tables\Actions\ViewAction::make()->tooltip('Detail'),
                Tables\Actions\EditAction::make()->tooltip('Edit'),
                Tables\Actions\DeleteAction::make()->tooltip('Delete'),
            ])

            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    /* ================= RELATIONS ================= */

    public static function getRelations(): array
    {
        return [
            InvoicesRelationManager::class,
            QuotationsRelationManager::class,
            ProjectRelationManager::class,
            TaskRelationManager::class,
            ContractsRelationManager::class,
        ];
    }

    /* ================= PAGES ================= */

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeals::route('/'),
            'create' => Pages\CreateDeal::route('/create'),
            'edit' => Pages\EditDeal::route('/{record}/edit'),
            'view' => Pages\ViewDeals::route('/{record}'),
        ];
    }
}
