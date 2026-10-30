<?php

namespace App\Filament\Resources\ConnectedAccountResource;

use App\Filament\Resources\ConnectedAccountResource\Pages;
use App\Models\ConnectedAccount;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ConnectedAccountResource extends Resource
{
    protected static ?string $model = ConnectedAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-camera';
    protected static ?string $navigationLabel = 'Connected Social Accounts';
    protected static ?string $navigationGroup = 'Tools';
    protected static ?int $navigationSort = 5;

    public static function getNavigationLabel(): string
    {
        return \App\Helpers\NavigationHelper::getLabel('connected_account', 'Connected Social Accounts');
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::active()->count() ?: null;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Account Info')
                    ->schema([
                        Forms\Components\Select::make('user_id')
                            ->relationship('user', 'name')
                            ->required()
                            ->disabledOn('edit'),

                        Forms\Components\Select::make('provider')
                            ->options([
                                'instagram' => 'Instagram',
                                'facebook' => 'Facebook',
                                'tiktok' => 'TikTok',
                                'twitter' => 'Twitter',
                            ])
                            ->required(),

                        Forms\Components\TextInput::make('account_id')
                            ->label('Account ID')
                            ->disabled(),

                        Forms\Components\TextInput::make('username')
                            ->required(),

                        Forms\Components\TextInput::make('name')
                            ->label('Display Name'),

                        Forms\Components\Select::make('account_type')
                            ->options([
                                'personal' => 'Personal',
                                'business' => 'Business',
                                'creator' => 'Creator',
                            ]),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ])->columns(2),

                Forms\Components\Section::make('Sync Status')
                    ->schema([
                        Forms\Components\Placeholder::make('last_synced_at')
                            ->label('Last Synced')
                            ->content(fn (ConnectedAccount $record) => $record->last_synced_at
                                ? $record->last_synced_at->diffForHumans()
                                : 'Never'),

                        Forms\Components\Placeholder::make('token_expires_at')
                            ->label('Token Expires')
                            ->content(fn (ConnectedAccount $record) => $record->token_expires_at
                                ? $record->token_expires_at->diffForHumans()
                                : 'No expiration'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\ImageColumn::make('profile_picture')
                    ->label('')
                    ->circular()
                    ->size(40),

                Tables\Columns\TextColumn::make('username')
                    ->label('Username')
                    ->formatStateUsing(fn (string $state) => '@' . $state)
                    ->searchable()
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('provider')
                    ->colors([
                        'instagram' => 'danger',
                        'facebook' => 'info',
                        'tiktok' => 'dark',
                        'twitter' => 'primary',
                    ]),

                Tables\Columns\BadgeColumn::make('account_type')
                    ->colors([
                        'personal' => 'gray',
                        'business' => 'success',
                        'creator' => 'warning',
                    ]),

                Tables\Columns\TextColumn::make('latestMetrics.followers')
                    ->label('Followers')
                    ->formatStateUsing(fn (int $state) => number_format($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('latestMetrics.reach')
                    ->label('Reach')
                    ->formatStateUsing(fn (int $state) => number_format($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('latestMetrics.engagement_rate')
                    ->label('Eng. Rate')
                    ->formatStateUsing(fn (float $state) => number_format($state, 2, ',', '.') . '%')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_synced_at')
                    ->label('Last Sync')
                    ->formatStateUsing(fn ($state) => $state ? $state->diffForHumans() : 'Never')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('provider')
                    ->options([
                        'instagram' => 'Instagram',
                        'facebook' => 'Facebook',
                        'tiktok' => 'TikTok',
                    ]),

                Tables\Filters\SelectFilter::make('account_type')
                    ->options([
                        'personal' => 'Personal',
                        'business' => 'Business',
                        'creator' => 'Creator',
                    ]),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status'),
            ])
            ->actions([
                Tables\Actions\Action::make('sync')
                    ->label('Sync')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (ConnectedAccount $record) {
                        $service = app(\App\Services\InstagramSyncService::class);
                        $service->syncAccount($record);

                        \Filament\Notifications\Notification::make()
                            ->title('Sync started')
                            ->body("Syncing data for @{$record->username}")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('disconnect')
                    ->label('Disconnect')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (ConnectedAccount $record) {
                        $record->update(['is_active' => false]);

                        \Filament\Notifications\Notification::make()
                            ->title('Account disconnected')
                            ->body("@{$record->username} has been disconnected")
                            ->warning()
                            ->send();
                    }),

                Tables\Actions\ViewAction::make(),
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
            'index' => Pages\ListConnectedAccounts::route('/'),
            'view' => Pages\ViewConnectedAccount::route('/{record}'),
        ];
    }
}