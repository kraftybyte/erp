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
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;


class ContractResource extends Resource
{
    protected static ?string $model = Contract::class;
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    public static function getNavigationLabel(): string
    {
        return \App\Helpers\NavigationHelper::getLabel('contract', 'Contract');
    }
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
                        TextInput::make('contract_number')
                            ->label('Nomor Kontrak')
                            ->default(fn () => Contract::generateContractNumber())
                            ->readonly()
                            ->helperText('Otomatis generated. Ubah jika perlu.'),

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

                // Section 4: Scope of Work (Hierarchical)
                Forms\Components\Section::make('Ruang Lingkup Pekerjaan')
                    ->icon('heroicon-o-sparkles')
                    ->description('Tabel rincian ruang lingkup pekerjaan dengan kategori dan sub-item')
                    ->schema([
                        Textarea::make('project_description')
                            ->label('Deskripsi Project')
                            ->rows(2)
                            ->placeholder('Deskripsi singkat project yang akan dikerjakan')
                            ->helperText('Contoh: Digital Ads Management Funnel untuk meningkatkan brand awareness')
                            ->columnSpanFull(),

                        // Main SOW Categories Repeater
                        Forms\Components\Repeater::make('sow_categories')
                            ->label('Kategori Ruang Lingkup')
                            ->defaultItems(0)
                            ->collapsible()
                            ->collapsed(false)
                            ->mutateRelationshipDataBeforeFillUsing(function ($record): array {
                                // Load sowCategories with children if not already loaded
                                if (!$record->relationLoaded('sowCategories')) {
                                    $record->load(['sowCategories' => function ($query) {
                                        $query->with('children')->orderBy('sort_order');
                                    }]);
                                }

                                // Convert to repeater format
                                return $record->sowCategories->map(function ($category) {
                                    return [
                                        'item' => $category->item,
                                        'children' => $category->children->map(function ($child) {
                                            return [
                                                'item' => $child->item,
                                                'description' => $child->description,
                                            ];
                                        })->toArray(),
                                    ];
                                })->toArray();
                            })
                            ->mutateRelationshipDataBeforeSaveUsing(function (array $data): array {
                                // Store for afterCreate/afterSave hook
                                if (isset($data['sow_categories'])) {
                                    $data['_sow_categories'] = $data['sow_categories'];
                                }
                                return $data;
                            })
                            ->schema([
                                // Category Header
                                TextInput::make('item')
                                    ->label('Nama Kategori')
                                    ->placeholder('Contoh: Social Media Management, Digital Ads, SEO, dll')
                                    ->required()
                                    ->columnSpanFull(),

                                // Sub-items nested under this category
                                Forms\Components\Repeater::make('children')
                                    ->label('Sub-Item')
                                    ->defaultItems(0)
                                    ->collapsible()
                                    ->collapsed(true)
                                    ->schema([
                                        TextInput::make('item')
                                            ->label('Nama Item')
                                            ->placeholder('Contoh: Produksi 20 materi/bulan')
                                            ->required()
                                            ->columnSpanFull(),

                                        Textarea::make('description')
                                            ->label('Deskripsi / Rincian')
                                            ->rows(2)
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(1),
                            ])
                            ->columns(1),
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

                        Select::make('payment_type')
                            ->label('Tipe Pembayaran')
                            ->options([
                                'full' => 'Lunas (Bayar Sekaligus)',
                                'dp_monthly' => 'DP + Bayar Per Bulan',
                                'monthly' => 'Cicilan Per Bulan (Tanpa DP)',
                            ])
                            ->default('full')
                            ->live(),

                        // DP + Monthly Payment
                        Grid::make(3)
                            ->schema([
                                TextInput::make('down_payment_percent')
                                    ->label('DP (%)')
                                    ->numeric()
                                    ->suffix('%')
                                    ->minValue(1)
                                    ->maxValue(100)
                                    ->default(30)
                                    ->visible(fn (callable $get) => $get('payment_type') === 'dp_monthly'),

                                TextInput::make('remaining_payment_percent')
                                    ->label('Sisa')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->visible(fn (callable $get) => $get('payment_type') === 'dp_monthly')
                                    ->placeholder(fn (callable $get) => (100 - ($get('down_payment_percent') ?? 30)) . '%'),

                                TextInput::make('installment_months')
                                    ->label('Bayar Per (Bulan)')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(60)
                                    ->placeholder(fn (callable $get) => $get('duration_months') ?? 3)
                                    ->helperText('Jumlah bulan untuk pembayaran')
                                    ->visible(fn (callable $get) => in_array($get('payment_type'), ['dp_monthly', 'monthly'])),
                            ]),
                    ]),

                // Section 8: Lampiran - Table Items
                Forms\Components\Section::make('Lampiran - Rincian Biaya')
                    ->icon('heroicon-o-table-cells')
                    ->schema([
                        TextInput::make('lampiran_title')
                            ->label('Judul Lampiran')
                            ->default('Lampiran 1 — Rincian Biaya Jasa')
                            ->columnSpanFull(),

                        // Tabel A - Fee Items
                        Grid::make(2)
                            ->schema([
                                TextInput::make('table_a_title')
                                    ->label('Judul Tabel A')
                                    ->default('Tabel A'),

                                TextInput::make('table_b_title')
                                    ->label('Judul Tabel a')
                                    ->default('Tabel a'),
                            ]),

                        // Tabel A - Fee Items
                        Forms\Components\Placeholder::make('table_a_placeholder')
                            ->label('Tabel A - Biaya Jasa')
                            ->content('Klik tombol "+ Tambah" untuk menambah item biaya')
                            ->columnSpanFull(),

                        Forms\Components\Repeater::make('fee_items')
                            ->label('Item Biaya (Tabel A)')
                            ->relationship('feeItems')
                            ->schema([
                                TextInput::make('item')
                                    ->label('Nama Item')
                                    ->required(),
                                TextInput::make('amount')
                                    ->label('Jumlah')
                                    ->numeric()
                                    ->prefix('Rp '),
                                TextInput::make('due_date')
                                    ->label('Jatuh Tempo'),
                            ])
                            ->columns(3)
                            ->defaultItems(1),

                        // Tabel a - Third Party (toggle)
                        Toggle::make('has_third_party')
                            ->label('Aktifkan Tabel a (Biaya Pihak Ketiga)')
                            ->default(false)
                            ->reactive()
                            ->afterStateUpdated(function ($state, callable $set) {
                                if (!$state) {
                                    $set('third_party_items', []);
                                }
                            }),

                        Forms\Components\Placeholder::make('table_b_placeholder')
                            ->label('Tabel a - Biaya Pihak Ketiga')
                            ->content('Klik tombol "+ Tambah" untuk menambah item pihak ketiga')
                            ->columnSpanFull()
                            ->hidden(function (callable $get) {
                                return !$get('has_third_party');
                            }),

                        Forms\Components\Repeater::make('third_party_items')
                            ->label('Item Pihak Ketiga (Tabel a)')
                            ->relationship('thirdPartyItems')
                            ->schema([
                                TextInput::make('item')
                                    ->label('Nama Item'),
                                TextInput::make('amount')
                                    ->label('Jumlah')
                                    ->numeric()
                                    ->prefix('Rp '),
                                Textarea::make('notes')
                                    ->label('Keterangan')
                                    ->rows(2),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->hidden(function (callable $get) {
                                return !$get('has_third_party');
                            }),
                    ]),

                // Section 9: Additional Terms
                Forms\Components\Section::make('Ketentuan Tambahan')
                    ->icon('heroicon-o-document-text')
                    ->collapsed()
                    ->schema([
                        MarkdownEditor::make('content')
                            ->label('Klausul / Catatan Tambahan')
                            ->columnSpanFull()
                            ->helperText('Ketentuan tambahan yang akan muncul di Pasal 11'),

                        Forms\Components\Toggle::make('force_majeure_extended')
                            ->label('Force Majeure Extended')
                            ->default(false)
                            ->helperText('Aktifkan untuk menambahkan klausul Force Majeure yang lebih detail'),

                        Grid::make(2)
                            ->schema([
                                TextInput::make('termination_notice_days')
                                    ->label('Hari Pemberitahuan Terminasi')
                                    ->numeric()
                                    ->default(30)
                                    ->suffix('hari')
                                    ->helperText('Jumlah hari pemberitahuan sebelum terminasi'),

                                Textarea::make('penalty_clause')
                                    ->label('Klausul Denda')
                                    ->rows(2)
                                    ->placeholder('Ketentuan denda jika melanggar perjanjian')
                                    ->helperText('Contoh: Denda 5% dari total kontrak per hari keterlambatan'),
                            ]),
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
            'create' => Pages\CreateContract::route('/create'),
            'edit' => Pages\EditContract::route('/{record}/edit'),
            'view' => Pages\ViewContract::route('/{record}'),
        ];
    }
}