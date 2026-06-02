<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentAccountResource\Pages;
use App\Filament\Resources\PaymentAccountResource\RelationManagers;
use App\Models\PaymentAccount;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PaymentAccountResource extends Resource
{
    protected static ?string $model = PaymentAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationGroup = 'Finance';
    protected static ?string $navigationLabel = 'Rekening';
    protected static ?string $navigationBadgeTooltip = 'Total Saldo';

    public static function getNavigationBadge(): ?string
    {
        $total = PaymentAccount::active()->get()->sum(function ($account) {
            return $account->getAvailableBalance();
        });

        return 'Rp ' . number_format($total, 0, ',', '.');
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informasi Rekening')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->label('Nama Akun')
                            ->placeholder('Contoh: Bank BCA Utama')
                            ->maxLength(255),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true),

                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan')
                            ->placeholder('Catatan tambahan...')
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('Detail Bank')
                    ->description('Kosongkan jika ini adalah akun Cash')
                    ->schema([
                        Forms\Components\TextInput::make('bank_name')
                            ->label('Nama Bank')
                            ->placeholder('Contoh: BCA, Mandiri, BRI, BNI')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('account_number')
                            ->label('Nomor Rekening')
                            ->placeholder('Contoh: 1234567890')
                            ->maxLength(50),

                        Forms\Components\TextInput::make('account_holder')
                            ->label('Nama Pemilik Rekening')
                            ->placeholder('Nama sesuai di rekening')
                            ->maxLength(255),
                    ])->columns(3),

                Forms\Components\Section::make('Saldo')
                    ->description('Saldo akan dihitung otomatis dari transaksi')
                    ->schema([
                        Forms\Components\TextInput::make('initial_balance')
                            ->label('Saldo Awal')
                            ->numeric()
                            ->prefix('Rp')
                            ->default(0)
                            ->helperText('Saldo awal sebelum ada transaksi'),

                        Forms\Components\TextInput::make('current_balance_display')
                            ->label('Saldo Saat Ini')
                            ->disabled()
                            ->dehydrated(false)
                            ->formatStateUsing(fn($record) => $record
                                ? 'Rp ' . number_format($record->getAvailableBalance(), 0, ',', '.')
                                : 'Rp 0')
                            ->helperText('Otomatis dihitung dari saldo awal + transaksi masuk - transaksi keluar'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['transactions']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Status')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Akun')
                    ->sortable()
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('bank_name')
                    ->label('Bank')
                    ->sortable()
                    ->formatStateUsing(fn($state) => $state ?: '-')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('account_number')
                    ->label('No. Rekening')
                    ->formatStateUsing(fn($state) => $state ?: '-')
                    ->copyable(),

                Tables\Columns\TextColumn::make('account_holder')
                    ->label('Pemilik')
                    ->formatStateUsing(fn($state) => $state ?: '-'),

                Tables\Columns\TextColumn::make('initial_balance')
                    ->label('Saldo Awal')
                    ->money('IDR')
                    ->sortable()
                    ->formatStateUsing(fn($state) => 'Rp ' . number_format((float) $state, 0, ',', '.')),

                Tables\Columns\TextColumn::make('current_balance')
                    ->label('Saldo')
                    ->money('IDR')
                    ->sortable()
                    ->weight('bold')
                    ->color('success')
                    ->formatStateUsing(function ($record) {
                        $balance = $record->getAvailableBalance();
                        return 'Rp ' . number_format($balance, 0, ',', '.');
                    }),

                Tables\Columns\TextColumn::make('transactions_sum_in')
                    ->label('Total Masuk')
                    ->money('IDR')
                    ->formatStateUsing(fn($record) => 'Rp ' . number_format($record->getTotalIn(), 0, ',', '.'))
                    ->color('success'),

                Tables\Columns\TextColumn::make('transactions_sum_out')
                    ->label('Total Keluar')
                    ->money('IDR')
                    ->formatStateUsing(fn($record) => 'Rp ' . number_format($record->getTotalOut(), 0, ',', '.'))
                    ->color('danger'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('is_active')
                    ->label('Status')
                    ->options([
                        '1' => 'Aktif',
                        '0' => 'Tidak Aktif',
                    ]),

                Tables\Filters\Filter::make('is_bank')
                    ->label('Akun Bank')
                    ->query(fn ($query) => $query->whereNotNull('bank_name')),

                Tables\Filters\Filter::make('is_cash')
                    ->label('Akun Cash')
                    ->query(fn ($query) => $query->whereNull('bank_name')),

                Tables\Filters\Filter::make('has_balance')
                    ->label('Punya Saldo')
                    ->query(fn ($query) => $query->whereRaw('current_balance > 0')),
            ])
            ->actions([
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
            RelationManagers\TransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentAccounts::route('/'),
            'create' => Pages\CreatePaymentAccount::route('/create'),
            'view' => Pages\ViewPaymentAccount::route('/{record}'),
            'edit' => Pages\EditPaymentAccount::route('/{record}/edit'),
        ];
    }
}
