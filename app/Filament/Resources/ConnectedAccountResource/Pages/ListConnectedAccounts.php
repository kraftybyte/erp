<?php

namespace App\Filament\Resources\ConnectedAccountResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListConnectedAccounts extends ListRecords
{
    protected static string $resource = \App\Filament\Resources\ConnectedAccountResource\ConnectedAccountResource::class;

    protected static ?string $title = 'Connected Social Accounts';

    protected function getHeaderActions(): array
    {
        return [
            Actions\ActionGroup::make([
                Actions\Action::make('connect_instagram')
                    ->label('Connect Instagram')
                    ->icon('heroicon-o-camera')
                    ->url(fn () => route('auth.instagram.redirect', ['type' => 'basic']))
                    ->openUrlInNewTab(),

                Actions\Action::make('connect_instagram_business')
                    ->label('Connect Instagram Business')
                    ->icon('heroicon-o-briefcase')
                    ->url(fn () => route('auth.instagram.redirect', ['type' => 'business']))
                    ->openUrlInNewTab(),
            ])
                ->label('Connect Account')
                ->button()
                ->outlined(),
        ];
    }
}