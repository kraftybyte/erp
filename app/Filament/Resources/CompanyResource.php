<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CompanyResource\Pages;
use App\Filament\Resources\CompanyResource\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\CompanyResource\RelationManagers\DealsRelationManager;
use App\Models\Company;
use App\Services\CompanyDirectory;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Data induk perusahaan klien beserta PIC-nya. Client (Deal) menunjuk
 * perusahaan dan PIC untuk setiap order.
 */
class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?int $navigationSort = -2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Perusahaan';

    protected static ?string $pluralModelLabel = 'Perusahaan';

    public static function getNavigationLabel(): string
    {
        return 'Perusahaan';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('CRM');
    }

    /**
     * Field perusahaan; juga dipakai form Client untuk menambah perusahaan baru.
     *
     * @return array<int, Forms\Components\Component>
     */
    public static function companyFields(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->label('Nama Perusahaan')
                ->required()
                ->maxLength(255)
                // $record bukan Company saat form ini dibuka dari form Client.
                ->rules([fn ($record): Closure => static::uniqueName(Company::query(), $record instanceof Company ? $record->getKey() : null, 'Perusahaan dengan nama ini sudah ada.')]),

            Forms\Components\TextInput::make('npwp')
                ->label('No. NPWP')
                ->maxLength(100),

            Forms\Components\Textarea::make('alamat')
                ->columnSpanFull(),
        ];
    }

    /**
     * Field PIC; juga dipakai form Client untuk menambah PIC baru.
     *
     * @return array<int, Forms\Components\Component>
     */
    public static function contactFields(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->label('Nama PIC')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('jabatan')
                ->maxLength(255),

            Forms\Components\TextInput::make('telephone')
                ->tel()
                ->maxLength(50),

            Forms\Components\TextInput::make('email')
                ->email()
                ->maxLength(255),
        ];
    }

    /**
     * Aturan validasi: nama belum dipakai data lain (beda huruf besar/kecil
     * atau spasi dianggap sama).
     */
    public static function uniqueName(Builder $query, mixed $ignoreId, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($query, $ignoreId, $message) {
            $taken = (clone $query)
                ->where('name_key', CompanyDirectory::key($value))
                ->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))
                ->exists();

            if ($taken) {
                $fail($message);
            }
        };
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Informasi Perusahaan')
                ->columns(2)
                ->schema([
                    ...static::companyFields(),

                    Forms\Components\Textarea::make('notes')
                        ->label('Catatan')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('contacts:id,company_id,name')->withCount(['contacts', 'deals']))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Perusahaan')
                    ->weight('medium')
                    ->wrap()
                    ->sortable()
                    ->searchable(
                        query: fn (Builder $query, string $search) => $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhereHas('contacts', fn (Builder $contacts) => $contacts->where('name', 'like', "%{$search}%"))
                    )
                    ->description(fn (Company $record) => $record->contacts->take(4)->pluck('name')->implode(', ')
                        . ($record->contacts->count() > 4 ? ', …' : '') ?: null),

                Tables\Columns\TextColumn::make('contacts_count')
                    ->label('PIC')
                    ->alignCenter()
                    ->sortable(),

                Tables\Columns\TextColumn::make('deals_count')
                    ->label('Order')
                    ->alignCenter()
                    ->sortable(),

                Tables\Columns\TextColumn::make('npwp')
                    ->label('NPWP')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\Filter::make('duplikat')
                    ->label('Dugaan duplikat')
                    ->toggle()
                    ->query(function (Builder $query) {
                        $ids = app(CompanyDirectory::class)->suspectedDuplicateIds();

                        return $ids
                            ? $query->whereIn('id', $ids)->orderByRaw('FIELD(id, ' . implode(',', $ids) . ')')
                            : $query->whereRaw('1 = 0');
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('merge')
                    ->label('Gabungkan')
                    ->tooltip('Gabungkan ke perusahaan lain')
                    ->icon('heroicon-o-arrows-pointing-in')
                    ->iconButton()
                    ->color('warning')
                    ->modalHeading(fn (Company $record) => "Gabungkan \"{$record->name}\"")
                    ->modalDescription('Semua PIC dan Client perusahaan ini pindah ke perusahaan tujuan; PIC dengan nama sama dijadikan satu. Perusahaan ini lalu dihapus, dan namanya tetap dikenali sebagai nama lain perusahaan tujuan. Quotation dan invoice yang sudah terbit tidak berubah.')
                    ->form([
                        Forms\Components\Select::make('into')
                            ->label('Gabungkan ke')
                            ->required()
                            ->searchable()
                            ->options(fn (Company $record) => static::mergeTargets($record))
                            ->getSearchResultsUsing(fn (string $search, Company $record) => Company::query()
                                ->whereKeyNot($record->id)
                                ->where('name', 'like', "%{$search}%")
                                ->orderBy('name')
                                ->limit(50)
                                ->pluck('name', 'id')),
                    ])
                    ->action(function (Company $record, array $data) {
                        $into = Company::findOrFail($data['into']);

                        app(CompanyDirectory::class)->merge($record, $into);

                        Notification::make()
                            ->title("\"{$record->name}\" digabung ke \"{$into->name}\"")
                            ->success()
                            ->send();
                    })
                    ->visible(fn () => auth()->user()?->can('update_deal')),

                Tables\Actions\EditAction::make()->hiddenLabel()->tooltip('Edit'),

                Tables\Actions\DeleteAction::make()
                    ->hiddenLabel()
                    ->tooltip('Hapus')
                    ->hidden(fn (Company $record) => $record->deals_count > 0),
            ]);
    }

    /**
     * Pilihan tujuan penggabungan: yang namanya mirip ditaruh paling atas.
     *
     * @return array<int, string>
     */
    public static function mergeTargets(Company $record): array
    {
        $key = CompanyDirectory::looseKey($record->name);

        return Company::query()
            ->whereKeyNot($record->id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->sortBy(fn (Company $company) => CompanyDirectory::looseKey($company->name) === $key ? 0 : 1, SORT_REGULAR)
            ->take(200)
            ->pluck('name', 'id')
            ->all();
    }

    public static function getRelations(): array
    {
        return [
            ContactsRelationManager::class,
            DealsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCompanies::route('/'),
            'create' => Pages\CreateCompany::route('/create'),
            'edit'   => Pages\EditCompany::route('/{record}/edit'),
        ];
    }
}
