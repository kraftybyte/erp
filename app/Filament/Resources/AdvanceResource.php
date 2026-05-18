<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AdvanceResource\Pages;
use App\Filament\Resources\AdvanceResource\RelationManagers;
use App\Helpers\ColorHelper;
use App\Models\Advance;
use App\Models\Budget;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Storage;
use App\Models\Project;

class AdvanceResource extends Resource 
{
    protected static ?string $model = Advance::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

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
                    ->default(fn() => auth()->id())
                    ->required(),

                Forms\Components\DatePicker::make('submission_date')
                    ->label('Tanggal Pengajuan')
                    ->default(now())
                    ->required(),

                Forms\Components\Select::make('project_id')
                    ->label('Pilih Project')
                    ->options(Project::all()->pluck('nama_perusahaan', 'id'))
                    ->searchable()
                    ->required(),

                Forms\Components\Textarea::make('keterangan_advance')
                    ->label('Keterangan Expense')
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
            $user = auth()->user();

            // Jika user memiliki role 'director finance' atau 'super_admin', tampilkan semua data
            if ($user->hasRole(['Director','Finance', 'super_admin'])) {
                return $query->with(['user', 'paidBy', 'project']);
            }

            // Jika bukan, hanya tampilkan data miliknya sendiri
            return $query->with(['user', 'paidBy', 'project'])->where('user_id', $user->id);
        })
        
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID Advance')
                    ->sortable()
                    ->wrap()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('user.fullName')
                    ->label('Permohonan User')
                    ->description(fn (Advance $record): string => $record->submission_date ?? '', position: 'after')
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('keterangan_advance')
                    ->label('Keterangan')
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Nominal')
                    ->sortable()
                    ->formatStateUsing(fn($state) => 'Rp ' . number_format($state, 2, ',', '.')),
                

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->sortable()
                    ->badge()
                    ->alignCenter()
                    ->color(fn ($record) => ColorHelper::status($record->status))
                    ->description(fn($record) => match ($record->status) {
                        'Ditolak' => trans($record->rejection_reason ?? ''),
                        'Dibayarkan' => $record->paidBy
                            ? 'oleh ' . $record->paidBy->fullName . ' pada ' . \Carbon\Carbon::parse($record->updated_at)->format('d M Y H:i')
                            : 'oleh unknown',
                        default => null,
                    })
                    ->wrap(),

                Tables\Columns\TextColumn::make('attachment')
                    ->label('Lampiran Invoice')
                    ->badge()
                    ->alignCenter()
                    ->formatStateUsing(fn($state) => $state ? 'Link Invoice' : 'Belum Ada')
                    ->action(function ($record) {
                        if ($record && $record->attachment) {
                            return redirect()->to(asset('storage/' . $record->attachment));
                        } else {
                            return back()->with('Error', 'Attachment not found.');
                        }
                    })
                    ->sortable()
                    ->alignCenter()
                    ->url(fn($record) => $record->attachment ? asset('storage/' . $record->attachment) : null)
                    ->openUrlInNewTab(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\Action::make('upload_invoice')
                ->button()
                ->size('xs')
                ->icon('heroicon-o-arrow-up-circle')
                ->color('danger')
                ->label('Realisasi Advance')
                ->action(function ($record, array $data) {
                    if ($data['attachment'] instanceof \Illuminate\Http\UploadedFile) {
                        $path = $data['attachment']->storeAs('advance', uniqid() . '-' . now()->format('d-m-Y') . '.' . $data['attachment']->getClientOriginalExtension());
                        if ($path) {
                            $record->update([
                                'attachment' => $path,
                                'status' => 'Sudah di-Realisasi User',
                            ]);
                        } else {
                            return back()->with('error', 'File upload failed.');
                        }
                    } else {
                        $record->update([
                            'attachment' => $data['attachment'],
                            'status' => 'Sudah di-Realisasi User',

                        ]);
                    }

                })
                ->form([
                    Forms\Components\Repeater::make('items')
                    ->label('Item Pengeluaran')
                    ->relationship('itemAdvance')
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
                        ->acceptedFileTypes(['image/*', 'application/pdf'])
                        ->directory('advance/invoice/' . now()->format('Y/m/d'))
                        ->required()
                        ->validationMessages([
                            'required' => 'Bukti pembayaran harus diunggah.',
                        ]),
                ])
                ->hidden(fn($record) => $record->status !== 'Dibayarkan'),

                Tables\Actions\Action::make('convert_to_pay')
                    ->button()
                    ->size('xs')
                    ->color('warning')
                    ->label('Bayarkan Pengajuan')
                    ->action(function ($record, array $data) {
                        DB::transaction(function () use ($record, $data) {
                            $record->update([
                                'status' => 'Dibayarkan',
                                'paid_by' => auth()->id(),
                                'paid' => $data['paid'],
                                'reference' => $data['reference'],
                                'attachment_pay' => $data['attachment_pay'] instanceof \Illuminate\Http\UploadedFile ? $data['attachment_pay']->storeAs('payments', uniqid() . '-' . now()->format('d-m-Y') . '.' . $data['attachment_pay']->getClientOriginalExtension()) : $data['attachment_pay'],
                            ]);
                        });
                    })
                    ->form([
                        Forms\Components\TextInput::make('paid')
                            ->label('Nominal yang Dibayarkan')
                            ->numeric()
                            ->required()
                            ->prefix('Rp')
                            ->validationMessages([
                                'lte' => 'Nominal yang dibayarkan tidak boleh lebih dari jumlah pengeluaran yang diajukan.',
                            ])
                            ->formatStateUsing(fn($state) => number_format($state, 0, ',', '.')),
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
                        Forms\Components\Select::make('budget_id')
                            ->label('Pilih Budget')
                            ->options(Budget::all()->pluck('name', 'id'))
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(function ($state, callable $set, $get, $record) {
                                $budget = Budget::find($get('budget_id'));
                                if ($budget) {
                                    $budget->update([
                                        'amount' => $budget->amount - $get('paid'),
                                    ]);
                                }
                            }),
                        ])
                        ->requiresConfirmation()
                        ->color('success')
                        ->icon('heroicon-o-currency-dollar')
                        ->hidden(fn($record) => 
                        !auth()->user()->can('restore_advance') || 
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
                    !auth()->user()->can('restore_advance') || 
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
                    !auth()->user()->can('restore_advance') || 
                    in_array($record->status, ['Disetujui', 'Ditolak', 'Dibayarkan','Selesai'])
                ),


                Tables\Actions\Action::make('selesai')
                    ->label('Selesaikan Advance')
                    ->action(function ($record) {
                        $record->update(['status' => 'Selesai']);
                    })
                    ->requiresConfirmation()
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->hidden(fn($record) => 
                    !auth()->user()->can('restore_advance') || 
                    in_array($record->status, ['Disetujui', 'Ditolak', 'Dibayarkan','Pending'])
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
        ];
    }
}
