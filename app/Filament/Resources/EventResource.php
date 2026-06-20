<?php

namespace App\Filament\Resources;

use App\Models\Event;
use App\Settings\GeneralSettings;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use App\Filament\Resources\EventResource\Pages;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;


class EventResource extends Resource
{
    protected static ?string $model = Event::class;
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    public static function getNavigationLabel(): string
    {
        return \App\Helpers\NavigationHelper::getLabel('event', 'Event');
    }
    protected static ?string $navigationLabel = 'Event';
    protected static ?int $navigationSort = -1;

    public static function getNavigationGroup(): ?string
    {
        return __("HR");
    }

    public static function form(Form $form): Form
    {
        $settings = app(GeneralSettings::class);
        $categoryOptions = $settings->getEventCategoryOptions();

        return $form
            ->schema([
                TextInput::make('title')->required()
                ->label('Judul Acara'),
                Textarea::make('description')
                ->label('Keterangan Acara'),
                DateTimePicker::make('start_time')
                    ->required()
                    ->timezone('Asia/Jakarta')
                    ->label('Tanggal Mulai'),
                DateTimePicker::make('end_time')
                ->timezone('Asia/Jakarta')
                ->label('Tanggal Selesai'),
                Select::make('color')
                ->label('Pilih Agenda')
                ->required()
                ->options($categoryOptions)
                ->native(false),
                Hidden::make('create_by')
                    ->label('Dibuat oleh')
                    ->required()
                    ->default(fn() => auth()->id()),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn(Builder $query) => $query->with(['user']))
            ->columns([
                Tables\Columns\TextColumn::make('title')
                ->searchable(),
                Tables\Columns\TextColumn::make('description')
                ->limit(10) // Memotong teks setelah 50 karakter
                ->tooltip(fn ($record) => $record->description), // Menampilkan teks lengkap saat hover
                Tables\Columns\TextColumn::make('start_time')
                ->dateTime()
                ->sortable(),
                Tables\Columns\TextColumn::make('end_time')
                ->dateTime()
                ->sortable(),
                Tables\Columns\TextColumn::make('user.firstname')
                ->label('Created By')
                ->sortable()
                ->wrap(),

            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()->hiddenLabel()->tooltip('Edit'),
                Tables\Actions\DeleteAction::make()->hiddenLabel()->tooltip('Delete'),
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
            'index' => Pages\ListEvents::route('/'),
            'create' => Pages\CreateEvent::route('/create'),
            'edit' => Pages\EditEvent::route('/{record}/edit'),
        ];
    }
}
