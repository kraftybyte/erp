<?php

namespace App\Filament\Resources\ContractResource\Pages;

use App\Filament\Resources\ContractResource;
use App\Filament\Resources\ContractResource\Pages\Concerns\HasContractPreview;
use App\Models\ContractItem;
use Filament\Forms;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateContract extends CreateRecord
{
    use HasContractPreview;

    protected static string $resource = ContractResource::class;

    protected static string $view = 'filament.resources.contracts.form-with-preview';

    /** @var array|null */
    protected ?array $sowCategoriesData = null;

    public function form(Forms\Form $form): Forms\Form
    {
        return $form
            // Setiap perubahan isian memperbarui pratinjau kontrak di samping form.
            ->live(debounce: 600)
            ->schema([
                // Section 1: Contract Info
                Section::make('Informasi Kontrak')
                    ->icon('heroicon-o-document')
                    ->schema([
                        TextInput::make('contract_number')
                            ->label('Nomor Kontrak')
                            ->default(ContractResource::getModel()::generateContractNumber())
                            ->required(),
                        Grid::make(2)
                            ->schema([
                                DatePicker::make('effective_date')
                                    ->label('Tanggal Efektif')
                                    ->required()
                                    ->default(now()),
                                DatePicker::make('expiration_date')
                                    ->label('Tanggal Berakhir'),
                            ]),
                        Select::make('deal_id')
                            ->label('Deal')
                            ->relationship('deal', 'nama_perusahaan')
                            ->searchable()
                            ->preload()
                            ->afterStateUpdated(function ($state, callable $set) {
                                if ($state) {
                                    $deal = \App\Models\Deal::find($state);
                                    if ($deal) {
                                        $set('client_name_full', $deal->nama_perusahaan);
                                    }
                                }
                            }),
                    ]),

                // Section 2: Client Info
                Section::make('Informasi Klien')
                    ->icon('heroicon-o-user')
                    ->collapsed()
                    ->schema([
                        TextInput::make('client_name_full')
                            ->label('Nama Perusahaan (Full)')
                            ->required(),
                        Textarea::make('client_address')
                            ->label('Alamat')
                            ->rows(2),
                        Grid::make(2)
                            ->schema([
                                TextInput::make('client_phone')
                                    ->label('Telepon'),
                                TextInput::make('client_email')
                                    ->label('Email'),
                            ]),
                        Grid::make(2)
                            ->schema([
                                TextInput::make('client_signatory_name')
                                    ->label('Nama Penanda Tangan'),
                                TextInput::make('client_signatory_title')
                                    ->label('Jabatan Penanda Tangan'),
                            ]),
                    ]),

                // Section 3: Project Description
                Section::make('Deskripsi Project')
                    ->icon('heroicon-o-briefcase')
                    ->collapsed()
                    ->schema([
                        Textarea::make('project_description')
                            ->label('Deskripsi Project')
                            ->rows(2)
                            ->placeholder('Deskripsi singkat project yang akan dikerjakan')
                            ->helperText('Contoh: Digital Ads Management Funnel untuk meningkatkan brand awareness')
                            ->columnSpanFull(),
                    ]),

                // Section 4: SOW Categories
                Section::make('Ruang Lingkup Pekerjaan')
                    ->icon('heroicon-o-sparkles')
                    ->description('Tabel rincian ruang lingkup pekerjaan dengan kategori dan sub-item')
                    ->schema([
                        Repeater::make('sow_categories')
                            ->label('Kategori Ruang Lingkup')
                            ->defaultItems(0)
                            ->collapsible()
                            ->collapsed(false)
                            ->schema([
                                TextInput::make('item')
                                    ->label('Nama Kategori')
                                    ->placeholder('Contoh: Social Media Management, Digital Ads, SEO, dll')
                                    ->required()
                                    ->columnSpanFull(),

                                Repeater::make('children')
                                    ->label('Sub-Item')
                                    ->defaultItems(0)
                                    ->collapsible()
                                    ->collapsed(false)
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
                Section::make('Detail Kontrak')
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
                                    ->default(3),
                            ]),
                    ]),

                // Section 6: Payment
                Section::make('Pembayaran')
                    ->icon('heroicon-o-credit-card')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Select::make('payment_type')
                                    ->label('Tipe Pembayaran')
                                    ->options([
                                        'full' => 'Lunas',
                                        'dp_monthly' => 'DP + Cicilan',
                                        'monthly' => 'Cicilan per Bulan',
                                    ])
                                    ->default('full'),
                                TextInput::make('total_value')
                                    ->label('Total Nilai Kontrak')
                                    ->numeric()
                                    ->prefix('Rp '),
                            ]),
                        Grid::make(2)
                            ->schema([
                                TextInput::make('down_payment_percent')
                                    ->label('DP (%)')
                                    ->numeric()
                                    ->default(0),
                                TextInput::make('installment_months')
                                    ->label('Jumlah Cicilan')
                                    ->numeric()
                                    ->default(1),
                            ]),
                    ]),

                // Section 7: Lampiran
                Section::make('Lampiran')
                    ->icon('heroicon-o-paper-clip')
                    ->collapsed()
                    ->schema([
                        TextInput::make('lampiran_title')
                            ->label('Judul Lampiran')
                            ->default('Lampiran 1 — Rincian Biaya Jasa')
                            ->columnSpanFull(),

                        Grid::make(2)
                            ->schema([
                                TextInput::make('table_a_title')
                                    ->label('Judul Tabel A'),
                                TextInput::make('table_b_title')
                                    ->label('Judul Tabel a'),
                            ]),

                        Toggle::make('has_third_party')
                            ->label('Aktifkan Tabel a (Biaya Pihak Ketiga)')
                            ->default(false),

                        Repeater::make('fee_items')
                            ->label('Item Biaya (Tabel A)')
                            ->relationship('feeItems')
                            ->schema([
                                TextInput::make('item')->label('Nama Item')->required(),
                                TextInput::make('amount')->label('Jumlah')->numeric()->prefix('Rp '),
                                TextInput::make('due_date')->label('Jatuh Tempo'),
                            ])
                            ->columns(3)
                            ->defaultItems(1),

                        Repeater::make('third_party_items')
                            ->label('Item Pihak Ketiga (Tabel a)')
                            ->relationship('thirdPartyItems')
                            ->schema([
                                TextInput::make('item')->label('Nama Item'),
                                TextInput::make('amount')->label('Jumlah')->numeric()->prefix('Rp '),
                                Textarea::make('notes')->label('Keterangan')->rows(2),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->visible(fn (callable $get) => $get('has_third_party')),
                    ]),

                // Section 8: Additional Terms
                Section::make('Ketentuan Tambahan')
                    ->icon('heroicon-o-document-text')
                    ->collapsed()
                    ->schema([
                        MarkdownEditor::make('content')
                            ->label('Klausul / Catatan Tambahan')
                            ->columnSpanFull(),
                        MarkdownEditor::make('penalty_clause')
                            ->label('Klausul Denda')
                            ->columnSpanFull(),
                        Grid::make(2)
                            ->schema([
                                Toggle::make('force_majeure_extended')
                                    ->label('Force Majeure Extended'),
                                TextInput::make('termination_notice_days')
                                    ->label('Hari Pengakhiran Kontrak')
                                    ->numeric()
                                    ->default(30),
                            ]),
                    ]),
            ]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Extract SOW categories before record creation
        if (isset($data['sow_categories'])) {
            $this->sowCategoriesData = $data['sow_categories'];
            unset($data['sow_categories']);
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        // Save SOW categories after contract is created
        if (!empty($this->sowCategoriesData)) {
            $this->saveSowCategories($this->record, $this->sowCategoriesData);
            unset($this->sowCategoriesData);
        }
    }

    private function saveSowCategories(Model $contract, array $categories): void
    {
        $sortOrder = 0;
        foreach ($categories as $categoryData) {
            $sortOrder++;

            if (empty($categoryData['item'])) {
                continue;
            }

            $category = ContractItem::create([
                'contract_id' => $contract->id,
                'item' => $categoryData['item'] ?? 'Layanan',
                'type' => 'sow',
                'sort_order' => $sortOrder,
                'is_category_header' => true,
            ]);

            if (!empty($categoryData['children']) && is_array($categoryData['children'])) {
                $childSortOrder = 0;
                foreach ($categoryData['children'] as $childData) {
                    $childSortOrder++;

                    if (empty($childData['item'])) {
                        continue;
                    }

                    ContractItem::create([
                        'contract_id' => $contract->id,
                        'parent_id' => $category->id,
                        'item' => $childData['item'] ?? '',
                        'description' => $childData['description'] ?? '',
                        'type' => 'sow',
                        'sort_order' => $childSortOrder,
                        'is_category_header' => false,
                    ]);
                }
            }
        }
    }
}
