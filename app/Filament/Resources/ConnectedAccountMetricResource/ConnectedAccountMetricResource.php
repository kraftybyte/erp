<?php

namespace App\Filament\Resources\ConnectedAccountMetricResource;

use App\Filament\Resources\ConnectedAccountMetricResource\Pages;
use App\Models\ConnectedAccountMetric;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ConnectedAccountMetricResource extends Resource
{
    protected static ?string $model = ConnectedAccountMetric::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Account Metrics';

    protected static ?string $label = 'Metric';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationGroup = 'Social Media';

    public static function getNavigationBadge(): ?string
    {
        $count = ConnectedAccountMetric::last7Days()->count();
        return $count > 0 ? $count : null;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('account.username')
                    ->label('Account')
                    ->formatStateUsing(fn ($state) => '@' . $state)
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('date')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('followers')
                    ->label('Followers')
                    ->formatStateUsing(fn (int $state) => number_format($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('reach')
                    ->label('Reach')
                    ->formatStateUsing(fn (int $state) => number_format($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('impressions')
                    ->label('Impressions')
                    ->formatStateUsing(fn (int $state) => number_format($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('engagement')
                    ->label('Engagement')
                    ->formatStateUsing(fn (int $state) => number_format($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('engagement_rate')
                    ->label('Eng. Rate')
                    ->formatStateUsing(fn (float $state) => number_format($state, 2) . '%')
                    ->sortable(),

                Tables\Columns\TextColumn::make('profile_visits')
                    ->label('Profile Visits')
                    ->formatStateUsing(fn (int $state) => number_format($state))
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('connected_account_id')
                    ->label('Account')
                    ->relationship('account', 'username', fn ($query) => $query->where('is_active', true)),

                Tables\Filters\Filter::make('date_range')
                    ->form([
                        Forms\Components\DatePicker::make('from'),
                        Forms\Components\DatePicker::make('until'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q) => $q->where('date', '>=', $data['from']))
                            ->when($data['until'], fn ($q) => $q->where('date', '<=', $data['until']));
                    })
                    ->indicateUsing(function (array $data): ?string {
                        if ($data['from'] || $data['until']) {
                            return 'Date range: ' . ($data['from'] ?? '-') . ' to ' . ($data['until'] ?? '-');
                        }
                        return null;
                    }),
            ])
            ->actions([
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
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListConnectedAccountMetrics::route('/'),
            'view' => Pages\ViewConnectedAccountMetric::route('/{record}'),
        ];
    }
}