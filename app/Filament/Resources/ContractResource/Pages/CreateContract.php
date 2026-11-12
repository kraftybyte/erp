<?php

namespace App\Filament\Resources\ContractResource\Pages;

use App\Filament\Resources\ContractResource\Schemas\ContractLampiran;
use App\Filament\Resources\ContractResource\Schemas\ContractScope;
use App\Models\Contract;
use App\Services\ContractFormats;
use Filament\Forms\Get;
use Filament\Forms\Set;
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
                            ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                $deal = $state ? \App\Models\Deal::find($state) : null;
                                if ($deal) {
                                    static::refreshNumberForClient($get('client_name_full'), $deal->nama_perusahaan, $get, $set);
                                    $set('client_name_full', $deal->nama_perusahaan);
                                }
                            }),
                        static::signingCityInput(),
                    ]),

                // Section 2: Client Info
                Section::make('Informasi Klien')
                    ->icon('heroicon-o-user')
                    ->collapsed()
                    ->schema([
                        TextInput::make('client_name_full')
                            ->label('Nama Perusahaan (Full)')
                            ->required()
                            // Format nomor dengan {kode_klien}: nomor otomatis ikut nama client.
                            ->afterStateUpdated(fn ($state, $old, Get $get, Set $set) => static::refreshNumberForClient($old, $state, $get, $set)),
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
                        static::clientLegalBasisInput(),
                    ]),

                static::agencySignatorySection(prefill: true),

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
                ContractScope::section(),

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
                                    ->prefix('Rp '),
                            ]),
                        Grid::make(2)
                            ->schema([
                                TextInput::make('down_payment_percent')
                                    ->label('DP (%)')
                                    ->visible(fn (Get $get) => $get('payment_type') === 'dp_monthly')
                                    ->numeric()
                                    ->default(0),
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

    /**
     * Kontrak baru memakai format bawaan dari Settings → Contract Template:
     * layout dan teks lampiran di sini; judul, pembukaan, pasal, dan penutup
     * lewat default isiannya (repeater pasal tidak boleh diisi ulang: Filament
     * menggabungkan lagi isi bawaannya).
     */
    protected function afterFill(): void
    {
        $format = \Illuminate\Support\Arr::except(ContractFormats::forNewContract(), ['document_title', 'opening_text', 'articles', 'closing_text']);

        $this->form->fill(array_merge($this->form->getRawState(), $format));
    }

    /** Nomor otomatis yang belum diubah tangan ikut berganti saat nama client berganti. */
    protected static function refreshNumberForClient(?string $oldClient, ?string $newClient, Get $get, Set $set): void
    {
        if ($get('contract_number') === Contract::generateContractNumber($oldClient)) {
            $set('contract_number', Contract::generateContractNumber($newClient));
        }
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
            ContractScope::save($this->record, $this->sowCategoriesData);
            unset($this->sowCategoriesData);
        }
    }

}
