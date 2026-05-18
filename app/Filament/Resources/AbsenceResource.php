<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AbsenceResource\Pages;
use App\Helpers\ColorHelper;
use App\Models\Absence;
use Filament\Forms;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Form;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\Actions;
use Filament\Infolists\Components\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;

class AbsenceResource extends Resource
{
    protected static ?string $model = Absence::class;
    protected static ?string $navigationIcon = 'heroicon-o-clock';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationLabel = 'Absence';

    public static function getNavigationGroup(): ?string
    {
        return __('HR');
    }

    /* =========================================================
     * INFOLIST (View Page)
     * ========================================================= */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Informasi Pengajuan')
                ->columns(2)
                ->schema([
                    TextEntry::make('user.fullName')
                        ->label('Nama Lengkap'),

                    TextEntry::make('category')
                        ->label('Kategori')
                        ->badge()
                        ->color(fn ($record) => ColorHelper::absenceCategory($record->category)),

                    TextEntry::make('start_time')
                        ->label('Tanggal Mulai')
                        ->date('d M Y'),

                    TextEntry::make('end_time')
                        ->label('Tanggal Selesai')
                        ->date('d M Y'),

                    TextEntry::make('description')
                        ->label('Keterangan')
                        ->columnSpanFull(),
                ]),

            Section::make('Surat Sakit')
                ->visible(fn (Absence $record) => !empty($record->sick_letter))
                ->schema([
                    IconEntry::make('sick_letter')
                        ->label('Status Surat')
                        ->icon('heroicon-o-document')
                        ->color('danger'),

                    Actions::make([
                        Action::make('download_sick_letter')
                            ->label('Download / Lihat Surat')
                            ->icon('heroicon-o-arrow-down-tray')
                            ->url(fn (Absence $record) => asset('storage/' . $record->sick_letter))
                            ->openUrlInNewTab(),
                    ]),
                ]),

