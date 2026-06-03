<?php

namespace App\Filament\Resources\PaymentAccountResource\RelationManagers;

use App\Models\Transaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Actions\Action;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $title = 'Riwayat Transaksi';

    protected static ?string $modelLabel = 'Transaksi';

    protected static ?string $pluralModelLabel = 'Transaksi';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('type')
                    ->label('Jenis Transaksi')
                    ->options([
                        'in' => 'Masuk (In)',
                        'out' => 'Keluar (Out)',
                    ])
                    ->required(),

                Forms\Components\TextInput::make('amount')
                    ->label('Jumlah')
                    ->numeric()
                    ->prefix('Rp')
                    ->required(),

                Forms\Components\TextInput::make('description')
                    ->label('Keterangan')
                    ->required(),

                Forms\Components\DatePicker::make('transaction_date')
                    ->label('Tanggal Transaksi')
                    ->default(now())
                    ->required(),

                Forms\Components\Select::make('category')
                    ->label('Kategori')
                    ->options(Transaction::getCategories()),

                Forms\Components\Hidden::make('user_id')
                    ->default(fn() => auth()->id()),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->orderBy('transaction_date', 'desc')->orderBy('created_at', 'desc'))
            ->defaultSort('transaction_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('transaction_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn($state) => $state === 'in' ? 'MASUK' : 'KELUAR')
                    ->color(fn($state) => $state === 'in' ? 'success' : 'danger'),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Jumlah')
                    ->money('IDR')
                    ->sortable()
                    ->formatStateUsing(fn($state, $record) => ($record->type === 'in' ? '+' : '-') . ' Rp ' . number_format((float) $state, 0, ',', '.'))
                    ->color(fn($record) => $record->type === 'in' ? 'success' : 'danger')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('reference_type')
                    ->label('Referensi')
                    ->formatStateUsing(fn($state) => $state ? ucfirst($state) : '-'),

                Tables\Columns\TextColumn::make('reference_id')
                    ->label('ID Ref')
                    ->formatStateUsing(fn($state, $record) => $state ? "#{$state}" : '-')
                    ->color('info'),

                Tables\Columns\TextColumn::make('category')
                    ->label('Kategori')
                    ->formatStateUsing(fn($state) => $state ? (Transaction::getCategories()[$state] ?? $state) : '-'),

                Tables\Columns\TextColumn::make('description')
                    ->label('Keterangan')
                    ->limit(50),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Oleh')
                    ->formatStateUsing(fn($state) => $state ?? 'System'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Jenis')
                    ->options([
                        'in' => 'Masuk',
                        'out' => 'Keluar',
                    ]),

                Tables\Filters\SelectFilter::make('category')
                    ->label('Kategori')
                    ->options(Transaction::getCategories()),

                Tables\Filters\Filter::make('this_month')
                    ->label('Bulan Ini')
                    ->query(fn ($query) => $query->whereMonth('transaction_date', now()->month)
                        ->whereYear('transaction_date', now()->year)),

                Tables\Filters\Filter::make('this_week')
                    ->label('Minggu Ini')
                    ->query(fn ($query) => $query->whereBetween('transaction_date', [now()->startOfWeek(), now()->endOfWeek()])),
            ])
            ->headerActions([
                Tables\Actions\Action::make('add_deposit')
                    ->label('Setoran')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->form([
                        Forms\Components\TextInput::make('amount')
                            ->label('Jumlah')
                            ->numeric()
                            ->prefix('Rp')
                            ->required(),

                        Forms\Components\TextInput::make('description')
                            ->label('Keterangan')
                            ->placeholder('Contoh: Setoran awal, Transfer dari BCA...')
                            ->required(),

                        Forms\Components\DatePicker::make('transaction_date')
                            ->label('Tanggal')
                            ->default(now())
                            ->required(),

                        Forms\Components\Select::make('category')
                            ->label('Kategori')
                            ->options([
                                'deposit' => 'Setoran',
                                'transfer' => 'Transfer Masuk',
                                'other' => 'Lainnya',
                            ]),
                    ])
                    ->action(function (array $data, RelationManager $livewire) {
                        $livewire->getOwnerRecord()->transactions()->create([
                            'type' => 'in',
                            'amount' => $data['amount'],
                            'description' => $data['description'],
                            'transaction_date' => $data['transaction_date'],
                            'category' => $data['category'] ?? 'deposit',
                            'user_id' => auth()->id(),
                        ]);
                        $livewire->resetTable();
                    }),

                Tables\Actions\Action::make('add_withdrawal')
                    ->label('Penarikan')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('danger')
                    ->form([
                        Forms\Components\TextInput::make('amount')
                            ->label('Jumlah')
                            ->numeric()
                            ->prefix('Rp')
                            ->required(),

                        Forms\Components\TextInput::make('description')
                            ->label('Keterangan')
                            ->placeholder('Contoh: Penarikan cash, Transfer keluar...')
                            ->required(),

                        Forms\Components\DatePicker::make('transaction_date')
                            ->label('Tanggal')
                            ->default(now())
                            ->required(),

                        Forms\Components\Select::make('category')
                            ->label('Kategori')
                            ->options([
                                'withdrawal' => 'Penarikan',
                                'transfer' => 'Transfer Keluar',
                                'other' => 'Lainnya',
                            ]),
                    ])
                    ->action(function (array $data, RelationManager $livewire) {
                        $livewire->getOwnerRecord()->transactions()->create([
                            'type' => 'out',
                            'amount' => $data['amount'],
                            'description' => $data['description'],
                            'transaction_date' => $data['transaction_date'],
                            'category' => $data['category'] ?? 'withdrawal',
                            'user_id' => auth()->id(),
                        ]);
                        $livewire->resetTable();
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
