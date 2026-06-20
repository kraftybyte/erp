<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SocialAccountResource\Pages;
use App\Helpers\ColorHelper;
use App\Jobs\SyncSocialAccountJob;
use App\Models\SocialAccount;
use App\Services\InstagramService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;

class SocialAccountResource extends Resource
{
    protected static ?string $model = SocialAccount::class;
    protected static ?string $navigationIcon = 'heroicon-o-photo';

    public static function getNavigationLabel(): string
    {
        return \App\Helpers\NavigationHelper::getLabel('social_account', 'Social account');
    }
    protected static ?string $navigationLabel = 'Social Accounts';
    protected static ?string $navigationGroup = 'Tools';

        public static function canCreate(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }


    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('client_id')
                ->relationship('client', 'nama_perusahaan')
                ->searchable()
                ->required()
                ->label('Klien'),

            Forms\Components\Select::make('platform')
                ->options([
                    'instagram' => 'Instagram',
                    'facebook'  => 'Facebook Page',
                    'tiktok'    => 'TikTok',
                ])
                ->required()
                ->live(),

            Forms\Components\TextInput::make('platform_account_id')
                ->label('Account ID / Page ID')
                ->required()
                ->helperText('ID numerik dari Instagram Business atau Facebook Page'),

            Forms\Components\TextInput::make('username')
                ->label('Username Instagram')
                ->visible(fn ($get) => $get('platform') === 'instagram'),

            Forms\Components\TextInput::make('page_name')
                ->label('Nama Facebook Page')
                ->visible(fn ($get) => $get('platform') === 'facebook'),

            Forms\Components\TextInput::make('access_token')
                ->label('Page Access Token')
                ->required()
                ->password()
                ->revealable()
                ->columnSpanFull()
                ->helperText('Token akan dienkripsi otomatis'),

