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

                        Forms\Components\TextInput::make('source')
                            ->label('Dari Rekening / Sumber')
                            ->placeholder('Contoh: Bank BCA 123456789 a/n PT XYZ'),

                        Forms\Components\TextInput::make('description')
                            ->label('Keterangan')
                            ->placeholder('Contoh: Setoran awal, Pembayaran klien...')
                            ->required(),

                        Forms\Components\DatePicker::make('transaction_date')
                            ->label('Tanggal')
                            ->default(now())
                            ->required(),

                        Forms\Components\Select::make('category')
                            ->label('Kategori')
                            ->options([
                                'deposit' => 'Setoran',
                                'payment' => 'Pembayaran Masuk',
                                'income' => 'Pendapatan',
                                'other' => 'Lainnya',
                            ]),
                    ])
                    ->action(function (array $data, RelationManager $livewire) {
                        $account = $livewire->getOwnerRecord();

                        $description = !empty($data['source'])
                            ? $data['source'] . ' → ' . $data['description']
                            : $data['description'];

                        // Create deposit transaction
                        $account->transactions()->create([
                            'type' => 'in',
                            'amount' => $data['amount'],
                            'description' => $description,
                            'transaction_date' => $data['transaction_date'],
                            'category' => $data['category'] ?? 'deposit',
                            'user_id' => auth()->id(),
                        ]);

                        $account->syncBalanceFromTransactions();
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

                        Forms\Components\TextInput::make('destination')
                            ->label('Ke Rekening / Tujuan')
                            ->placeholder('Contoh: Bank BCA 123456789 a/n PT ABC')
                            ->required(),

                        Forms\Components\TextInput::make('description')
                            ->label('Keterangan')
                            ->placeholder('Contoh: Pembayaran vendor, fee agency...')
                            ->required(),

                        Forms\Components\DatePicker::make('transaction_date')
                            ->label('Tanggal')
                            ->default(now())
                            ->required(),

                        Forms\Components\Select::make('category')
                            ->label('Kategori')
                            ->options([
                                'withdrawal' => 'Penarikan',
                                'payment' => 'Pembayaran',
                                'expense' => 'Biaya Operasional',
                                'other' => 'Lainnya',
                            ]),
                    ])
                    ->action(function (array $data, RelationManager $livewire) {
                        $account = $livewire->getOwnerRecord();

                        // Create withdrawal transaction with destination info
                        $account->transactions()->create([
                            'type' => 'out',
                            'amount' => $data['amount'],
                            'description' => $data['destination'] . ' → ' . $data['description'],
                            'transaction_date' => $data['transaction_date'],
                            'category' => $data['category'] ?? 'withdrawal',
                            'user_id' => auth()->id(),
                        ]);

                        $account->syncBalanceFromTransactions();
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
