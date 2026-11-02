<?php

namespace App\Filament\Forms;

use App\Helpers\Money;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;

/**
 * Field "Tipe Pembayaran" dan "Persen DP" di form invoice. Persen DP diketik
 * bebas (1–99), tidak lagi dipilih dari kelipatan 10. Tetap tersimpan sebagai
 * satu kolom type_payment ("dp_25"), format yang dibaca Invoice::dpPercentage(),
 * PDF, tabel, dan bot.
 *
 * Setelah invoice menerima pembayaran keduanya terkunci: mengubah persen DP
 * mengubah nominal yang ditagih, sehingga status lunas/sebagian tidak lagi
 * sesuai dengan uang yang sudah masuk.
 */
class PaymentTypeFields
{
    public const DP = 'dp';

    /** @return array<Forms\Components\Component> */
    public static function make(): array
    {
        return [
            Forms\Components\Select::make('type_payment')
                ->label('Tipe Pembayaran')
                ->required()
                ->live()
                ->options([
                    'full_payment'  => 'Full Payment',
                    self::DP        => 'Down Payment',
                    'final_payment' => 'Final Payment',
                ])
                // "dp_25" di database tampil sebagai "Down Payment" + 25 di kolom persen.
                ->afterStateHydrated(function (Forms\Components\Select $component, ?string $state, Set $set) {
                    if (is_string($state) && str_starts_with($state, 'dp_')) {
                        $component->state(self::DP);
                        $set('dp_persen', (int) substr($state, 3));
                    }
                })
                ->dehydrateStateUsing(fn (?string $state, Get $get) => $state === self::DP
                    ? 'dp_' . (int) $get('dp_persen')
                    : $state)
                ->disabled(fn ($record) => self::locked($record))
                ->helperText(fn ($record) => self::locked($record)
                    ? 'Sudah ada pembayaran, jadi tipe dan persen DP tidak bisa diubah.'
                    : null),

            Forms\Components\TextInput::make('dp_persen')
                ->label('Persen DP')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(99)
                ->suffix('%')
                ->required()
                ->live(onBlur: true)
                ->dehydrated(false)
                ->visible(fn (Get $get) => $get('type_payment') === self::DP)
                ->disabled(fn ($record) => self::locked($record))
                ->validationMessages([
                    'integer' => 'Isi angka bulat, mis. 25.',
                    'min'     => 'Persen DP minimal 1%.',
                    'max'     => 'Persen DP maksimal 99%. Untuk 100% pilih Full Payment.',
                ])
                ->helperText(function (Get $get) {
                    $persen = (int) $get('dp_persen');
                    $total  = (float) $get('grand_total');

                    return $persen >= 1 && $persen <= 99 && $total > 0
                        ? 'Ditagih ' . Money::rupiah($total * $persen / 100) . ' dari Grand Total ' . Money::rupiah($total) . '.'
                        : 'Dihitung dari Grand Total.';
                }),
        ];
    }

    private static function locked($record): bool
    {
        return $record !== null && (float) $record->paid > 0;
    }
}