            Section::make('Status Approval')
                ->collapsible()
                ->schema([
                    TextEntry::make('approval_status')
                        ->label('Status')
                        ->badge()
                        ->color(fn ($record) => ColorHelper::absenceStatus($record->approval_status)),

                    TextEntry::make('approvedBy.fullName')
                        ->label('Diproses Oleh')
                        ->visible(fn (Absence $record) => !empty($record->approved_by)),

                    TextEntry::make('rejection_reason')
                        ->label('Alasan Penolakan')
                        ->visible(fn (Absence $record) =>
                            !empty($record->rejection_reason)
                        ),
                ])->visible(fn () =>
                    auth()->user()->hasRole(['Head', 'Director', 'Finance', 'super_admin'])
                ),
        ]);
    }

    /* =========================================================
     * FORM
     * ========================================================= */
    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Hidden::make('user_id')
                ->default(auth()->id()),

            Forms\Components\Select::make('category')
                ->label('Kategori Ketidakhadiran')
                ->options([
                    'cuti' => 'Cuti',
                    'wfa' => 'WFA',
                    'tukar_hari' => 'Tukar Hari',
                    'sakit' => 'Sakit',
                ])
                ->required()
                ->reactive(),

            Forms\Components\Textarea::make('description')
                ->label('Keterangan')
                ->rows(3),

            Forms\Components\DatePicker::make('start_time')
                ->label('Waktu Mulai')
                ->required()
                ->displayFormat('d M Y')
                ->closeOnDateSelection()
                ->native(false),

            Forms\Components\DatePicker::make('end_time')
                ->label('Waktu Selesai')
                ->required()
                ->displayFormat('d M Y')
                ->rule('after_or_equal:start_time')
                ->closeOnDateSelection()
                ->native(false),

            // Upload Surat Sakit - hanya muncul saat category = sakit
            FileUpload::make('sick_letter')
                ->label('Surat Dokter / Surat Sakit')
                ->visible(fn (callable $get) => $get('category') === 'sakit')
                ->helperText('Format: JPG, JPEG, PDF. Maksimal 2MB.')
                ->directory('sick-letters/' . now()->format('Y-m'))
                ->acceptedFileTypes(['image/jpeg', 'image/jpg', 'application/pdf'])
                ->maxSize(2048) // 2MB
                ->downloadable()
                ->openable()
                ->nullable()
                ->columnSpanFull(),
        ]);
    }

    /* =========================================================
     * TABLE
     * ========================================================= */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $user = auth()->user();
                if (!$user->hasRole(['Director', 'Finance', 'Head', 'super_admin'])) {
                    return $query->where('user_id', $user->id)->with(['user', 'approvedBy']);
                }
                return $query->with(['user', 'approvedBy']);
            })
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('user.fullName')
                    ->label('Nama Lengkap')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('category')
                    ->label('Kategori')
                    ->alignCenter()
                    ->badge()
                    ->color(fn ($record) => ColorHelper::absenceCategory($record->category))
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'cuti' => 'Cuti',
                        'wfa' => 'WFA',
                        'tukar_hari' => 'Tukar Hari',
                        'sakit' => 'Sakit',
                        default => $state,
                    }),

                TextColumn::make('description')
                    ->label('Keterangan')
                    ->limit(30)
                    ->tooltip(fn ($record) => $record->description),

                TextColumn::make('start_time')
                    ->label('Mulai')
                    ->date('d M Y'),

                TextColumn::make('end_time')
                    ->label('Selesai')
                    ->date('d M Y'),

                IconColumn::make('sick_letter')
                    ->label('Surat Sakit')
                    ->boolean()
                    ->alignCenter()
                    ->trueIcon('heroicon-o-document')
                    ->falseIcon('heroicon-o-x-mark')
                    ->trueColor('danger')
                    ->falseColor('gray'),

                TextColumn::make('approval_status')
                    ->label('Status')
                    ->alignCenter()
                    ->badge()
                    ->color(fn ($record) => ColorHelper::absenceStatus($record->approval_status))
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'Pending',
                        'approved' => 'Disetujui',
                        'rejected' => 'Ditolak',
                        default => $state,
                    })
                    ->description(fn ($record) =>
                        $record->approval_status !== 'pending' && $record->approvedBy
                            ? ' oleh ' . $record->approvedBy->fullName
                            : null
                    ),

                TextColumn::make('created_at')
                    ->label('Diajukan')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->label('Kategori')
                    ->options([
                        'cuti' => 'Cuti',
                        'wfa' => 'WFA',
                        'tukar_hari' => 'Tukar Hari',
                        'sakit' => 'Sakit',
                    ]),

                Tables\Filters\SelectFilter::make('approval_status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Disetujui',
                        'rejected' => 'Ditolak',
                    ]),
            ])

            ->actions([

                // ACTION: Lihat Surat Sakit
                Tables\Actions\Action::make('view_sick_letter')
                    ->label('Lihat Surat')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('danger')
                    ->url(fn ($record) =>
                        $record->sick_letter ? asset('storage/' . $record->sick_letter) : null
                    )
                    ->openUrlInNewTab()
                    ->visible(fn ($record) => !empty($record->sick_letter)),

                // ACTION: Approve
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->requiresConfirmation()
                    ->modalHeading('Approve Pengajuan')
                    ->modalDescription(fn ($record) =>
                        $record->category === 'sakit' && $record->sick_letter
                            ? 'Pengajuan sakit dengan surat dokter. Pastikan surat sudah diverifikasi.'
                            : 'Approve pengajuan ini?'
                    )
                    ->action(function ($record) {
                        $record->update([
                            'approval_status' => 'approved',
                            'approved_by' => auth()->id(),
                        ]);

                        Notification::make()
                            ->title('Berhasil Disetujui')
                            ->body('Pengajuan ' . ucfirst($record->category) . ' telah disetujui.')
                            ->success()
                            ->sendToDatabase($record->user);

                        // Broadcast
                        \Illuminate\Support\Facades\Notification::send(
                            $record->user,
                            \App\Notifications\AbsenceApprovalNotification::make(
                                $record,
                                'approved'
                            )
                        );
                    })
                    ->visible(fn ($record) =>
                        $record->approval_status === 'pending' &&
                        auth()->user()->hasRole(['Head', 'Director', 'Finance', 'super_admin'])
                    ),

                // ACTION: Reject dengan alasan
                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->modalHeading('Tolak Pengajuan')
                    ->modalWidth('lg')
                    ->form([
                        Forms\Components\Textarea::make('rejection_reason')
                            ->label('Alasan Penolakan')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function ($record, array $data) {
                        $record->update([
                            'approval_status' => 'rejected',
                            'approved_by' => auth()->id(),
                            'rejection_reason' => $data['rejection_reason'],
                        ]);

                        Notification::make()
                            ->title('Pengajuan Ditolak')
                            ->body('Pengajuan ' . ucfirst($record->category) . ' ditolak. Alasan: ' . $data['rejection_reason'])
                            ->warning()
                            ->sendToDatabase($record->user);

                        \Illuminate\Support\Facades\Notification::send(
                            $record->user,
                            \App\Notifications\AbsenceApprovalNotification::make(
                                $record,
                                'rejected'
                            )
                        );
                    })
                    ->visible(fn ($record) =>
                        $record->approval_status === 'pending' &&
                        auth()->user()->hasRole(['Head', 'Director', 'Finance', 'super_admin'])
                    ),

                // ACTION: Isi Laporan WFA
                Tables\Actions\Action::make('isi_laporan')
                    ->label('Isi Laporan')
                    ->color('primary')
                    ->icon('heroicon-o-document-text')
                    ->modalHeading('Laporan WFA')
                    ->form([
                        Forms\Components\Textarea::make('work_report')
                            ->label('Apa saja yang dikerjakan saat WFA?')
                            ->required()
                            ->rows(5),
                    ])
                    ->action(function ($record, array $data) {
                        $record->update(['work_report' => $data['work_report']]);

                        Notification::make()
                            ->title('Laporan berhasil disimpan')
                            ->success()
                            ->send();
                    })
                    ->visible(fn ($record) =>
                        $record->category === 'wfa' &&
                        $record->approval_status === 'approved' &&
                        empty($record->work_report)
                    ),

                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])

            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAbsences::route('/'),
            'create' => Pages\CreateAbsence::route('/create'),
            'edit' => Pages\EditAbsence::route('/{record}/edit'),
        ];
    }
}