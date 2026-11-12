<?php

namespace App\Filament\Resources\ContractResource\Schemas;

use App\Models\Contract;
use App\Services\ContractTemplateService;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Support\Str;

/**
 * Bagian Lampiran di form kontrak (Buat, Edit, View): semua teks Tabel A dan
 * Tabel B bisa diubah — judul, keterangan, judul kolom, baris, dan label total.
 * Isian kosong memakai teks bawaan dari ContractTemplateService (tampil
 * sebagai placeholder), jadi kontrak lama tidak berubah.
 */
final class ContractLampiran
{
    public static function section(): Section
    {
        return Section::make('Lampiran')
            ->icon('heroicon-o-table-cells')
            ->description('Semua teks lampiran bisa diubah. Isian yang dikosongkan memakai teks bawaan yang tampil samar.')
            ->collapsed()
            ->schema([
                Textarea::make('lampiran_title')
                    ->label('Judul lampiran biaya')
                    ->helperText('Enter untuk baris baru. Penanda isian seperti {durasi} juga bisa dipakai di judul, keterangan, dan catatan.')
                    ->placeholder('Lampiran 2 — Rincian Biaya Jasa')
                    ->rows(2),

                Fieldset::make('Tabel A — biaya jasa')
                    ->columns(1)
                    ->schema([
                        TextInput::make('table_a_title')
                            ->label('Judul tabel')
                            ->placeholder('Tabel A')
                            ->maxLength(255),
                        Toggle::make('layout.table_a_show_description')
                            ->label('Tampilkan keterangan di bawah judul')
                            ->default(true)
                            // Kontrak lama belum punya pilihan ini: keterangan tetap tampil.
                            ->afterStateHydrated(fn (Toggle $component, $state) => $state === null ? $component->state(true) : null)
                            ->live(),
                        Textarea::make('lampiran_description')
                            ->label('Keterangan di bawah judul')
                            ->placeholder(ContractTemplateService::TABLE_A_DESCRIPTION)
                            ->helperText('{durasi} diganti jumlah bulan kontrak.')
                            ->rows(2)
                            ->visible(fn (Get $get): bool => (bool) $get('layout.table_a_show_description')),
                        self::columnLabels('table_a_columns', ContractTemplateService::TABLE_A_COLUMNS),
                        Repeater::make('fee_items')
                            ->label('Baris')
                            ->relationship('feeItems')
                            ->orderColumn('sort_order')
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => ['type' => 'fee'] + $data)
                            ->schema([
                                TextInput::make('item')
                                    ->label('Item')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpan(['default' => 2, 'md' => 2]),
                                TextInput::make('estimate')
                                    ->label('Estimasi')
                                    ->placeholder('Kosong = sama dengan Jumlah')
                                    ->maxLength(255),
                                TextInput::make('due_date')
                                    ->label('Tanggal')
                                    ->placeholder('mis. Setiap tanggal 15')
                                    ->maxLength(255),
                                TextInput::make('amount')
                                    ->label('Jumlah')
                                    ->numeric()
                                    ->prefix('Rp'),
                            ])
                            ->columns(['default' => 2, 'md' => 5])
                            ->defaultItems(0)
                            ->addActionLabel('Tambah baris')
                            ->helperText('Tanpa baris: tabel diisi otomatis dari bagian Pembayaran. Klik "Isi otomatis dari Pembayaran" untuk menyalinnya ke sini lalu mengubahnya.')
                            ->hintAction(self::fillFromPaymentAction()),
                        Fieldset::make('Baris otomatis dari Pembayaran')
                            ->columns(['default' => 1, 'md' => 2])
                            ->schema([
                                TextInput::make('layout.fee_monthly_label')
                                    ->label('Nama baris cicilan per bulan')
                                    ->placeholder(ContractTemplateService::LAYOUT_DEFAULTS['fee_monthly_label'])
                                    ->helperText('Diikuti jumlah cicilan, mis. "(12x)".')
                                    ->maxLength(255),
                                TextInput::make('layout.fee_due_text')
                                    ->label('Teks jatuh tempo cicilan')
                                    ->placeholder(ContractTemplateService::LAYOUT_DEFAULTS['fee_due_text'])
                                    ->maxLength(255),
                            ]),
                        Placeholder::make('fee_total_check')
                            ->hiddenLabel()
                            ->content(fn (Get $get) => self::feeTotalWarning($get))
                            ->visible(fn (Get $get): bool => self::feeTotalWarning($get) !== null),
                        TextInput::make('table_a_total_label')
                            ->label('Label baris total')
                            ->placeholder(ContractTemplateService::TOTAL_LABEL)
                            ->helperText('Nilainya Total Nilai Kontrak di bagian Pembayaran.')
                            ->maxLength(255),
                        Textarea::make('layout.table_a_note')
                            ->label('Catatan di bawah tabel')
                            ->placeholder('mis. Catatan: Jumlah di atas belum termasuk PPN.')
                            ->rows(1),
                    ]),

                Toggle::make('has_third_party')
                    ->label('Tampilkan Tabel B (biaya pihak ketiga)')
                    ->live(),

                Fieldset::make('Tabel B — biaya pihak ketiga')
                    ->columns(1)
                    ->visible(fn (Get $get): bool => (bool) $get('has_third_party'))
                    ->schema([
                        TextInput::make('table_b_title')
                            ->label('Judul tabel')
                            ->placeholder('Tabel B')
                            ->maxLength(255),
                        Textarea::make('table_b_description')
                            ->label('Keterangan di bawah judul')
                            ->placeholder(ContractTemplateService::TABLE_B_DESCRIPTION)
                            ->rows(2),
                        self::columnLabels('table_b_columns', ContractTemplateService::TABLE_B_COLUMNS),
                        Repeater::make('third_party_items')
                            ->label('Baris')
                            ->relationship('thirdPartyItems')
                            ->orderColumn('sort_order')
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => ['type' => 'third_party'] + $data)
                            ->schema([
                                TextInput::make('item')
                                    ->label('Item')
                                    ->maxLength(255),
                                TextInput::make('amount')
                                    ->label('Jumlah')
                                    ->numeric()
                                    ->prefix('Rp'),
                                Textarea::make('notes')
                                    ->label('Keterangan (di bawah item)')
                                    ->rows(1),
                            ])
                            ->columns(['default' => 1, 'md' => 3])
                            ->defaultItems(0)
                            ->addActionLabel('Tambah baris'),
                    ]),
            ]);
    }

    /**
     * Baris Tabel A yang diketik sendiri tapi jumlahnya tidak sama dengan
     * Total Nilai Kontrak (yang dipakai baris TOTAL dan pasal biaya).
     */
    private static function feeTotalWarning(Get $get): ?string
    {
        $amounts = collect($get('fee_items') ?? [])->pluck('amount')->filter(fn ($amount) => is_numeric($amount));

        if ($amounts->isEmpty()) {
            return null;
        }

        $sum   = (float) $amounts->sum();
        $total = is_numeric($get('total_value')) ? (float) $get('total_value') : 0.0;

        if (abs($sum - $total) < 1) {
            return null;
        }

        $money = fn (float $value) => 'Rp ' . number_format($value, 0, ',', '.');

        return "Jumlah baris Tabel A {$money($sum)} tidak sama dengan Total Nilai Kontrak {$money($total)}. Baris TOTAL dan pasal biaya memakai Total Nilai Kontrak; sesuaikan barisnya atau klik \"Isi otomatis dari Pembayaran\".";
    }

    /** Judul kolom tabel; placeholder = judul bawaan. */
    private static function columnLabels(string $name, array $defaults): Fieldset
    {
        $inputs = [];

        foreach ($defaults as $key => $label) {
            $inputs[] = TextInput::make("{$name}.{$key}")
                ->label("Kolom {$label}")
                ->placeholder($label)
                ->maxLength(60);
        }

        return Fieldset::make('Judul kolom')
            ->columns(['default' => 2, 'md' => count($defaults)])
            ->schema($inputs);
    }

    /** Salin baris otomatis (dari tipe pembayaran, total, DP, cicilan) ke form supaya bisa diubah. */
    private static function fillFromPaymentAction(): Action
    {
        return Action::make('fillFeeRowsFromPayment')
            ->label('Isi otomatis dari Pembayaran')
            ->icon('heroicon-m-arrow-path')
            ->requiresConfirmation(fn (Get $get): bool => filled($get('fee_items')))
            ->modalHeading('Ganti baris Tabel A?')
            ->modalDescription('Baris yang ada diganti dengan baris dari tipe pembayaran, total nilai, DP, dan jumlah cicilan.')
            ->modalSubmitActionLabel('Ganti')
            ->action(function (Get $get, Set $set): void {
                $contract = (new Contract())->forceFill([
                    'payment_type'         => $get('payment_type') ?: 'full',
                    'total_value'          => is_numeric($get('total_value')) ? $get('total_value') : 0,
                    'down_payment_percent' => is_numeric($get('down_payment_percent')) ? $get('down_payment_percent') : 0,
                    'installment_months'   => is_numeric($get('installment_months')) ? (int) $get('installment_months') : null,
                    'duration_months'      => is_numeric($get('duration_months')) ? (int) $get('duration_months') : null,
                    'effective_date'       => $get('effective_date') ?: null,
                    'layout'               => is_array($get('layout')) ? $get('layout') : null,
                    'payment_due_day'      => is_numeric($get('payment_due_day')) ? (int) $get('payment_due_day') : null,
                ]);

                $rows = [];
                foreach (app(ContractTemplateService::class)->defaultFeeRows($contract) as $row) {
                    $rows[(string) Str::uuid()] = ['item' => $row['item'], 'estimate' => $row['estimate'], 'due_date' => $row['due_date'], 'amount' => round($row['amount'], 2)];
                }

                $set('fee_items', $rows);
            });
    }
}
