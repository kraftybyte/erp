<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BankMutationResource\Pages;
use App\Helpers\Money;
use App\Models\BankMutation;
use App\Models\Invoice;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Riwayat mutasi rekening yang dicatat lewat bot keuangan (/mutasi): baris
 * mutasinya dan invoice / expense / advance yang dibayar. Hanya dibaca;
 * pembatalan pembayaran tetap lewat halaman invoice/expense/advance-nya.
 */
class BankMutationResource extends Resource
{
    protected static ?string $model = BankMutation::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Mutasi Rekening';

    protected static ?string $modelLabel = 'mutasi rekening';

    protected static ?string $pluralModelLabel = 'mutasi rekening';

    protected static ?int $navigationSort = 4;

    private const ROLES = ['Director', 'Finance', 'super_admin'];

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->hasRole(self::ROLES);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['paymentAccount', 'recordedBy']))
            ->defaultSort('mutation_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('mutation_date')->label('Tanggal')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('paymentAccount.name')->label('Rekening'),
                Tables\Columns\TextColumn::make('direction')
                    ->label('Arah')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'in' ? 'Masuk' : 'Keluar')
                    ->color(fn (string $state) => $state === 'in' ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Nominal')
                    ->formatStateUsing(fn ($state) => Money::rupiah($state))
                    ->alignEnd()
                    ->sortable(),
                Tables\Columns\TextColumn::make('description')->label('Keterangan bank')->wrap()->limit(80)->searchable(),
                Tables\Columns\TextColumn::make('targets')
                    ->label('Dicatat sebagai')
                    ->state(fn (BankMutation $record) => self::targetLinks($record))
                    ->html(),
                Tables\Columns\TextColumn::make('recordedBy.name')->label('Oleh')->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->label('Dicatat')->dateTime('d M Y H:i')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('payment_account_id')->label('Rekening')->relationship('paymentAccount', 'name'),
                Tables\Filters\SelectFilter::make('direction')->label('Arah')->options(['in' => 'Masuk', 'out' => 'Keluar']),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    /**
     * "Invoice #SC/0132611/00012 · Expense #45", masing-masing tertaut ke halamannya.
     */
    private static function targetLinks(BankMutation $record): HtmlString
    {
        $links = collect($record->targets ?? [])->map(function (array $target) {
            [$label, $url] = match ($target['type']) {
                'invoice' => ['Invoice ' . (Invoice::withTrashed()->whereKey($target['id'])->value('uuid') ?? "#{$target['id']}"), InvoiceResource::getUrl('view', ['record' => $target['id']])],
                'expense' => ["Expense #{$target['id']}", ExpenseResource::getUrl('view', ['record' => $target['id']])],
                'advance' => ["Advance #{$target['id']}", AdvanceResource::getUrl('view', ['record' => $target['id']])],
                'bank_fee'      => ['Biaya / pajak bank', null],
                'bank_interest' => ['Bunga / jasa giro bank', null],
                default   => [ucfirst((string) $target['type']), null],
            };

            $text = e($label) . ' · ' . e(Money::rupiah($target['amount'] ?? 0))
                . (! empty($target['withholding']) ? ' + potongan ' . e($target['withholding_label'] ?? 'PPh 23') . ' ' . e(Money::rupiah($target['withholding'])) : '');

            return $url ? '<a href="' . e($url) . '" class="text-primary-600 hover:underline">' . $text . '</a>' : $text;
        });

        return new HtmlString($links->implode('<br>'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBankMutations::route('/'),
        ];
    }
}
