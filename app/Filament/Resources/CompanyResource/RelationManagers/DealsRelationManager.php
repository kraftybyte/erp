<?php

namespace App\Filament\Resources\CompanyResource\RelationManagers;

use App\Filament\Resources\DealResource;
use App\Helpers\ColorHelper;
use App\Models\Deal;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Semua order (Client) perusahaan ini, dari PIC mana pun. Dibuat dan diubah
 * lewat menu Client.
 */
class DealsRelationManager extends RelationManager
{
    protected static string $relationship = 'deals';

    protected static ?string $title = 'Order / Client';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('nama_perusahaan')
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('Deal ID')->sortable(),
                Tables\Columns\TextColumn::make('nama_pic')
                    ->label('PIC')
                    ->formatStateUsing(fn (?string $state) => blank($state) || $state === '-' ? '(Pribadi)' : $state),
                Tables\Columns\TextColumn::make('product.nama_layanan')->label('Layanan')->badge(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (Deal $record) => ColorHelper::dealStatus($record->status)),
                Tables\Columns\TextColumn::make('created_at')->label('Tanggal Deal')->dateTime('d/m/Y')->sortable(),
            ])
            ->recordUrl(fn (Deal $record) => DealResource::getUrl('view', ['record' => $record]));
    }
}
