<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BudgetResource\Pages;
use App\Filament\Resources\BudgetResource\RelationManagers;
use App\Models\Budget;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BudgetResource extends Resource
{
    protected static ?string $model = Budget::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationGroup = 'Finance';
    protected static ?string $navigationLabel = 'Budget';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->label('Nama Budget'),
                Forms\Components\Textarea::make('description')
                    ->label('Keterangan Budget'),
                Forms\Components\TextInput::make('amount')
                    ->numeric()
                    ->required()
                    ->label('Nominal'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Budget'),
                Tables\Columns\TextColumn::make('description')
                    ->label('Keterangan'),
                Tables\Columns\TextColumn::make('amount')
                    ->money('IDR')
                    ->label('Nominal'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->label('Dibuat Pada'),
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
            'index' => Pages\ListBudgets::route('/'),
            'create' => Pages\CreateBudget::route('/create'),
            'edit' => Pages\EditBudget::route('/{record}/edit'),
        ];
    }
}
