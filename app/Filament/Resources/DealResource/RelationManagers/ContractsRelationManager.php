<?php

namespace App\Filament\Resources\DealResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ContractsRelationManager extends RelationManager
{
    protected static string $relationship = 'contracts';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('type')
                    ->label('Contract Type')
                    ->options([
                        'editor' => 'Editor (Rich Text)',
                        'file' => 'File Upload',
                    ])
                    ->required(),

                Forms\Components\MarkdownEditor::make('content')
                    ->label('Contract Content')
                    ->visible(fn (callable $get) => $get('type') === 'editor')
                    ->columnSpanFull(),

                Forms\Components\FileUpload::make('file_path')
                    ->label('Contract File')
                    ->visible(fn (callable $get) => $get('type') === 'file')
                    ->directory('contracts/' . now()->format('Y-m-d'))
                    ->downloadable()
                    ->openable()
                    ->columnSpanFull(),

                Forms\Components\DatePicker::make('effective_date')
                    ->label('Effective Date')
                    ->required()
                    ->closeOnDateSelection(),

                Forms\Components\DatePicker::make('expiration_date')
                    ->label('Expiration Date')
                    ->required()
                    ->closeOnDateSelection(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('type')
                    ->label('Type')
                    ->colors([
                        'primary' => 'editor',
                        'warning' => 'file',
                    ]),

                Tables\Columns\TextColumn::make('effective_date')
                    ->date('d/m/Y')
                    ->label('Effective'),

                Tables\Columns\TextColumn::make('expiration_date')
                    ->date('d/m/Y')
                    ->label('Expiration'),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d/m/Y H:i')
                    ->label('Created'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }
}