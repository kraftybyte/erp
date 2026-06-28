<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AdvanceResource\Pages;
use App\Filament\Resources\AdvanceResource\RelationManagers;
use App\Helpers\ColorHelper;
use App\Models\Advance;
use App\Models\PaymentAccount;
use App\Models\Transaction;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Storage;
use App\Models\Project;

class AdvanceResource extends Resource 
{
    protected static ?string $model = Advance::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    public static function getNavigationLabel(): string
    {
        return \App\Helpers\NavigationHelper::getLabel('advance', 'Advance');
    }

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __("Finance");
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Hidden::make('user_id')
                    ->default(fn() => Auth::user()?->id)
                    ->required(),

                Forms\Components\DatePicker::make('submission_date')
                    ->label('Tanggal Pengajuan')
                    ->default(now())
                    ->required(),

                Forms\Components\Select::make('project_id')
                    ->label('Pilih Project')
                    ->searchable()
                    ->getOptionLabelFromRecordUsing(fn (Project $record): string => $record->deal?->nama_perusahaan . ' - ' . $record->nama_project)
                    ->getSearchResultsUsing(fn (string $search): array => Project::with('deal')
                        ->whereHas('deal', fn ($q) => $q->where('nama_perusahaan', 'like', '%' . $search . '%'))
                        ->orWhereHas('deal', fn ($q) => $q->where('nama_pic', 'like', '%' . $search . '%'))
                        ->orWhere('nama_project', 'like', '%' . $search . '%')
                        ->limit(20)
                        ->get()
                        ->mapWithKeys(fn ($project) => [$project->id => $project->deal?->nama_perusahaan . ' - ' . $project->nama_project])
                        ->toArray()
                    )
                    ->options(fn (): array => Project::with('deal')->latest()->limit(50)->get()
                        ->mapWithKeys(fn ($project) => [$project->id => $project->deal?->nama_perusahaan . ' - ' . $project->nama_project])
                        ->toArray()
                    )
                    ->required(),

                Forms\Components\Textarea::make('keterangan_advance')
                    ->label('Keterangan Advance')
                    ->required(),

                Forms\Components\TextInput::make('amount')
                    ->label('Nominal')
                    ->numeric()
                    ->required()
                    ->prefix('Rp')
                    ->formatStateUsing(fn($state) => number_format($state, 0, ',', '.')),

                Forms\Components\Hidden::make('status')
                    ->default('Pending')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
        ->modifyQueryUsing(function (Builder $query) {
            /** @var User|null $currentUser */
            $currentUser = Auth::user();

            // Jika user memiliki role 'director finance' atau 'super_admin', tampilkan semua data
            if ($currentUser?->hasRole(['Director','Finance', 'super_admin'])) {
                return $query->with(['user', 'paidBy', 'project', 'paymentAccount', 'realizasis.items']);
            }

            // Jika bukan, hanya tampilkan data miliknya sendiri
            return $query->with(['user', 'paidBy', 'project', 'paymentAccount', 'realizasis.items'])->where('user_id', $currentUser?->id);
        })

            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->alignCenter()
                    ->size('sm'),

                Tables\Columns\TextColumn::make('user.fullName')
                    ->label('User')
                    ->description(fn (Advance $record): string => $record->submission_date?->format('d M Y') ?? '', position: 'below')
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('keterangan_advance')
                    ->label('Keterangan')
                    ->sortable()
                    ->wrap()
                    ->limit(40),

                Tables\Columns\TextColumn::make('paid')
                    ->label('Dibayarkan')
                    ->sortable()
                    ->formatStateUsing(fn($state) => $state ? 'Rp ' . number_format((float)$state, 0, ',', '.') : '-')
                    ->color('info'),

                Tables\Columns\TextColumn::make('total_realisasi_display')
                    ->label('Terealisasi')
                    ->alignCenter()
                    ->getStateUsing(function (Advance $record): string {
                        $total = $record->realizasis->flatMap->items->sum('subtotal');
                        $paid = (float) ($record->paid ?? 0);
                        if ($paid <= 0) return '-';
                        $pct = round(($total / $paid) * 100);
                        return 'Rp ' . number_format($total, 0, ',', '.') . " ({$pct}%)";
                    })
                    ->badge()
                    ->color(fn (Advance $record): string => match(true) {
                        $record->realizasis->flatMap->items->sum('subtotal') >= (float)$record->paid => 'success',
                        default => 'warning',
                    }),

