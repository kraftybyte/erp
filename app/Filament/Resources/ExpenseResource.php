<?php

namespace App\Filament\Resources;

use App\Filament\Filters\SubmissionDateFilter;
use App\Enums\FinanceCategory;
use App\Filament\Resources\ExpenseResource\Pages;
use App\Filament\Resources\ExpenseResource\RelationManagers;
use App\Helpers\ColorHelper;
use App\Helpers\Money;
use App\Models\Expense;
use App\Models\PaymentAccount;
use App\Models\Transaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use App\Models\User;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Storage;
use App\Models\Project;

class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-pointing-out';

    public static function getNavigationLabel(): string
    {
        return \App\Helpers\NavigationHelper::getLabel('expense', 'Expense');
    }

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __("Finance");
    }

    public static function form(Form $form): Form
    {
        $categories = FinanceCategory::options();

        return $form
            ->schema([
                // Pengaju & status diisi server saat dibuat (Create…::mutateFormDataBeforeCreate),
                // bukan dari field tersembunyi yang bisa diubah di browser.
                Forms\Components\DatePicker::make('submission_date')
                    ->label('Tanggal Pengajuan')
                    ->default(now())
                    ->required(),

                Forms\Components\Select::make('project_id')
                    ->label('Pilih Project')
                    ->options(fn () => cache()->remember('projects_list', 300, fn () => Project::all()->pluck('nama_perusahaan', 'id')))
                    ->searchable()
                    ->required(),

                Forms\Components\Select::make('category')
                    ->label('Kategori')
                    ->options($categories)
                    ->searchable()
                    ->required(),

                Forms\Components\Textarea::make('keterangan_expense')
                    ->label('Keterangan Expense')
                    ->required(),

                Forms\Components\TextInput::make('amount')
                    ->label('Nominal')
                    ->numeric()
                    ->required()
                    ->prefix('Rp')
                    ->formatStateUsing(fn($state) => Money::inputValue($state)),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
        ->modifyQueryUsing(function (Builder $query) {
            $user = auth()->user();

            // Jika user memiliki role 'director finance' atau 'super_admin', tampilkan semua data
            if ($user->hasRole(['Director','Finance', 'super_admin'])) {
                return $query->with(['user', 'paidBy', 'project', 'paymentAccount']);
            }

            // Jika bukan, hanya tampilkan data miliknya sendiri
            return $query->with(['user', 'paidBy', 'project', 'paymentAccount'])->where('user_id', $user->id);
        })
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID Expense')
                    ->sortable()
                    ->wrap()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('user.firstname')
                    ->label('Permohonan User')
                    ->formatStateUsing(fn($state, $record) => $record->user?->fullName ?? '-')
                    // Dulu tampil mentah "2026-08-24 00:00:00".
                    ->description(fn(Expense $record): string => $record->submission_date?->format('d M Y') ?? '', position: 'below')
                    ->sortable()
                    ->wrap(),

                Tables\Columns\BadgeColumn::make('category')
                    ->label('Kategori')
                    ->sortable()
                    ->color('info'),

                Tables\Columns\TextColumn::make('keterangan_expense')
                    ->label('Keterangan Expense')
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Nominal')
                    ->sortable()
                    ->formatStateUsing(fn($state) => 'Rp ' . number_format($state, 2, ',', '.')),

                Tables\Columns\TextColumn::make('paymentAccount.name')
                    ->label('Rekening')
                    ->formatStateUsing(fn($state, $record) => $state ?? '-')
                    ->badge()
                    ->color('info')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->sortable()
                    ->badge()
                    ->color(fn ($record) => ColorHelper::status($record->status))
                    ->description(fn($record) => match ($record->status) {
                        'Ditolak' => trans($record->rejection_reason ?? ''),
                        'Dibayarkan' => $record->paidBy
                            ? 'oleh ' . $record->paidBy->fullName . ' pada ' . \Carbon\Carbon::parse($record->updated_at)->format('d M Y H:i')
                            : 'oleh unknown',
                        default => null,
                    })
                    ->wrap()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('attachment')
                    ->label('Lampiran Invoice')
                    ->badge()
                    ->formatStateUsing(fn($state) => $state ? 'Link Invoice' : 'Belum Ada')
                    ->action(function ($record) {
                        if ($record && $record->attachment) {
                            return redirect()->to(asset('storage/' . $record->attachment));
                        } else {
                            return back()->with('error', 'Attachment not found.');
                        }
                    })
                    ->sortable()
                    ->alignCenter()
                    ->url(fn($record) => $record->attachment ? asset('storage/' . $record->attachment) : null)
                    ->openUrlInNewTab(),
            ])
            ->defaultSort('submission_date', 'desc')
            ->filters([
                SubmissionDateFilter::make(),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'Pending' => 'Pending',
                        'Disetujui' => 'Disetujui',
                        'Ditolak' => 'Ditolak',
                        'Dibayarkan' => 'Dibayarkan',
                        'Selesai' => 'Selesai',
                    ]),
                Tables\Filters\SelectFilter::make('payment_account_id')
                    ->label('Rekening')
                    ->options(fn () => \App\Models\PaymentAccount::active()->pluck('name', 'id')),
            ])
            ->actions([
                Tables\Actions\Action::make('upload_invoice')
                    ->button()
                    ->size('xs')
                    ->icon('heroicon-o-arrow-up-circle')
                    ->color('danger')
                    ->label('Upload Invoice Pembelian')
                    ->action(function ($record, array $data) {
                        $file = $data['attachment'];
                        if ($file instanceof \Illuminate\Http\UploadedFile) {
                            // Validate file size (5MB max)
                            if ($file->getSize() > 5 * 1024 * 1024) {
                                throw ValidationException::withMessages([
                                    'attachment' => 'Ukuran file maksimal 5MB.',
                                ]);
                            }

                            // Validate file type
                            $allowedMimes = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
                            $extension = strtolower($file->getClientOriginalExtension());
                            if (!in_array($extension, $allowedMimes)) {
                                throw ValidationException::withMessages([
                                    'attachment' => 'Format file harus JPG, PNG, WebP, atau PDF.',
                                ]);
                            }

                            $path = $file->storeAs('expense', uniqid() . '-' . now()->format('d-m-Y') . '.' . $extension, 'public');
                            if ($path) {
                                $record->update([
                                    'attachment' => $path,
                                ]);
                            } else {
                                return back()->with('error', 'File upload failed.');
                            }
                        } else {
                            $record->update([
                                'attachment' => $data['attachment'],
                            ]);
                        }
                    })
                    ->form([
                        Forms\Components\Repeater::make('items')
                        ->label('Item Pengeluaran')
                        ->relationship('itemExpenses') // Relasi dengan model ItemExpense
                        ->schema([
                            Forms\Components\TextInput::make('name')
                            ->label('Nama Pembelian')
                            ->required(),
                
                        Forms\Components\Textarea::make('description')
                            ->label('Keterangan Pembelian')
                            ->required(),
                
                        Forms\Components\TextInput::make('price')
                            ->label('Harga')
                            ->numeric()
                            ->prefix('Rp')
                            ->required()
                            ->minValue(0)
                            ->reactive()
                            ->debounce(500)
                            ->afterStateUpdated(function ($state, callable $set, $get) {
                                $set('subtotal', $state * ($get('quantity') ?? 1));
                                $items = $get('../../items') ?? [];
                                $set('../../total_harga', collect($items)->sum(fn($item) => (float)($item['subtotal'] ?? 0)));
                            }),
                
                        Forms\Components\TextInput::make('quantity')
                            ->label('Qty')
                            ->numeric()
                            ->default(1)
                            ->required()
                            ->minValue(1)
                            ->reactive()
                            ->afterStateUpdated(function ($state, callable $set, $get) {
                                $set('subtotal', ($get('price') ?? 0) * $state);
                                $items = $get('../../items') ?? [];
                                $set('../../total_harga', collect($items)->sum(fn($item) => (float)($item['subtotal'] ?? 0)));
                            }),
                
                        Forms\Components\TextInput::make('subtotal')
                            ->label('Subtotal')
                            ->disabled()
                            ->dehydrated()
                            ->formatStateUsing(fn($state) => 'Rp ' . number_format($state, 0, ',', '.'))
                            ->required(),
                    ])
                    ->createItemButtonLabel('Tambah Item')
                    ->minItems(1)
                    ->maxItems(20),
                
                    Forms\Components\TextInput::make('total_harga')
                        ->label('Total Harga')
                        ->disabled()
                        ->dehydrated(true)
                        ->default(0)
                        ->formatStateUsing(fn($state) => 'Rp ' . number_format((float) $state, 0, ',', '.'))
                        ->dehydrateStateUsing(fn($state) => (float) str_replace(['Rp', '.', ','], ['', '', '.'], $state))
                        ->required(),

                        
                    Forms\Components\FileUpload::make('attachment')
                            ->label('Upload Invoice Pembayaran')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                            ->maxSize(5120) // 5MB
                            ->directory('expense/invoice/' . now()->format('Y/m/d'))
                            ->required()
                            ->validationMessages([
                                'required' => 'Bukti pembayaran harus diunggah.',
                                'max' => 'Ukuran file maksimal 5MB.',
                                'mimes' => 'Format file harus JPG, PNG, WebP, atau PDF.',
                            ]),
                    ])
                    ->hidden(fn($record) => !empty($record->attachment) || auth()->id() !== $record->user_id),

                Tables\Actions\Action::make('convert_to_pay')
                    ->button()
                    ->size('xs')
                    ->color('warning')
                    ->label('Bayarkan Pengajuan')
                    ->action(function ($record, array $data) {
                        DB::transaction(function () use ($record, $data) {
                            /*
                            | Kunci baris expense-nya, lalu baca ulang statusnya dari database.
                            | hidden() hanya berlaku saat halaman di-render, sehingga dua orang
                            | yang membuka daftar bersamaan sama-sama melihat tombolnya dan
                            | pengajuan bisa terbayar dua kali.
                            */
                            $expense = Expense::lockForUpdate()->find($record->id);

                            if (!$expense) {
                                throw ValidationException::withMessages([
                                    'payment_account_id' => 'Pengajuan tidak ditemukan.',
                                ]);
                            }

                            if ($expense->status !== 'Disetujui') {
                                throw ValidationException::withMessages([
                                    'payment_account_id' => $expense->status === 'Dibayarkan'
                                        ? 'Pengajuan ini sudah dibayarkan.'
                                        : "Pengajuan berstatus \"{$expense->status}\" dan tidak bisa dibayarkan.",
                                ]);
                            }

                            // Get payment account with pessimistic locking
                            $account = PaymentAccount::lockForUpdate()->find($data['payment_account_id']);
                            if (!$account) {
                                throw ValidationException::withMessages([
                                    'payment_account_id' => 'Rekening tidak ditemukan.',
                                ]);
                            }

                            /*
                            | Saldo rekening sengaja TIDAK dijadikan penghalang: pembayaran
                            | boleh melebihi saldo tercatat (budget longgar). Saldo tetap
                            | disinkronkan setelah transaksi, sehingga nilainya bisa minus
                            | dan selisihnya terlihat apa adanya.
                            */

                            // Create transaction record
                            Transaction::create([
                                'payment_account_id' => $account->id,
                                'created_by' => auth()->id(),
                                'type' => 'out',
                                'amount' => (float) $data['paid'],
                                'description' => "Expense: {$record->keterangan_expense}",
                                'reference_type' => 'expense',
                                'reference_id' => $record->id,
                                'transaction_date' => now()->toDateString(),
                                'category' => 'operational',
                            ]);

                            // Sync account balance after transaction
                            $account->syncBalanceFromTransactions();

                            // Update expense status with payment account
                            $record->update([
                                'status' => 'Dibayarkan',
                                'paid_by' => auth()->id(),
                                'paid' => $data['paid'],
                                'payment_account_id' => $account->id,
                                'reference' => $data['reference'],
                                'attachment_pay' => $data['attachment_pay'] instanceof \Illuminate\Http\UploadedFile
                                    ? $data['attachment_pay']->storeAs('payments', uniqid() . '-' . now()->format('d-m-Y') . '.' . $data['attachment_pay']->getClientOriginalExtension())
                                    : $data['attachment_pay'],
                            ]);
                        });
                    })
                    ->form([
                        Forms\Components\TextInput::make('paid')
                            ->label('Nominal yang Dibayarkan')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            // Batas atas mengikuti nominal yang diajukan. Pesan errornya sudah
                            // ada sejak awal, tapi aturannya tidak pernah terpasang.
                            ->maxValue(fn($record) => (float) $record->amount)
                            ->prefix('Rp')
                            ->default(fn($record) => $record->amount)
                            ->helperText(fn($record) => 'Maksimal Rp ' . number_format((float) $record->amount, 0, ',', '.') . ' sesuai pengajuan.')
                            ->formatStateUsing(fn($state) => Money::inputValue($state))
                            ->validationMessages([
                                'lte' => 'Nominal yang dibayarkan tidak boleh lebih dari jumlah pengeluaran yang diajukan.',
                                'max' => 'Nominal yang dibayarkan tidak boleh lebih dari jumlah pengeluaran yang diajukan.',
                                'min' => 'Nominal yang dibayarkan harus lebih dari nol.',
                            ]),
                        Forms\Components\TextInput::make('reference')
                            ->label('Nomor Referensi')
                            ->required(),
                        Forms\Components\FileUpload::make('attachment_pay')
                            ->label('Bukti Pembayaran')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                            ->maxSize(5120) // 5MB
                            ->directory('expense/payments/' . now()->format('Y/m/d'))
                            ->required()
                            ->validationMessages([
                                'required' => 'Bukti pembayaran harus diunggah.',
                                'max' => 'Ukuran file maksimal 5MB.',
                                'mimes' => 'Format file harus JPG, PNG, WebP, atau PDF.',
                            ]),
                        Forms\Components\Select::make('payment_account_id')
                            ->label('Pilih Rekening')
                            ->options(fn () =>
                                PaymentAccount::active()->get()->mapWithKeys(fn ($account) =>
                                    [$account->id => "{$account->name} ({$account->bank_name}) - {$account->getFormattedBalance()}"]
                                )
                            )
                            ->required()
                            ->reactive(),
                        ])
                        ->requiresConfirmation()
                        ->color('success')
                        ->icon('heroicon-o-credit-card')
                        ->hidden(fn ($record) =>
                            !auth()->user()->can('restore_expense', Expense::class) ||
                            $record->status !== 'Disetujui' ||
                            $record->status === 'Dibayarkan' ||
                            $record->status === 'Selesai' ||
                            empty($record->attachment)
                        ),

                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->action(function ($record) {
                        $record->update(['status' => 'Disetujui']);
                    })
                    ->requiresConfirmation()
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->hidden(fn($record) => !auth()->user()->can('restore_expense', Expense::class) || $record->status === 'Disetujui' || $record->status === 'Ditolak' || $record->status === 'Dibayarkan'|| $record->status === 'Selesai'),

                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->action(function ($record, array $data) {
                        $record->update([
                            'status' => 'Ditolak',
                            'rejection_reason' => $data['rejection_reason'],
                        ]);
                    })
                    ->form([
                        Forms\Components\Textarea::make('rejection_reason')
                            ->label('Alasan Penolakan')
                            ->required(),
                    ])
                    ->requiresConfirmation()
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->hidden(fn($record) => !auth()->user()->can('restore_expense', Expense::class) || $record->status === 'Disetujui' || $record->status === 'Ditolak' || $record->status === 'Dibayarkan' || $record->status === 'Selesai'),

                    Tables\Actions\Action::make('selesai')
                    ->label('Selesaikan Expense')
                    ->action(function ($record) {
                        $record->update(['status' => 'Selesai']);
                    })
                    ->requiresConfirmation()
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->hidden(fn($record) => !auth()->user()->can('restore_expense', Expense::class) || $record->status === 'Disetujui' || $record->status === 'Ditolak' || $record->status === 'Selesai'),

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
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExpenses::route('/'),
            'create' => Pages\CreateExpense::route('/create'),
            'edit' => Pages\EditExpense::route('/{record}/edit'),
                    'view' => Pages\ViewExpense::route('/{record}'),

        ];
    }
}
