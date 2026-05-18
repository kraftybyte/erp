<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProjectTimelineResource\Pages;
use App\Models\ProjectTimeline;
use App\Helpers\ColorHelper;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProjectTimelineResource extends Resource
{
    protected static ?string $model = ProjectTimeline::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationLabel = 'List Project Timeline';
    protected static ?string $navigationGroup = 'Project';
    protected static ?string $navigationParentItem = 'Projects';
    // ==============================
    // FORM (CREATE & EDIT)
    // ==============================
    public static function form(Form $form): Form
    {
        return $form->schema([

            Forms\Components\Select::make('project_id')
                ->label('Perusahaan')
                ->relationship('project', 'nama_perusahaan')
                ->searchable()
                ->required(),

            Forms\Components\TextInput::make('nama_phase')
                ->label('Nama Phase')
                ->required()
                ->maxLength(255),

            Forms\Components\DateTimePicker::make('start_date')
                ->label('Tanggal Mulai')
                ->required(),

            Forms\Components\DateTimePicker::make('end_date')
                ->label('Tanggal Selesai')
                ->required(),

            Forms\Components\Select::make('status')
                ->label('Status')
                ->options([
                    'ongoing' => 'Ongoing',
                    'done' => 'Done',
                    'delayed' => 'Delayed',
                ])
                ->default('ongoing')
                ->required(),

            Forms\Components\Textarea::make('notes')
                ->label('Catatan')
                ->rows(3),
        ]);
    }

    // ==============================
    // TABLE (LIST DATA)
    // ==============================
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn(Builder $query) => $query->with(['project']))
            ->columns([

                Tables\Columns\TextColumn::make('project.nama_perusahaan')
                    ->label('Perusahaan')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('nama_phase')
                    ->label('Phase')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('start_date')
                    ->label('Mulai')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('end_date')
                    ->label('Selesai')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->color(fn ($record) => ColorHelper::status($record->status)),

                Tables\Columns\TextColumn::make('notes')
                    ->label('Catatan')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->since()
                    ->toggleable(),
            ])
            ->defaultSort('start_date', 'desc')

            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'ongoing' => 'Ongoing',
                        'done' => 'Done',
                        'delayed' => 'Delayed',
                    ]),

                Tables\Filters\SelectFilter::make('project_id')
                    ->label('Perusahaan')
                    ->relationship('project', 'nama_perusahaan'),
            ])

            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])

            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    // ==============================
    // RELATIONS (JIKA ADA)
    // ==============================
    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    // ==============================
    // PAGES
    // ==============================
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProjectTimelines::route('/'),
            'create' => Pages\CreateProjectTimeline::route('/create'),
            'edit' => Pages\EditProjectTimeline::route('/{record}/edit'),
        ];
    }
}