                Tables\Columns\TextColumn::make('remaining_display')
                    ->label('Sisa')
                    ->alignCenter()
                    ->getStateUsing(function (Advance $record): string {
                        $total = $record->realizasis->flatMap->items->sum('subtotal');
                        $paid = (float) ($record->paid ?? 0);
                        $remaining = $paid - $total;
                        if ($paid <= 0) return '-';
                        if ($remaining <= 0) return '-';
                        return 'Rp ' . number_format($remaining, 0, ',', '.');
                    })
                    ->color(fn (Advance $record): string => match(true) {
                        ($record->paid ?? 0) > 0 && (float)$record->paid <= $record->realizasis->flatMap->items->sum('subtotal') => 'success',
                        default => 'danger',
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->sortable()
                    ->badge()
                    ->alignCenter()
                    ->color(fn ($record) => ColorHelper::status($record->status))
                    ->description(fn($record) => match ($record->status) {
                        'Ditolak' => $record->rejection_reason ?? '',
                        'Dibayarkan' => $record->paidBy ? 'by ' . $record->paidBy->fullName : null,
                        default => null,
                    })
                    ->wrap(),

                Tables\Columns\TextColumn::make('batch_count')
                    ->label('Batch')
                    ->alignCenter()
                    ->getStateUsing(fn (Advance $record): string => (string) $record->realizasis->count())
                    ->badge()
                    ->color('gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'Pending' => 'Pending',
                        'Disetujui' => 'Disetujui',
                        'Ditolak' => 'Ditolak',
                        'Dibayarkan' => 'Dibayarkan',
                        'Sedang di-Realisasi' => 'Sedang di-Realisasi',
                        'Sedang di-Realisasi User' => 'Sedang di-Realisasi User',
                        'Siap Diselesaikan' => 'Siap Diselesaikan',
                        'Selesai' => 'Selesai',
                    ]),
                Tables\Filters\SelectFilter::make('payment_account_id')
                    ->label('Rekening')
                    ->options(fn () => PaymentAccount::active()->get()->mapWithKeys(fn ($account) => [$account->id => $account->name])),
            ])
            ->actions([
                Tables\Actions\Action::make('realisasi')
                ->link()
                ->size('xs')
                ->icon('heroicon-o-document-check')
                ->color('gray')
                ->label('Realisasi')
                ->url(fn ($record) => '/admin/advances/' . $record->id . '/realisasi')
                ->visible(fn ($record) => in_array($record->status, ['Dibayarkan', 'Sedang di-Realisasi User'])),

                Tables\Actions\Action::make('convert_to_pay')
                    ->button()
                    ->size('xs')
                    ->color('warning')
                    ->label('Bayarkan Pengajuan')
                    ->action(function ($record, array $data) {
                        /** @var User|null $currentUser */
                        $currentUser = Auth::user();

                        DB::transaction(function () use ($record, $data, $currentUser) {
                            // Get payment account with pessimistic locking
                            $account = PaymentAccount::lockForUpdate()->find($data['payment_account_id']);
                            if (!$account) {
                                throw ValidationException::withMessages([
                                    'payment_account_id' => 'Rekening tidak ditemukan.',
                                ]);
                            }

                            // Check balance using getBalance() method
                            $availableBalance = $account->getBalance();
                            if ($availableBalance < (float) $data['paid']) {
                                throw ValidationException::withMessages([
                                    'payment_account_id' => 'Saldo tidak mencukupi. Sisa: Rp ' . number_format($availableBalance, 0, ',', '.'),
                                ]);
                            }

                            // Create transaction record
                            Transaction::create([
                                'payment_account_id' => $account->id,
                                'created_by' => $currentUser?->id,
                                'type' => 'out',
                                'amount' => (float) $data['paid'],
                                'description' => "Advance: {$record->keterangan_advance}",
                                'reference_type' => 'advance',
                                'reference_id' => $record->id,
                                'transaction_date' => now()->toDateString(),
                                'category' => 'operational',
                            ]);

                            // Sync account balance after transaction
                            $account->syncBalanceFromTransactions();

                            // Update advance status with payment account
                            $record->update([
                                'status' => 'Dibayarkan',
                                'paid_by' => $currentUser?->id,
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
                            ->prefix('Rp')
                            ->default(fn($record) => $record->amount)
                            ->formatStateUsing(fn($state) => number_format((float) $state, 0, ',', '.'))
                            ->validationMessages([
                                'lte' => 'Nominal yang dibayarkan tidak boleh lebih dari jumlah pengeluaran yang diajukan.',
                            ]),
                        Forms\Components\TextInput::make('reference')
                            ->label('Nomor Referensi')
                            ->required(),
                        Forms\Components\FileUpload::make('attachment_pay')
                            ->label('Bukti Pembayaran')
                            ->acceptedFileTypes(['image/*', 'application/pdf'])
                            ->directory('advance/payments/' . now()->format('Y/m/d'))
                            ->required()
                            ->validationMessages([
                                'required' => 'Bukti pembayaran harus diunggah.',
                            ]),
                        Forms\Components\Select::make('payment_account_id')
                            ->label('Pilih Rekening')
                            ->searchable()
                            ->options(fn () => PaymentAccount::active()->get()->mapWithKeys(fn ($account) =>
                                [$account->id => "{$account->name} ({$account->bank_name}) - {$account->getFormattedBalance()}"]
                            ))
                            ->required(),
                        ])
                        ->requiresConfirmation()
                        ->color('success')
                        ->icon('heroicon-o-credit-card')
                        ->hidden(fn($record) =>
                        !Auth::user()?->can('restore_advance') ||
                        in_array($record->status, ['Pending','Dibayarkan','Selesai','Ditolak'])
                    ),

                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->action(function ($record) {
                        $record->update(['status' => 'Disetujui']);
                    })
                    ->requiresConfirmation()
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->hidden(fn($record) =>
                    !Auth::user()?->can('restore_advance') ||
                    in_array($record->status, ['Disetujui', 'Ditolak', 'Dibayarkan','Selesai'])
                ),

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
                    ->hidden(fn($record) =>
                    !Auth::user()?->can('restore_advance') ||
                    in_array($record->status, ['Disetujui', 'Ditolak', 'Dibayarkan','Selesai'])
                ),

                // Action untuk menyetujui overrun
                Tables\Actions\Action::make('approveOverrun')
                    ->label('Setujui Overrun')
                    ->action(function ($record) {
                        $service = new \App\Services\RealisasiService();
                        $service->approveOverrun($record);
                    })
                    ->requiresConfirmation()
                    ->color('warning')
                    ->icon('heroicon-o-check-circle')
                    ->hidden(fn($record) =>
                    !Auth::user()?->can('restore_advance') ||
                    $record->overrun_approved_by !== null ||
                    !in_array($record->status, ['Sedang di-Realisasi User'])
                ),


                Tables\Actions\Action::make('selesai')
                    ->label('Selesaikan Advance')
                    ->action(function ($record) {
                        $service = new \App\Services\RealisasiService();

                        // Check if ready to close
                        if (!$service->isReadyToClose($record)) {
                            throw new \InvalidArgumentException('Advance belum siap untuk diselesaikan. Pastikan total realization sudah sesuai atau overrun sudah disetujui.');
                        }

                        // Check if overrun exists and not approved
                        $totalRealisasi = $service->getTotalRealisasi($record);
                        $remaining = (float) $record->paid - $totalRealisasi;
                        if ($remaining < 0 && !$record->overrun_approved_by) {
                            throw new \InvalidArgumentException('Overrun belum disetujui Finance.');
                        }

                        // Finish the advance
                        $service->finishAdvance($record);
                    })
                    ->requiresConfirmation()
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->hidden(fn($record) =>
                    !Auth::user()?->can('restore_advance') ||
                    !in_array($record->status, ['Sedang di-Realisasi User'])
                ),

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
            'index' => Pages\ListAdvances::route('/'),
            'create' => Pages\CreateAdvance::route('/create'),
            'view' => Pages\ViewAdvance::route('/{record}'),
            'edit' => Pages\EditAdvance::route('/{record}/edit'),
            'realisasi' => Pages\RealisasiAdvance::route('/{record}/realisasi'),
        ];
    }
}
