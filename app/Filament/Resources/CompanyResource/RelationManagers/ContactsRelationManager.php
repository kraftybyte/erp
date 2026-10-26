<?php

namespace App\Filament\Resources\CompanyResource\RelationManagers;

use App\Filament\Resources\CompanyResource;
use App\Models\CompanyContact;
use App\Services\CompanyDirectory;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    protected static ?string $title = 'PIC';

    protected static ?string $modelLabel = 'PIC';

    public function form(Form $form): Form
    {
        $fields = CompanyResource::contactFields();

        // Nama PIC tidak boleh dobel di perusahaan yang sama.
        $fields[0]->rules([fn ($record): Closure => CompanyResource::uniqueName(
            CompanyContact::where('company_id', $this->getOwnerRecord()->getKey()),
            $record instanceof CompanyContact ? $record->getKey() : null,
            'PIC dengan nama ini sudah ada di perusahaan ini.'
        )]);

        return $form->columns(2)->schema($fields);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('deals'))
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable()->weight('medium'),
                Tables\Columns\TextColumn::make('jabatan')->placeholder('-'),
                Tables\Columns\TextColumn::make('telephone')->placeholder('-'),
                Tables\Columns\TextColumn::make('email')->placeholder('-'),
                Tables\Columns\TextColumn::make('deals_count')->label('Order')->alignCenter(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('merge')
                    ->label('Gabungkan')
                    ->tooltip('Gabungkan ke PIC lain (nama dobel)')
                    ->icon('heroicon-o-arrows-pointing-in')
                    ->iconButton()
                    ->color('warning')
                    ->modalHeading(fn (CompanyContact $record) => "Gabungkan PIC \"{$record->name}\"")
                    ->modalDescription('Order milik PIC ini pindah ke PIC tujuan, lalu PIC ini dihapus. Quotation dan invoice yang sudah terbit tidak berubah.')
                    ->form([
                        Forms\Components\Select::make('into')
                            ->label('Gabungkan ke')
                            ->required()
                            ->options(fn (CompanyContact $record) => CompanyContact::where('company_id', $record->company_id)
                                ->whereKeyNot($record->id)
                                ->orderBy('name')
                                ->pluck('name', 'id')),
                    ])
                    ->action(function (CompanyContact $record, array $data) {
                        $into = CompanyContact::findOrFail($data['into']);

                        app(CompanyDirectory::class)->mergeContact($record, $into);

                        Notification::make()
                            ->title("PIC \"{$record->name}\" digabung ke \"{$into->name}\"")
                            ->success()
                            ->send();
                    })
                    ->visible(fn () => auth()->user()?->can('update_deal')),

                Tables\Actions\EditAction::make()->hiddenLabel()->tooltip('Edit'),

                Tables\Actions\DeleteAction::make()
                    ->hiddenLabel()
                    ->tooltip('Hapus')
                    ->hidden(fn (CompanyContact $record) => $record->deals_count > 0),
            ]);
    }
}
