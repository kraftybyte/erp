<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AnnouncementResource\Pages;
use App\Models\Announcement;
use Filament\Forms;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\DateTimePicker;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Components\Hidden;


class AnnouncementResource extends Resource
{
    protected static ?string $model = Announcement::class;
    protected static ?string $navigationIcon = 'heroicon-o-bell';
    protected static ?string $navigationLabel = 'Announcement';
    public static function getNavigationGroup(): ?string
    {
        return __("HR");
    }

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                RichEditor::make('content')
                    ->label('Isi Pengumuman')
                    ->required()
                    ->columnSpanFull(),
                DateTimePicker::make('start_date')
                    ->label('Mulai')
                    ->required(),
                DateTimePicker::make('end_date')
                    ->label('Berakhir')
                    ->required(),
                Hidden::make('user_id')
                    ->required()
                    ->default(fn () => auth()->id()),
            ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->query(fn () => Announcement::with('user'))
            ->columns([
                TextColumn::make('content')->limit(50)->label('Isi Pengumuman')->html(),
                TextColumn::make('start_date')->dateTime()->label('Mulai Pada'),
                TextColumn::make('end_date')->dateTime()->label('Berakhir Pada'),
                TextColumn::make('user.firstname')->label('Pembuat'),
                TextColumn::make('created_at')->dateTime()->label('Tanggal Dibuat'),
            ])
            ->defaultSort('start_date', 'desc')
            ->actions([
                Tables\Actions\EditAction::make()->hiddenLabel()->tooltip('Edit'),
                Tables\Actions\DeleteAction::make()->hiddenLabel()->tooltip('Delete'),
            ])

            ->filters([
                Tables\Filters\Filter::make('aktif')
                    ->query(fn (Builder $query) => $query->where('end_date', '>', now()))
                    ->label('Masih Berlaku'),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }


    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAnnouncements::route('/'),
            'create' => Pages\CreateAnnouncement::route('/create'),
            'edit' => Pages\EditAnnouncement::route('/{record}/edit'),
        ];
    }
}
