<?php

namespace App\Filament\Resources;

use App\Exceptions\PayoutException;
use App\Filament\Resources\PayslipResource\Pages;
use App\Helpers\Money;
use App\Models\PaymentAccount;
use App\Models\PayrollComponent;
use App\Models\Payslip;
use App\Services\Payroll;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Slip gaji bulanan (HR → Slip Gaji). Yang punya izin update_payslip (Shield)
 * mengelola semua slip; karyawan lain hanya melihat dan mengunduh slipnya
 * sendiri yang sudah final.
 */
class PayslipResource extends Resource
{
    protected static ?string $model = Payslip::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Slip Gaji';

    protected static ?string $modelLabel = 'slip gaji';

    protected static ?string $pluralModelLabel = 'slip gaji';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return __('HR');
    }

    // Akses lewat PayslipPolicy (izin Shield: Roles → Payslip). Slip hanya
    // dibuat lewat "Buat slip", tidak ada halaman Create.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('user');

        return Payroll::canManage(auth()->user())
            ? $query
            : $query->where('user_id', auth()->id())->where('status', '!=', Payslip::DRAFT);
    }

    public static function form(Form $form): Form
    {
        $locked = fn (?Payslip $record) => ! $record?->isDraft();

        return $form->schema([
            Forms\Components\Section::make(fn (?Payslip $record) => $record ? "{$record->employee_name} · {$record->periodLabel()}" : 'Slip gaji')
                ->description(fn (?Payslip $record) => match ($record?->status) {
                    Payslip::DRAFT => 'Draft: baris dari komponen tetap karyawan. Tambahkan penyesuaian bulan ini (lembur, bonus, potongan sekali), lalu Final & kirim.',
                    Payslip::FINAL => 'Final' . ($record->sent_at ? ', sudah dikirim ke Telegram karyawan.' : '.') . ' Kembalikan ke draft untuk mengubah.',
                    Payslip::PAID  => 'Sudah dibayar ' . Carbon::parse($record->paid_at)->locale('id')->translatedFormat('j M Y') . '.',
                    default        => null,
                })
                ->schema([
                    Forms\Components\Repeater::make('items')
                        ->relationship()
                        ->label('Rincian')
                        ->orderColumn('sort')
                        ->schema([
                            Forms\Components\TextInput::make('name')->label('Komponen')->required()->maxLength(100)->columnSpan(3),
                            Forms\Components\Select::make('type')->label('Jenis')->options(PayrollComponent::TYPES)->default(PayrollComponent::EARNING)->required()->native(false)->columnSpan(2),
                            Forms\Components\TextInput::make('amount')->label('Nominal')->numeric()->prefix('Rp')->minValue(0)->required()->live(onBlur: true)->columnSpan(2),
                        ])
                        ->columns(7)
                        ->addActionLabel('Tambah penyesuaian')
                        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => $data + ['from_component' => false])
                        ->live()
                        ->disabled($locked),

                    Forms\Components\Placeholder::make('totals')
                        ->label('Take home pay')
                        ->content(function (Get $get) {
                            $items = collect($get('items') ?? []);
                            $sum   = fn (string $type) => (float) $items->where('type', $type)->sum(fn ($item) => (float) ($item['amount'] ?? 0));
                            $in    = $sum(PayrollComponent::EARNING);
                            $out   = $sum(PayrollComponent::DEDUCTION);

                            return Money::rupiah($in - $out) . ' (pendapatan ' . Money::rupiah($in) . ' − potongan ' . Money::rupiah($out) . ')';
                        }),

                    Forms\Components\Textarea::make('notes')->label('Catatan di slip')->rows(2)->maxLength(500)->disabled($locked),
                ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        $manager = fn () => Payroll::canManage(auth()->user());
        $money   = fn ($state) => Money::rupiah($state);

        return $table
            ->defaultSort('period', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('period')
                    ->label('Bulan')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => Carbon::parse($state)->locale('id')->translatedFormat('F Y')),
                Tables\Columns\TextColumn::make('employee_name')
                    ->label('Karyawan')
                    ->searchable()
                    ->description(fn (Payslip $record) => collect([$record->position, $record->division])->filter()->implode(' · ') ?: null),
                Tables\Columns\TextColumn::make('earnings')->label('Pendapatan')->alignEnd()->formatStateUsing($money)->toggleable(),
                Tables\Columns\TextColumn::make('deductions')->label('Potongan')->alignEnd()->formatStateUsing($money)->toggleable(),
                Tables\Columns\TextColumn::make('net')->label('Take home pay')->alignEnd()->weight('bold')->formatStateUsing($money)->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Payslip::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Payslip::DRAFT => 'gray',
                        Payslip::FINAL => 'warning',
                        Payslip::PAID  => 'success',
                        default        => 'gray',
                    })
                    ->description(fn (Payslip $record) => match (true) {
                        $record->status === Payslip::PAID => Carbon::parse($record->paid_at)->locale('id')->translatedFormat('j M Y'),
                        $record->status === Payslip::FINAL => $record->sent_at ? 'terkirim, belum dibayar' : 'belum dibayar',
                        default => null,
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('period')
                    ->label('Bulan')
                    ->options(fn () => Payslip::query()->select('period')->distinct()->orderByDesc('period')->pluck('period')
                        ->mapWithKeys(fn ($period) => [Carbon::parse($period)->toDateString() => Carbon::parse($period)->locale('id')->translatedFormat('F Y')])
                        ->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $value) => $query->whereDate('period', $value))),
                Tables\Filters\SelectFilter::make('status')->label('Status')->options(Payslip::STATUSES),
            ])
            ->actions([
                Tables\Actions\Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(fn (Payslip $record) => route('payslip.pdf', $record))
                    ->openUrlInNewTab(),

                Tables\Actions\EditAction::make()
                    ->label(fn (Payslip $record) => $record->isDraft() ? 'Ubah' : 'Lihat')
                    ->icon(fn (Payslip $record) => $record->isDraft() ? 'heroicon-o-pencil-square' : 'heroicon-o-eye'),

                Tables\Actions\ActionGroup::make([
                    self::finalizeAction(Tables\Actions\Action::make('finalize')),
                    self::payAction(Tables\Actions\Action::make('pay')),
                    Tables\Actions\Action::make('send')
                        ->label('Kirim ulang ke Telegram')
                        ->icon('heroicon-o-paper-airplane')
                        ->visible(fn (Payslip $record) => $manager() && ! $record->isDraft())
                        ->action(fn (Payslip $record) => app(Payroll::class)->send($record)
                            ? Notification::make()->title('Slip dikirim ke Telegram karyawan')->success()->send()
                            : Notification::make()->title('Tidak terkirim')->body('Karyawan belum menghubungkan akun Telegram, atau bot belum diatur.')->warning()->send()),
                    Tables\Actions\Action::make('refresh')
                        ->label('Muat ulang komponen')
                        ->icon('heroicon-o-arrow-path')
                        ->visible(fn (Payslip $record) => $manager() && $record->isDraft())
                        ->requiresConfirmation()
                        ->modalDescription('Baris dari komponen tetap diganti dengan komponen karyawan saat ini. Penyesuaian bulan ini tetap.')
                        ->action(fn (Payslip $record) => app(Payroll::class)->refresh($record)),
                    Tables\Actions\Action::make('reopen')
                        ->label('Kembalikan ke draft')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->visible(fn (Payslip $record) => $manager() && $record->status === Payslip::FINAL)
                        ->requiresConfirmation()
                        ->action(fn (Payslip $record) => app(Payroll::class)->reopen($record)),
                    Tables\Actions\DeleteAction::make(),
                ])->visible($manager),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('finalizeAll')
                        ->label('Final & kirim')
                        ->icon('heroicon-o-check-badge')
                        ->requiresConfirmation()
                        ->modalDescription('Slip draft yang dipilih dikunci dan dikirim ke Telegram masing-masing karyawan.')
                        ->action(function (Collection $records) {
                            $drafts = $records->filter->isDraft();
                            $drafts->each(fn (Payslip $slip) => app(Payroll::class)->finalize($slip, auth()->user()));

                            Notification::make()->title($drafts->count() . ' slip difinalkan')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('payAll')
                        ->label('Tandai dibayar')
                        ->icon('heroicon-o-banknotes')
                        ->form(self::paymentFields())
                        ->action(function (Collection $records, array $data) {
                            $account = PaymentAccount::findOrFail($data['payment_account_id']);
                            $final   = $records->where('status', Payslip::FINAL);
                            $final->each(fn (Payslip $slip) => app(Payroll::class)->pay($slip, $account, auth()->user(), Carbon::parse($data['paid_at'])));

                            Notification::make()
                                ->title($final->count() . ' slip dibayar · ' . Money::rupiah($final->sum('net')))
                                ->body($records->count() > $final->count() ? 'Slip draft atau yang sudah dibayar dilewati.' : null)
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make()
                        ->action(fn (Collection $records) => $records->filter(fn (Payslip $slip) => auth()->user()->can('delete', $slip))->each->delete()),
                ])->visible($manager),
            ])
            ->emptyStateHeading('Belum ada slip gaji')
            ->emptyStateDescription(fn () => Payroll::canManage(auth()->user())
                ? 'Isi komponen gaji di HR → Employee (bagian Gaji), lalu tekan "Buat slip".'
                : 'Slip gajimu muncul di sini setelah difinalkan Finance.');
    }

    /**
     * Final & kirim satu slip (dipakai tabel dan halaman slip).
     */
    public static function finalizeAction(Tables\Actions\Action|\Filament\Actions\Action $action): Tables\Actions\Action|\Filament\Actions\Action
    {
        return $action
            ->label('Final & kirim')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (Payslip $record) => Payroll::canManage(auth()->user()) && $record->isDraft())
            ->requiresConfirmation()
            ->modalDescription(fn (Payslip $record) => 'Slip dikunci dengan take home pay ' . Money::rupiah($record->net)
                . (Payroll::settings()['send_telegram'] ? ' dan PDF-nya dikirim ke Telegram karyawan.' : '.'))
            ->action(function (Payslip $record) {
                app(Payroll::class)->finalize($record, auth()->user());

                Notification::make()
                    ->title('Slip difinalkan')
                    ->body($record->fresh()->sent_at ? 'PDF sudah dikirim ke Telegram karyawan.' : 'Belum terkirim ke Telegram (akun karyawan belum terhubung atau pengiriman dimatikan).')
                    ->success()
                    ->send();
            });
    }

    public static function payAction(Tables\Actions\Action|\Filament\Actions\Action $action): Tables\Actions\Action|\Filament\Actions\Action
    {
        return $action
            ->label('Tandai dibayar')
            ->icon('heroicon-o-banknotes')
            ->color('warning')
            ->visible(fn (Payslip $record) => Payroll::canManage(auth()->user()) && $record->status === Payslip::FINAL)
            ->modalDescription(fn (Payslip $record) => 'Take home pay ' . Money::rupiah($record->net) . ' dicatat sebagai uang keluar kategori Gaji.')
            ->form(self::paymentFields())
            ->action(function (Payslip $record, array $data) {
                try {
                    app(Payroll::class)->pay($record, PaymentAccount::findOrFail($data['payment_account_id']), auth()->user(), Carbon::parse($data['paid_at']));
                } catch (PayoutException $e) {
                    Notification::make()->title('Gagal dicatat')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Pembayaran gaji dicatat')->success()->send();
            });
    }

    private static function paymentFields(): array
    {
        return [
            Forms\Components\Select::make('payment_account_id')
                ->label('Dari rekening')
                ->options(fn () => PaymentAccount::active()->orderBy('name')->pluck('name', 'id'))
                ->required(),
            Forms\Components\DatePicker::make('paid_at')->label('Tanggal bayar')->default(now())->maxDate(now())->required(),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayslips::route('/'),
            'edit'  => Pages\EditPayslip::route('/{record}/edit'),
        ];
    }
}
