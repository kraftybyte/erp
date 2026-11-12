<?php

namespace App\Filament\Resources\ContractResource\Pages;

use App\Filament\Resources\ContractResource\Schemas\ContractLampiran;
use App\Filament\Resources\ContractResource\Schemas\ContractScope;
use App\Filament\Resources\ContractResource;
use App\Filament\Resources\ContractResource\Pages\Concerns\HasContractPreview;
use App\Models\ContractItem;
use App\Services\ContractDefaultBody;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditContract extends EditRecord
{
    use HasContractPreview;

    protected static string $resource = ContractResource::class;

    protected static string $view = 'filament.resources.contracts.form-with-preview';

    /** @var array|null */
    protected ?array $sowCategoriesData = null;

    protected function getHeaderActions(): array
    {
        return [
            \App\Filament\Resources\ContractResource\Actions\DuplicateContract::page(),
            \Filament\Actions\DeleteAction::make(),
        ];
    }

    public function form(Forms\Form $form): Forms\Form
    {
        // Load relationships first
        $this->record->load([
            'feeItems',
            'thirdPartyItems',
            'sowCategories' => function ($query) {
                $query->with('children')->orderBy('sort_order');
            },
            'deal',
        ]);

        return $form
            // Setiap perubahan isian memperbarui pratinjau kontrak di samping form.
            ->live(debounce: 600)
            ->schema([
                // Section 1: Contract Info
                Section::make('Informasi Kontrak')
                    ->icon('heroicon-o-document')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('contract_number')
                                    ->label('Nomor Kontrak')
                                    ->required(),
                                DatePicker::make('effective_date')
                                    ->label('Tanggal Efektif')
                                    ->required(),
                            ]),
                        Grid::make(2)
                            ->schema([
                                DatePicker::make('expiration_date')
                                    ->label('Tanggal Berakhir'),
                                Select::make('deal_id')
                                    ->label('Deal')
                                    ->relationship('deal', 'nama_perusahaan')
                                    ->searchable()
                                    ->preload(),
                            ]),
                        static::signingCityInput(),
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
                        Grid::make(3)
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
                        static::clientLegalBasisInput(),
                    ]),

                static::agencySignatorySection(prefill: false),

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

                // Section 4: Ruang lingkup (kategori, catatan, Lampiran 1)
                ContractScope::section(ContractScope::existing($this->record)),

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
                        Grid::make(3)
                            ->schema([
                                TextInput::make('max_revisions')
                                    ->label('Maksimal Revisi')
                                    ->numeric()
                                    ->default(3),
                                TextInput::make('duration_months')
                                    ->label('Durasi (Bulan)')
                                    ->numeric()
                                    ->default(3),
                                // Dipakai di Pasal Jangka Waktu lewat {hari_pengakhiran}.
                                TextInput::make('termination_notice_days')
                                    ->label('Hari Pengakhiran Kontrak')
                                    ->numeric()
                                    ->default(30),
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
                                    ->prefix('Rp ')
                                    ->reactive()
                                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                        $this->recalculateTotals($set, $get);
                                    }),
                            ]),
                        Grid::make(2)
                            ->schema([
                                TextInput::make('down_payment_percent')
                                    ->label('DP (%)')
                                    ->visible(fn (Get $get) => $get('payment_type') === 'dp_monthly')
                                    ->numeric()
                                    ->default(0)
                                    ->reactive()
                                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                        $this->recalculateTotals($set, $get);
                                    }),
                                TextInput::make('installment_months')
                                    ->label('Jumlah Cicilan')
                                    ->numeric()
                                    ->default(1),
                                TextInput::make('payment_due_day')
                                    ->label('Jatuh tempo tiap bulan (tanggal)')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(31)
                                    ->default(\App\Services\ContractTemplateService::DEFAULT_DUE_DAY)
                                    ->placeholder((string) \App\Services\ContractTemplateService::DEFAULT_DUE_DAY)
                                    ->helperText('Penanda {tanggal_jatuh_tempo}: pasal pembayaran dan baris Tabel A ikut tanggal ini.'),
                            ]),
                    ]),

                // Section 7: Lampiran (semua teks Tabel A/B bisa diubah)
                ContractLampiran::section(),

                // Isi kontrak (semua teks bisa diedit) dan kop/kaki surat.
                ...static::contractBodySections(),
            ]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Kontrak yang isinya belum pernah diedit mulai dari teks bawaan, sama
        // dengan PDF-nya sekarang (termasuk klausul tambahan/denda lamanya).
        if (! is_array($data['articles'] ?? null)) {
            $data = array_merge($data, ContractDefaultBody::make($this->record));
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Extract SOW categories before record save
        if (isset($data['sow_categories'])) {
            $this->sowCategoriesData = $data['sow_categories'];
            unset($data['sow_categories']);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        // Save SOW categories after contract is updated
        if (!empty($this->sowCategoriesData)) {
            // Delete existing SOW items for this contract
            ContractItem::where('contract_id', $this->record->id)
                ->where('type', 'sow')
                ->delete();

            // Re-save all
            ContractScope::save($this->record, $this->sowCategoriesData);
            unset($this->sowCategoriesData);
        }
    }


    private function recalculateTotals(callable $set, callable $get): void
    {
        // Placeholder for total recalculation
    }
}
