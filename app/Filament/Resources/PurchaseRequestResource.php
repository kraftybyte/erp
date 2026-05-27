<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PurchaseRequestResource\Pages;
use App\Helpers\ColorHelper;
use App\Models\PurchaseRequest;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Actions\Action;
use Filament\Tables\Table;
use Filament\Forms\Components\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Filament\Notifications\Notification;

class PurchaseRequestResource extends Resource
{
    protected static ?string $model = PurchaseRequest::class;
    protected static ?string $navigationLabel = 'Purchase Request';
    protected static ?string $navigationIcon = 'heroicon-o-scissors';


    public static function getNavigationGroup(): ?string
    {
        return __("Management");
    }

    public static function getBreadcrumb(): string
    {
        return 'Purchase Request'; // Ganti dengan nama breadcrumb yang Anda inginkan
    }


    public static function form(Form $form): Form

{
    return $form
        ->schema([
            Forms\Components\Hidden::make('requested_by')
            ->default(fn () => auth()->id()) // Memastikan ID user yang login digunakan
            ->dehydrated()
            ->required(),

            Forms\Components\TextInput::make('item_name')
                ->required()
                ->label('Nama Barang')
                ->placeholder('Contoh: Laptop Dell Inspiron')
                ->helperText('Masukkan nama barang yang ingin dibeli'),

            Forms\Components\TextInput::make('quantity')
                ->numeric()
                ->required()
                ->label('Jumlah')
                ->placeholder('Contoh: 2')
                ->helperText('Jumlah barang yang ingin dibeli'),

            Forms\Components\Textarea::make('description')
                ->label('Deskripsi Barang')
                ->placeholder('Contoh: Laptop untuk keperluan kantor dengan spesifikasi RAM 8GB, SSD 512GB')
                ->helperText('Deskripsikan barang secara detail'),

            Forms\Components\TextInput::make('reference_link')
                ->url()
                ->label('Link Referensi')
                ->placeholder('Contoh: https://www.tokopedia.com/laptop-dell')
                ->helperText('Tambahkan link marketplace atau referensi lainnya'),

            Forms\Components\TextInput::make('price')
                ->numeric()
                ->prefix('Rp')
                ->label('Harga')
                ->placeholder('Contoh: 7500000')
                ->helperText('Masukkan harga barang tanpa titik/koma'),

            Forms\Components\Select::make('status')
                ->options([
                    'Pending' => 'Pending',
                    'Disetujui' => 'Disetujui',
                    'Ditolak' => 'Ditolak',
                ])
                ->default('Pending')
                ->hidden()
                ->label('Status'),

            Forms\Components\Textarea::make('reason')
                ->label('Alasan')
                ->placeholder('Contoh: Barang ini dibutuhkan segera untuk proyek baru')
                ->helperText('Berikan alasan jika permintaan ini disetujui atau ditolak')
                ->hidden(fn ($get) => $get('status') === 'Pending')
                ->required(fn ($get) => in_array($get('status'), ['Disetujui', 'Ditolak'])),
        ]);
}


    public static function table(Table $table): Table
    {
        return $table
        ->modifyQueryUsing(function (Builder $query) {
            $user = auth()->user();
            if ($user->hasRole(['Director','Finance', 'super_admin'])) {
                return $query->with(['user']);
            }
            return $query->where('requested_by', $user->id)->with(['user']);
        })
        
            ->columns([
                Tables\Columns\TextColumn::make('item_name')
                ->label('Nama Barang')
                ->searchable(),
            
                Tables\Columns\TextColumn::make('quantity')
                ->label('Jumlah'),
            
                Tables\Columns\TextColumn::make('price')
                ->money('IDR')
                ->label('Harga'),

                Tables\Columns\TextColumn::make('reference_link')
                ->label('Link Referensi')
                ->url(fn ($record) => $record->reference_link, true)
                ->limit(30),

                Tables\Columns\TextColumn::make('status')
                ->label('Status')
                ->sortable()
                ->badge()
                ->color(fn ($record) => ColorHelper::status($record->status))
                ->tooltip(fn($record) => $record?->status === 'Ditolak' ? $record->reason : null) // Tooltip hanya saat Rejected
                ->wrap()
                ->alignment('center'), // Menengahkan teks
            

            Tables\Columns\TextColumn::make('user.firstname')
                ->label('User Request')
                ->sortable()
                ->searchable(),
            
            
            Tables\Columns\TextColumn::make('created_at')
                ->label('Tanggal Request')
                ->dateTime('d M Y, H:i')
                ->sortable(),

            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'Pending' => 'Pending',
                        'Disetujui' => 'Disetujui',
                        'Ditolak' => 'Ditolak',
                    ]),
            ])
            ->actions([
            Tables\Actions\Action::make('approve')
                ->label('Approve')
                ->action(function ($record) {
                    $record->update(['status' => 'Disetujui']);

                    Notification::make()
                        ->title('Purchase Request Disetujui')
                        ->body("Permintaan {$record->item_name} telah disetujui.")
                        ->success()
                        ->sendToDatabase($record->user);

                    \Illuminate\Support\Facades\Notification::send(
                        $record->user,
                        \App\Notifications\PurchaseRequestApprovalNotification::make($record, 'Disetujui')
                    );
                })
                ->requiresConfirmation()
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->hidden(fn() => !auth()->check() || !auth()->user()->hasRole(['Finance', 'super_admin'])),

            Tables\Actions\Action::make('reject')
                ->label('Reject')
                ->action(function ($record, array $data) {
                    $record->update([
                        'status' => 'Ditolak',
                        'reason' => $data['reason'],
                    ]);

                    Notification::make()
                        ->title('Purchase Request Ditolak')
                        ->body("Permintaan {$record->item_name} ditolak. Alasan: {$data['reason']}")
                        ->warning()
                        ->sendToDatabase($record->user);

                    \Illuminate\Support\Facades\Notification::send(
                        $record->user,
                        \App\Notifications\PurchaseRequestApprovalNotification::make($record, 'Ditolak')
                    );
                })
                ->form([
                    Forms\Components\Textarea::make('reason')
                        ->label('Alasan Penolakan')
                        ->required()
                        ->placeholder('Berikan alasan mengapa permintaan ini ditolak'),
                ])
                ->requiresConfirmation()
                ->color('danger')
                ->icon('heroicon-o-x-circle')
                ->hidden(fn() => !auth()->check() || !auth()->user()->hasRole(['Finance', 'super_admin'])),



                Tables\Actions\EditAction::make(),
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
            'index' => Pages\ListPurchaseRequests::route('/'),
            'create' => Pages\CreatePurchaseRequest::route('/create'),
            'edit' => Pages\EditPurchaseRequest::route('/{record}/edit'),
        ];
    }
}