            Forms\Components\Toggle::make('is_active')
                ->label('Aktif')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn(Builder $query) => $query->with(['client']))
            ->recordUrl(fn (SocialAccount $record) => static::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\ImageColumn::make('profile_picture_url')
                    ->label('')
                    ->circular()
                    ->size(40)
                    ->defaultImageUrl(fn ($record) => 'https://ui-avatars.com/api/?name=' . urlencode($record->username ?? $record->page_name ?? 'S') . '&background=07a0cf&color=fff'),

                Tables\Columns\TextColumn::make('client.nama_perusahaan')
                    ->label('Klien')
                    ->searchable()
                    ->sortable()
                    ->limit(20)
                    ->tooltip(fn ($record) => $record->client?->nama_perusahaan),

                Tables\Columns\ImageColumn::make('platform_icon')
                    ->label('Platform')
                    ->state(fn ($record): string => match ($record->platform) {
                        'instagram' => 'https://cdn.simpleicons.org/instagram/E4405F',
                        'facebook'  => 'https://cdn.simpleicons.org/facebook/1877F2',
                        'tiktok'    => 'https://cdn.simpleicons.org/tiktok/000000',
                        default     => '',
                    })
                    ->size(24)
                    ->extraImgAttributes(['style' => 'object-fit:contain;']),

                Tables\Columns\TextColumn::make('display_name')
                    ->label('Username / Page')
                    ->searchable(),

                Tables\Columns\TextColumn::make('followers_count')
                    ->label('Followers')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('token_expires_at')
                    ->label('Token Expires')
                    ->since()
                    ->sortable()
                    ->color(fn ($record) => $record?->token_expires_at
                        ? ColorHelper::tokenStatus(
                            \Carbon\Carbon::parse($record->token_expires_at)->isPast(),
                            \Carbon\Carbon::parse($record->token_expires_at)->diffInDays(now())
                        )
                        : 'gray')
                    ->placeholder('—'),

                Tables\Columns\BadgeColumn::make('sync_status')
                    ->colors([
                        'gray'    => 'pending',
                        'warning' => 'syncing',
                        'success' => 'completed',
                        'danger'  => 'failed',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending'   => '⏳ Pending',
                        'syncing'   => '🔄 Syncing',
                        'completed' => '✅ Completed',
                        'failed'    => '❌ Failed',
                        default     => $state,
                    }),

                Tables\Columns\TextColumn::make('last_synced_at')
                    ->label('Last Sync')
                    ->since()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('platform')
                    ->options([
                        'instagram' => 'Instagram',
                        'facebook'  => 'Facebook',
                        'tiktok'    => 'TikTok',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status Aktif'),
            ])

            ->defaultSort('created_at', 'desc')

            /*
            |------------------------------------------------------------------
            | HEADER ACTIONS
            |------------------------------------------------------------------
            */
            ->headerActions([

                Tables\Actions\Action::make('syncAllQueue')
                    ->label('Sync Semua (Queue)')
                    ->icon('heroicon-o-queue-list')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Sync semua akun aktif via queue?')
                    ->modalDescription('Pastikan queue worker sudah jalan: php artisan queue:work')
                    ->modalSubmitActionLabel('Ya, Masukkan Antrian')
                    ->action(function (): void {
                        $accounts = SocialAccount::where('is_active', true)->get();
                        foreach ($accounts as $account) {
                            SyncSocialAccountJob::dispatch($account, 'all');
                        }
                        Notification::make()
                            ->title('🔄 ' . $accounts->count() . ' akun masuk antrian')
                            ->body('Pastikan queue worker berjalan di terminal.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('syncAllDirect')
                    ->label('Sync Semua (Langsung)')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Sync semua akun aktif sekarang?')
                    ->modalDescription('Sync dijalankan langsung tanpa queue. Mungkin membutuhkan waktu beberapa menit.')
                    ->modalSubmitActionLabel('Ya, Sync Sekarang')
                    ->action(function (InstagramService $instagram): void {
                        $accounts = SocialAccount::where('is_active', true)
                            ->where('platform', 'instagram')
                            ->get();

                        $success = 0;
                        $failed  = 0;

                        foreach ($accounts as $account) {
                            try {
                                set_time_limit(300);
                                $result = $instagram->syncAll($account);
                                if ($result['success'] ?? false) {
                                    $success++;
                                } else {
                                    $failed++;
                                }
                            } catch (\Exception $e) {
                                $failed++;
                            }
                        }

                        Notification::make()
                            ->title("Sync selesai: {$success} berhasil, {$failed} gagal")
                            ->color($failed > 0 ? 'warning' : 'success')
                            ->send();
                    }),
            ])

            /*
            |------------------------------------------------------------------
            | ROW ACTIONS
            |------------------------------------------------------------------
            */
            ->actions([

            

                // Sync langsung
                Tables\Actions\Action::make('syncDirect')
                    ->label('Sync')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Sync akun ini sekarang?')
                    ->modalDescription('Sync dijalankan langsung. Tunggu hingga selesai.')
                    ->disabled(fn ($record): bool => $record->sync_status === 'syncing')
                    ->action(function (SocialAccount $record, InstagramService $instagram): void {
                        try {
                            set_time_limit(300);
                            $result = $instagram->syncAll($record);

                            if (!empty($result['errors'])) {
                                Notification::make()
                                    ->title('⚠️ Sync selesai dengan peringatan')
                                    ->body(implode("\n", $result['errors']))
                                    ->warning()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('✅ Sync berhasil')
                                    ->body($record->display_name . ' berhasil di-sync.')
                                    ->success()
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('❌ Sync gagal')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                // Sync via queue
                Tables\Actions\Action::make('syncQueue')
                    ->label('Queue')
                    ->icon('heroicon-o-queue-list')
                    ->color('info')
                    ->disabled(fn ($record): bool => $record->sync_status === 'syncing')
                    ->action(function (SocialAccount $record): void {
                        SyncSocialAccountJob::dispatch($record, 'all');
                        Notification::make()
                            ->title('🔄 Masuk antrian')
                            ->body($record->display_name . ' akan diproses oleh queue worker.')
                            ->info()
                            ->send();
                    }),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])

            /*
            |------------------------------------------------------------------
            | BULK ACTIONS
            |------------------------------------------------------------------
            */
            ->bulkActions([

                Tables\Actions\BulkAction::make('bulkSyncDirect')
                    ->label('Sync Langsung yang Dipilih')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Sync akun yang dipilih sekarang?')
                    ->modalSubmitActionLabel('Ya, Sync Sekarang')
                    ->action(function (Collection $records, InstagramService $instagram): void {
                        $success = 0;
                        $failed  = 0;

                        foreach ($records as $record) {
                            if ($record->platform !== 'instagram') continue;
                            try {
                                set_time_limit(300);
                                $result = $instagram->syncAll($record);
                                $result['success'] ?? false ? $success++ : $failed++;
                            } catch (\Exception $e) {
                                $failed++;
                            }
                        }

                        Notification::make()
                            ->title("Sync selesai: {$success} berhasil, {$failed} gagal")
                            ->color($failed > 0 ? 'warning' : 'success')
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),

                Tables\Actions\BulkAction::make('bulkSyncQueue')
                    ->label('Queue yang Dipilih')
                    ->icon('heroicon-o-queue-list')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $count = 0;
                        foreach ($records as $record) {
                            if ($record->is_active) {
                                SyncSocialAccountJob::dispatch($record, 'all');
                                $count++;
                            }
                        }
                        Notification::make()
                            ->title("✅ {$count} akun masuk antrian")
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),

                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSocialAccounts::route('/'),
            'create' => Pages\CreateSocialAccount::route('/create'),
            'edit'   => Pages\EditSocialAccount::route('/{record}/edit'),
            'view'   => Pages\ViewSocialAccount::route('/{record}'),
        ];
    }
}