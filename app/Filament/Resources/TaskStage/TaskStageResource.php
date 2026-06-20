<?php

namespace App\Filament\Resources\TaskStage;

use App\Models\TaskStage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;

class TaskStageResource extends Resource
{
    use HasPageShield;

    protected static ?string $model = TaskStage::class;

    protected static ?string $navigationIcon = 'heroicon-o-view-columns';

    protected static ?string $navigationLabel = 'Task Stages';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 100;

    protected static ?string $slug = 'task-stages';

    protected static bool $shouldRegisterNavigation = true;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Stage Configuration')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('key')
                                    ->label('Key')
                                    ->required()
                                    ->unique(ignoreRecord: true)
                                    ->helperText('Unique identifier (e.g., log, process). Lowercase, no spaces.')
                                    ->maxLength(50),

                                Forms\Components\TextInput::make('name')
                                    ->label('Display Name')
                                    ->required()
                                    ->maxLength(100)
                                    ->placeholder('e.g., Log, Process, Review'),
                            ]),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\ColorPicker::make('color')
                                    ->label('Accent Color')
                                    ->default('#94a3b8')
                                    ->rgb()
                                    ->required(),

                                Forms\Components\ColorPicker::make('background_color')
                                    ->label('Background Color')
                                    ->default('#f8fafc')
                                    ->rgb()
                                    ->required(),
                            ]),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('sort_order')
                                    ->label('Sort Order')
                                    ->numeric()
                                    ->default(0)
                                    ->required()
                                    ->helperText('Lower number = left side of board'),

                                Forms\Components\Toggle::make('is_active')
                                    ->label('Active')
                                    ->default(true)
                                    ->helperText('Inactive stages will be hidden from board'),
                            ]),
                    ]),

                Forms\Components\Section::make('Preview')
                    ->schema([
                        Forms\Components\Placeholder::make('preview')
                            ->label('Stage Preview')
                            ->content(function (callable $get) {
                                $name = $get('name') ?: 'Stage Name';
                                $color = $get('color') ?: '#94a3b8';
                                $bg = $get('background_color') ?: '#f8fafc';

                                return new \Illuminate\Support\HtmlString("
                                    <div style='display: flex; align-items: center; gap: 8px;'>
                                        <span style='width: 12px; height: 12px; border-radius: 50%; background: {$color};'></span>
                                        <span style='font-weight: 600; color: #1e293b;'>{$name}</span>
                                        <span style='padding: 2px 8px; border-radius: 99px; background: {$bg}; font-size: 11px; color: #64748b;'>0</span>
                                    </div>
                                ");
                            }),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->orderBy('sort_order'))
            ->columns([
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Status')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Order')
                    ->numeric()
                    ->sortable()
                    ->width(80),

                Tables\Columns\ColorColumn::make('color')
                    ->label('Color'),

                Tables\Columns\TextColumn::make('key')
                    ->label('Key')
                    ->fontFamily('mono')
                    ->searchable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('background_color')
                    ->label('Background')
                    ->formatStateUsing(fn ($state) => $state ?? '#f8fafc')
                    ->tooltip(fn ($record) => $record->background_color),

                Tables\Columns\TextColumn::make('tasks_count')
                    ->label('Tasks')
                    ->counts('tasks')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('All')
                    ->trueLabel('Active')
                    ->falseLabel('Inactive'),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make()
                        ->action(function (TaskStage $record) {
                            // Check if stage has tasks
                            if ($record->tasks()->exists()) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Cannot Delete')
                                    ->body('This stage has assigned tasks. Please reassign them first.')
                                    ->warning()
                                    ->send();
                                return;
                            }
                            $record->delete();
                        })
                        ->requiresConfirmation()
                        ->modalHeading('Delete Stage')
                        ->modalDescription(fn (TaskStage $record) =>
                            $record->tasks()->exists()
                                ? 'This stage has tasks assigned. Please reassign them first.'
                                : 'Are you sure you want to delete this stage?'
                        ),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->reorderable('sort_order');
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
            'index' => Pages\ListTaskStages::route('/'),
            'create' => Pages\CreateTaskStage::route('/create'),
            'view' => Pages\ViewTaskStage::route('/{record}'),
            'edit' => Pages\EditTaskStage::route('/{record}/edit'),
        ];
    }
}
