<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BudgetLogResource\Pages;
use App\Models\BudgetLog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BudgetLogResource extends Resource
{
    protected static ?string $model = BudgetLog::class;

    protected static ?string $navigationGroup = 'Finance';
    protected static ?string $navigationParentItem = 'Budget';
    protected static ?string $navigationLabel = 'Log Budget';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('budget_id')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('user_id')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('change_amount')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('action')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('note')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn($query) => $query->with(['budget', 'user']))
            ->columns([
                Tables\Columns\TextColumn::make('budget_id')
                    ->numeric()
                    ->sortable()
                    ->description(fn (BudgetLog $record): string => $record->budget->name, position: 'after')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Nama User')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('change_amount')
                    ->numeric()
                    ->label('Nominal')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => 'Rp ' . number_format($state, 0, ',', '.')),
                Tables\Columns\TextColumn::make('action')
                    ->description(fn (BudgetLog $record): string => $record->note ?? '', position: 'after')
                    ->sortable()
                    ->label('Keterangan')
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->label('Tanggal')
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
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
            'index' => Pages\ListBudgetLogs::route('/'),
            'create' => Pages\CreateBudgetLog::route('/create'),
            'edit' => Pages\EditBudgetLog::route('/{record}/edit'),
        ];
    }
}
