<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ContractResource\Pages;
use App\Models\Contract;
use App\Models\Deal;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;


class ContractResource extends Resource
{
    protected static ?string $model = Contract::class;
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationLabel = 'Contracts';
    protected static ?int $navigationSort = 8;

    public static function getNavigationGroup(): ?string
    {
        return __('CRM');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // Section 1: Select Deal
                Forms\Components\Section::make('Pilih Klien')
                    ->icon('heroicon-o-building-office')
                    ->description('Pilih klien dari deal yang ada. Informasi akan otomatis terisi.')
                    ->schema([
                        Select::make('deal_id')
                            ->label('Nama Klien')
                            ->relationship('deal', 'nama_perusahaan')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(function ($state, callable $set) {
                                if ($state) {
                                    $deal = Deal::with('product')->find($state);
                                    if ($deal) {
                                        // Auto-fill client info from deal
                                        $set('client_name_full', $deal->nama_perusahaan);
                                        $set('client_address', $deal->alamat);
                                        $set('client_phone', $deal->telephone);
                                        $set('client_email', $deal->email);

                                        // Auto-fill products - combine all product details
                                        $productLines = [];
                                        foreach ($deal->product as $product) {
                                            $line = $product->nama_layanan;
                                            if ($product->detail_layanan) {
                                                $line .= "\n" . $product->detail_layanan;
                                            }
                                            $productLines[] = $line;
                                        }
                                        if (!empty($productLines)) {
                                            $set('scope_of_work', implode("\n\n", $productLines));
                                        }
                                    }
                                }
                            }),
                    ]),

                // Section 2: Client Info (auto-filled from deal)
                Forms\Components\Section::make('Informasi Klien')
                    ->icon('heroicon-o-user')
                    ->collapsed()
                    ->schema([
                        TextInput::make('client_name_full')
                            ->label('Nama Klien / Perusahaan'),

                        Textarea::make('client_address')
                            ->label('Alamat Klien')
                            ->rows(2),

                        TextInput::make('client_phone')
                            ->label('No. Telepon Klien')
                            ->tel(),

                        TextInput::make('client_email')
                            ->label('Email Klien')
                            ->email(),
                    ]),

                // Section 3: Contract Type
                Forms\Components\Section::make('Tipe Kontrak')
                    ->icon('heroicon-o-document')
                    ->schema([
                        Select::make('type')
                            ->label('Format Kontrak')
                            ->options([
                                'editor' => 'Generated HTML (Otomatis dari data)',
                                'file' => 'Upload File PDF',
                            ])
                            ->required()
                            ->default('editor')
                            ->live(),
                    ]),

                // Section 4: Services (auto-filled from products)
                Forms\Components\Section::make('Ruang Lingkup Pekerjaan')
                    ->icon('heroicon-o-sparkles')
                    ->description('Otomatis terisi dari produk yang dipilih di Deal')
                    ->schema([
                        Textarea::make('scope_of_work')
                            ->label('Detail Layanan')
                            ->rows(5)
                            ->helperText('Akan otomatis terisi dari produk deal. Bisa diedit manual jika perlu.'),
                    ]),

                // Section 5: Details
                Forms\Components\Section::make('Detail Kontrak')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('platform')
                                    ->label('Platform')
                                    ->placeholder('Instagram, Facebook, TikTok'),

                                TextInput::make('content_count')
                                    ->label('Jumlah Konten/Bulan')
                                    ->numeric()
                                    ->default(20),
                            ]),

                        Grid::make(2)
                            ->schema([
                                TextInput::make('max_revisions')
                                    ->label('Maksimal Revisi')
                                    ->numeric()
                                    ->default(3),

                                TextInput::make('duration_months')
                                    ->label('Durasi (Bulan)')
                                    ->numeric()
                                    ->default(3)
                                    ->reactive()
                                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                        if ($state && $get('effective_date')) {
                                            $start = \Carbon\Carbon::parse($get('effective_date'));
                                            $set('expiration_date', $start->addMonths((int)$state)->format('Y-m-d'));
                                        }
                                    }),
                            ]),
                    ]),

                // Section 6: Schedule
                Forms\Components\Section::make('Jadwal Kontrak')
                    ->icon('heroicon-o-calendar-days')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                DatePicker::make('effective_date')
                                    ->label('Tanggal Mulai')
                                    ->required()
                                    ->closeOnDateSelection()
                                    ->reactive()
                                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                        if ($state && $get('duration_months')) {
                                            $start = \Carbon\Carbon::parse($state);
                                            $set('expiration_date', $start->addMonths((int)$get('duration_months'))->format('Y-m-d'));
                                        }
                                    }),

                                DatePicker::make('expiration_date')
                                    ->label('Tanggal Berakhir')
                                    ->required()
                                    ->closeOnDateSelection()
                                    ->minDate(fn (callable $get) => $get('effective_date')),
                            ]),
                    ]),

                // Section 7: Pricing
                Forms\Components\Section::make('Pembayaran')
                    ->icon('heroicon-o-banknotes')
                    ->schema([
                        TextInput::make('total_value')
                            ->label('Total Nilai Kontrak')
                            ->numeric()
                            ->prefix('Rp ')
                            ->required(),

                        Grid::make(2)
                            ->schema([
                                TextInput::make('down_payment_percent')
                                    ->label('DP (%)')
                                    ->numeric()
                                    ->suffix('%')
                                    ->default(50),

                                TextInput::make('remaining_payment_percent')
                                    ->label('Sisa Pembayaran')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->placeholder(fn (callable $get) => 'Sisa: ' . (100 - ($get('down_payment_percent') ?? 50)) . '%'),
                            ]),
                    ]),

                // Section 8: Notes
                Forms\Components\Section::make('Catatan Tambahan')
                    ->icon('heroicon-o-document-text')
                    ->collapsed()
                    ->schema([
                        MarkdownEditor::make('content')
                            ->label('Klausul / Catatan Tambahan')
                            ->columnSpanFull(),
                    ]),

                // Section 9: Signature
                Forms\Components\Section::make('Tanda Tangan')
                    ->icon('heroicon-o-pencil-square')
                    ->description('Nama penanda tangan yang akan muncul di dokumen kontrak')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('client_signatory_name')
                                    ->label('Nama Penanda Tangan Klien')
                                    ->placeholder('Contoh: Budi Santoso'),

                                Forms\Components\TextInput::make('client_signatory_title')
                                    ->label('Jabatan Klien')
                                    ->placeholder('Contoh: Direktur Utama'),
                            ]),

                        Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('agency_signatory_name')
                                    ->label('Nama Penanda Tangan Agency')
                                    ->placeholder('Contoh: Anita Wijaya'),

                                Forms\Components\TextInput::make('agency_signatory_title')
                                    ->label('Jabatan Agency')
                                    ->placeholder('Contoh: Chief Executive Officer'),
                            ]),
                    ]),

                // Section 10: File (if type = file)
                Forms\Components\Section::make('File Kontrak')
                    ->icon('heroicon-o-paper-clip')
                    ->collapsed()
                    ->visible(fn (callable $get) => $get('type') === 'file')
                    ->schema([
                        FileUpload::make('file_path')
                            ->label('Upload File PDF')
                            ->directory('contracts/' . now()->format('Y-m'))
                            ->preserveFilenames()
                            ->downloadable()
                            ->openable()
                            ->acceptedFileTypes([
                                'application/pdf',
                                'application/msword',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            ])
                            ->maxSize(10240) // 10MB
                            ->validationMessages([
                                'max' => 'Ukuran file maksimal 10MB.',
                                'mimes' => 'Format file harus PDF atau DOCX.',
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn(Builder $query) => $query->with(['deal']))
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('deal.nama_perusahaan')
                    ->searchable()
                    ->label('Klien')
                    ->limit(25)
                    ->tooltip(fn ($record) => $record->deal?->nama_perusahaan),

                Tables\Columns\TextColumn::make('client_name_full')
                    ->label('Nama Kontrak')
                    ->limit(20)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\BadgeColumn::make('type')
                    ->label('Tipe')
                    ->color(fn ($record) => match ($record->type) {
                        'editor' => 'info',
                        'file' => 'warning',
                        default => 'secondary',
                    }),

                Tables\Columns\TextColumn::make('duration_months')
                    ->label('Durasi')
                    ->formatStateUsing(fn ($state) => ($state ?? 0) . ' bulan')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_value')
                    ->label('Nilai')
                    ->formatStateUsing(fn ($state) => $state ? 'Rp ' . number_format($state, 0, ',', '.') : '-')
                    ->sortable(),

                Tables\Columns\TextColumn::make('effective_date')
                    ->date('d/m/Y')
                    ->label('Berlaku')
                    ->sortable(),

                Tables\Columns\TextColumn::make('expiration_date')
                    ->date('d/m/Y')
                    ->label('Berakhir')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d/m/Y H:i')
                    ->label('Dibuat')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'editor' => 'Editor',
                        'file' => 'File Upload',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (Contract $record) => route('filament.admin.resources.contracts.preview', $record))
                    ->openUrlInNewTab(),
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListContracts::route('/'),
            'view' => Pages\ViewContract::route('/{record}'),
            'create' => Pages\CreateContract::route('/create'),
            'edit' => Pages\EditContract::route('/{record}/edit'),
        ];
    }
}