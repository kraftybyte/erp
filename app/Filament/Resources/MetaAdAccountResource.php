<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MetaAdAccountResource\Pages;
use App\Helpers\ColorHelper;
use App\Jobs\SyncMetaAdsJob;
use App\Models\MetaAdAccount;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MetaAdAccountResource extends Resource
{
    protected static ?string $model = MetaAdAccount::class;
    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    public static function getNavigationLabel(): string
    {
        return \App\Helpers\NavigationHelper::getLabel('meta_ad', 'Meta ad');
    }
    protected static ?string $navigationLabel = 'Meta Ads Accounts';
    protected static ?string $navigationGroup = 'Tools';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('client_id')
                ->relationship('client', 'nama_perusahaan')
                ->searchable()
                ->required()
                ->label('Klien'),

            Forms\Components\TextInput::make('ad_account_id')
                ->label('Ad Account ID')
                ->placeholder('act_XXXXXXXXXX')
                ->required(),

            Forms\Components\TextInput::make('account_name')
                ->label('Nama Akun'),

            Forms\Components\Textarea::make('access_token')
                ->label('Access Token')
                ->rows(3)
                ->required(),

            Forms\Components\DateTimePicker::make('token_expires_at')
                ->label('Token Expires At'),

            Forms\Components\Toggle::make('is_active')
                ->label('Aktif')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('client.nama_perusahaan')
                    ->label('Klien')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('ad_account_id')
                    ->label('Account ID')
                    ->copyable(),

                Tables\Columns\TextColumn::make('account_name')
                    ->label('Nama Akun'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),

                Tables\Columns\TextColumn::make('last_synced_at')
                    ->label('Last Sync')
                    ->since()
                    ->sortable(),

                Tables\Columns\TextColumn::make('token_expires_at')
                    ->label('Token Expires')
                    ->dateTime('d M Y')
                    ->color(fn ($record) => $record ? ColorHelper::tokenStatus($record->isTokenExpired()) : 'gray'),
            ])
            ->modifyQueryUsing(function ($query) {
                return $query->with('client');
            })
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status Aktif'),
            ])
            ->actions([
                Tables\Actions\Action::make('sync')
                    ->label('Sync Now')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->requiresConfirmation()
                    ->action(function (MetaAdAccount $record) {
                        SyncMetaAdsJob::dispatch($record, 'last_30d');
                        Notification::make()
                            ->title('Sync dimulai!')
                            ->body("Syncing {$record->account_name}...")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\EditAction::make()->hiddenLabel()->tooltip('Edit'),
                Tables\Actions\DeleteAction::make()->hiddenLabel()->tooltip('Delete'),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('sync_selected')
                    ->label('Sync Selected')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function ($records) {
                        $records->each(fn ($r) => SyncMetaAdsJob::dispatch($r, 'last_30d'));
                        Notification::make()->title('Sync jobs dispatched!')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListMetaAdAccounts::route('/'),
            'create' => Pages\CreateMetaAdAccount::route('/create'),
            'edit'   => Pages\EditMetaAdAccount::route('/{record}/edit'),
        ];
    }
}