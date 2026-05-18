<?php

namespace App\Filament\Resources\ConnectedAccountResource\Pages;

use App\Models\ConnectedAccount;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewConnectedAccount extends ViewRecord
{
    protected static string $resource = \App\Filament\Resources\ConnectedAccountResource\ConnectedAccountResource::class;

    protected static ?string $title = 'Account Details';

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('sync')
                ->label('Sync Now')
                ->icon('heroicon-o-arrow-path')
                ->action(function (ConnectedAccount $record) {
                    $service = app(\App\Services\InstagramSyncService::class);

                    try {
                        $service->syncAccount($record);

                        \Filament\Notifications\Notification::make()
                            ->title('Sync complete')
                            ->body("Data for @{$record->username} has been updated")
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        \Filament\Notifications\Notification::make()
                            ->title('Sync failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\Action::make('refresh_token')
                ->label('Refresh Token')
                ->icon('heroicon-o-key')
                ->action(function (ConnectedAccount $record) {
                    $service = app(\App\Services\InstagramSyncService::class);

                    try {
                        $service->refreshToken($record);

                        \Filament\Notifications\Notification::make()
                            ->title('Token refreshed')
                            ->body("Token for @{$record->username} is now valid for 60 more days")
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        \Filament\Notifications\Notification::make()
                            ->title('Token refresh failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\Action::make('disconnect')
                ->label('Disconnect')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->action(function (ConnectedAccount $record) {
                    $username = $record->username;
                    $record->update(['is_active' => false]);

                    \Filament\Notifications\Notification::make()
                        ->title('Account disconnected')
                        ->body("@{$username} has been disconnected")
                        ->warning()
                        ->send();

                    redirect(ConnectedAccountResource::getUrl('index'));
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Widgets\SingleAccountStatsWidget::class,
        ];
    }
}