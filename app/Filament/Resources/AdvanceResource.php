<?php

namespace App\Filament\Resources;

use App\Filament\Filters\SubmissionDateFilter;
use App\Enums\FinanceCategory;
use App\Filament\Resources\AdvanceResource\Pages;
use App\Filament\Resources\AdvanceResource\RelationManagers;
use App\Helpers\ColorHelper;
use App\Helpers\Money;
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
        $categories = FinanceCategory::options();

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
                    ->getOptionLabelFromRecordUsing(function (Project $record): string {
                        $namaPic = $record->deal?->nama_pic ?? '-';
                        $perusahaan = $record->deal?->nama_perusahaan ?? '-';
                        return "{$namaPic} - {$perusahaan} | {$record->nama_project}";
                    })
                    ->getSearchResultsUsing(fn (string $search): array => Project::with('deal')
                        ->whereHas('deal', fn ($q) => $q->where('nama_perusahaan', 'like', '%' . $search . '%'))
                        ->orWhereHas('deal', fn ($q) => $q->where('nama_pic', 'like', '%' . $search . '%'))
                        ->orWhere('nama_project', 'like', '%' . $search . '%')
                        ->limit(20)
                        ->get()
                        ->mapWithKeys(function ($project) {
                            $namaPic = $project->deal?->nama_pic ?? '-';
                            $perusahaan = $project->deal?->nama_perusahaan ?? '-';
                            return [$project->id => "{$namaPic} - {$perusahaan} | {$project->nama_project}"];
                        })
                        ->toArray()
                    )
                    ->options(fn (): array => Project::with('deal')->latest()->limit(50)->get()
                        ->mapWithKeys(function ($project) {
                            $namaPic = $project->deal?->nama_pic ?? '-';
                            $perusahaan = $project->deal?->nama_perusahaan ?? '-';
                            return [$project->id => "{$namaPic} - {$perusahaan} | {$project->nama_project}"];
                        })
                        ->toArray()
                    )
                    ->required(),

                Forms\Components\Select::make('category')
                    ->label('Kategori')
                    ->options($categories)
                    ->searchable()
                    ->required(),

                Forms\Components\Textarea::make('keterangan_advance')
                    ->label('Keterangan Advance')
                    ->required(),

                Forms\Components\TextInput::make('amount')
                    ->label('Nominal')
                    ->numeric()
                    ->required()
                    ->prefix('Rp')
                    ->formatStateUsing(fn($state) => Money::inputValue($state)),

                Forms\Components\Hidden::make('status')
                    ->default('Pending')
                    ->required(),
            ]);
    }

    /**
     * Approve/Reject advance yang masih Pending. Status dibaca ulang di dalam
     * kunci baris: dua orang yang menekan bersamaan, atau tampilan yang sudah
     * usang, tidak bisa mengubah advance yang sudah diproses.
     */
    protected static function decidePending(Advance $record, array $changes): void
    {
        $updated = DB::transaction(function () use ($record, $changes) {
            $advance = Advance::lockForUpdate()->find($record->getKey());

            if ($advance?->status !== Advance::STATUS_PENDING) {
                return false;
            }

            $advance->update($changes);

            return true;
        });

        if (! $updated) {
            \Filament\Notifications\Notification::make()
                ->title('Advance ini sudah diproses')
                ->body('Statusnya bukan Pending lagi. Muat ulang halaman untuk melihat status terbaru.')
                ->warning()
                ->send();
        }
    }

    public static function table(Table $table): Table
    {
        return $table
        ->modifyQueryUsing(function (Builder $query) {
            /** @var User|null $currentUser */
            $currentUser = Auth::user();

            // Jika user memiliki role 'director finance' atau 'super_admin', tampilkan semua data
            if ($currentUser?->hasRole(['Director','Finance', 'super_admin'])) {
                return $query->with(['user', 'paidBy', 'project', 'project.deal', 'paymentAccount', 'realizasis.items']);
            }

            // Jika bukan, hanya tampilkan data miliknya sendiri
            return $query->with(['user', 'paidBy', 'project', 'project.deal', 'paymentAccount', 'realizasis.items'])->where('user_id', $currentUser?->id);
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

                Tables\Columns\BadgeColumn::make('category')
                    ->label('Kategori')
                    ->sortable()
                    ->color('info'),

Tables\Columns\TextColumn::make('project.deal.nama_perusahaan')
    ->label('Project')
    ->sortable()
    ->wrap()
    ->formatStateUsing(function ($record) {
        $deal = $record->project?->deal;

        if (!$deal) {
            return '-';
        }

        $namaPic = $deal->nama_pic === '-' || empty($deal->nama_pic)
            ? '(Pribadi)'
            : $deal->nama_pic;

        return $namaPic . ' - ' . $deal->nama_perusahaan;
    }),

                Tables\Columns\TextColumn::make('paid')
                    ->label('Dibayarkan')
                    ->sortable()
                    ->formatStateUsing(fn($state) => $state ? 'Rp ' . number_format((float)$state, 0, ',', '.') : '-')
                    ->color('info'),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Diajukan')
                    ->sortable()
                    ->formatStateUsing(fn($state) => 'Rp ' . number_format((float)($state ?? 0), 0, ',', '.'))
                    ->color('primary'),

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
                SubmissionDateFilter::make(),
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
                            /*
                            | Kunci baris advance-nya, lalu baca ulang statusnya dari database.
                            | Tombol memang sudah disembunyikan lewat hidden(), tapi itu hanya
                            | berlaku saat halaman di-render: dua orang yang membuka daftar
                            | bersamaan sama-sama melihat tombolnya. Tanpa pemeriksaan di sini,
                            | klik kedua tetap diproses dan advance terbayar dua kali.
                            */
                            $advance = Advance::lockForUpdate()->find($record->id);

                            if (!$advance) {
                                throw ValidationException::withMessages([
                                    'payment_account_id' => 'Pengajuan tidak ditemukan.',
                                ]);
                            }

                            if ($advance->status !== 'Disetujui') {
                                throw ValidationException::withMessages([
                                    'payment_account_id' => $advance->status === 'Dibayarkan'
                                        ? 'Pengajuan ini sudah dibayarkan.'
                                        : "Pengajuan berstatus \"{$advance->status}\" dan tidak bisa dibayarkan.",
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
                                'created_by' => $currentUser?->id,
                                'type' => 'out',
                                'amount' => (float) $data['paid'],
                                'description' => "Advance: {$record->keterangan_advance}",
                                'reference_type' => 'advance',
                                'reference_id' => $record->id,
                                'transaction_date' => $data['paid_at'] ?? now()->toDateString(),
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
                                'reference_number' => $data['reference_number'] ?? null,
                                'attachment_pay' => $data['attachment_pay'] instanceof \Illuminate\Http\UploadedFile
                                    ? $data['attachment_pay']->storeAs('payments', uniqid() . '-' . now()->format('d-m-Y') . '.' . $data['attachment_pay']->getClientOriginalExtension())
                                    : $data['attachment_pay'],
                                'paid_at' => $data['paid_at'] ?? now(),
                            ]);
                        });
                    })
                    ->form([
                        Forms\Components\DatePicker::make('paid_at')
                            ->label('Tanggal Pembayaran')
                            ->default(now())
                            ->required(),
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
                        Forms\Components\TextInput::make('reference_number')
                            ->label('Nomor Referensi')
                            ->placeholder('Opsional'),
                        Forms\Components\FileUpload::make('attachment_pay')
                            ->label('Bukti Pembayaran')
                            ->acceptedFileTypes(['image/*', 'application/pdf'])
                            ->directory('advance/payments/' . now()->format('Y/m/d'))
                            ->placeholder('Opsional - Upload bukti pembayaran'),
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

                /*
                | Approve & Reject hanya untuk advance yang masih Pending. Dulu tombolnya
                | juga muncul pada advance yang sudah dicairkan (mis. "Sedang di-Realisasi
                | User"): Approve mengembalikannya ke Disetujui sehingga bisa dibayar kedua
                | kali, Reject membatalkan advance yang uangnya sudah keluar.
                */
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->action(fn (Advance $record) => static::decidePending($record, ['status' => Advance::STATUS_DISETUJUI]))
                    ->requiresConfirmation()
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->hidden(fn($record) =>
                    !Auth::user()?->can('restore_advance') ||
                    $record->status !== Advance::STATUS_PENDING
                ),

                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->action(fn (Advance $record, array $data) => static::decidePending($record, [
                        'status'           => Advance::STATUS_DITOLAK,
                        'rejection_reason' => $data['rejection_reason'],
                    ]))
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
                    $record->status !== Advance::STATUS_PENDING
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
