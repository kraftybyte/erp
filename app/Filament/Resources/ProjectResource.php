<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProjectResource\Pages;
use App\Filament\Resources\ProjectResource\Pages\PackingSlip;
use App\Helpers\ColorHelper;
use App\Models\Deal;
use App\Models\Project;
use App\Settings\GeneralSettings;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Actions;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;



class ProjectResource extends Resource
{
    protected static ?string $navigationIcon = 'heroicon-o-rocket-launch';

    public static function getNavigationLabel(): string
    {
        return \App\Helpers\NavigationHelper::getLabel('project', 'Project');
    }
    protected static ?int $navigationSort = -3;

    protected static ?string $recordTitleAttribute = 'nama_project';

    // Get project category options from settings
    public static function getProjectCategoryOptions(): array
    {
        $settings = app(GeneralSettings::class);
        $categories = $settings->project_categories ?? [];

        // Default categories if not set
        $defaultCategories = [
            'Social Media Management' => 'Social Media Management',
            'Video Production' => 'Video Production',
            'SEO' => 'SEO',
            'Tiktok Handling' => 'Tiktok Handling',
            'Website Development' => 'Website Development',
            'Live Tiktok' => 'Live Tiktok',
            'Photo Product' => 'Photo Product',
            'Lain-lain' => 'Lain-lain',
        ];

        // If settings is empty, return defaults
        if (empty($categories) || !is_array($categories)) {
            return $defaultCategories;
        }

        // Handle Repeater format: [{category_name: 'xxx'}, ...]
        $result = [];
        foreach ($categories as $cat) {
            if (is_array($cat) && isset($cat['category_name']) && !empty($cat['category_name'])) {
                $name = $cat['category_name'];
                $result[$name] = $name;
            } elseif (is_string($cat) && !empty($cat)) {
                // Simple array format: ['xxx', 'yyy']
                $result[$cat] = $cat;
            }
        }

        // If no valid categories found, return defaults
        if (empty($result)) {
            return $defaultCategories;
        }

        return $result;
    }

    public static function form(Form $form): Form
        {
            return $form
            ->schema([
                Forms\Components\Wizard::make([
                    Forms\Components\Wizard\Step::make('Detail Project')
                        ->schema([
                            Forms\Components\Select::make('deal_id')
                        ->label('Nama Klien')
                        ->relationship('deal', 'nama_perusahaan')
                        ->searchable(['nama_perusahaan', 'nama_pic', 'telephone'])
                        ->getOptionLabelFromRecordUsing(fn (Deal $record) => "{$record->nama_perusahaan} ({$record->nama_pic})")
                        ->required()
                        ->reactive()
                        ->afterStateUpdated(function ($state, callable $set) {
                            if ($state) {
                                $deal = \App\Models\Deal::find($state);
                                $set('notes', $deal?->notes);
                                $set('nama_perusahaan', $deal?->nama_perusahaan);
                                $set('alamat', $deal?->alamat);
                                $set('nama_pic', $deal?->nama_pic);
                                $set('status', $deal?->status);
                            }
                        })
                        ->default(request()->get('deal_id')),
                            Forms\Components\Select::make('nama_project')
                                ->label('Kategori Project')
                                ->required()
                                ->options(function () {
                                    return self::getProjectCategoryOptions();
                                }),
                            Forms\Components\MarkdownEditor::make('detail_project')
                                ->label('Detail Project')
                                ->helperText('Tulis dengan detail.')
                                ->maxLength(500)
                                ->required()
                                ->columnSpanFull(),
                        ])
                        ->columns([
                            'sm' => 1,
                            'lg' => 2,
                        ]),

                    Forms\Components\Wizard\Step::make('Timeline Project')
                        ->schema([
                            Forms\Components\DatePicker::make('timeline_mulai')
                                ->label('Tanggal Mulai Project')
                                ->required()
                                ->placeholder('MM/DD/YYYY')
                                ->native(false),

                            Forms\Components\DatePicker::make('timeline_selesai')
                                ->label('Tanggal Selesai Project')
                                ->required()
                                ->placeholder('MM/DD/YYYY')
                                ->native(false),
                        ])
                        ->columns([
                            'sm' => 1,
                            'lg' => 2,
                        ]),

                        Forms\Components\Wizard\Step::make('Informasi Klien')
                        ->schema([
                            Forms\Components\TextInput::make('nama_perusahaan')
                                ->label('Nama Perusahaan')
                                ->maxLength(500)
                                ->required()
                                ->reactive()
                                ->readonly() // Make the field read-only
                                ->columnSpanFull(),

                            Forms\Components\Textarea::make('alamat')
                                ->label('Alamat Perusahaan')
                                ->maxLength(500)
                                ->required()
                                ->reactive()
                                ->readonly() // Make the field read-only
                                ->columnSpanFull(),

                            Forms\Components\TextInput::make('nama_pic')
                                ->label('Nama PIC')
                                ->maxLength(500)
                                ->required()
                                ->reactive()
                                ->readonly() // Make the field read-only
                                ->columnSpanFull(),


                            Forms\Components\textarea::make('notes')
                                ->label('Catatan Informasi Klien')
                                ->maxLength(500)
                                // Terisi dari catatan client; boleh kosong bila client-nya tidak punya catatan.
                                ->reactive()
                                ->readonly() // Make the field read-only
                                ->columnSpanFull(),
                        ])
                        ->columns([
                            'sm' => 1,
                            'lg' => 2,
                        ]),
                ])
                ->columnSpanFull(),
            ]);
        }


    public static function table(Table $table): Table
    {
        return $table
        ->modifyQueryUsing(fn(Builder $query) => $query->with(['deal']))
        ->columns([
            Tables\Columns\TextColumn::make('id')
                ->searchable()
                ->sortable()
                ->label('Project ID'),

            Tables\Columns\TextColumn::make('nama_project')
                ->searchable()
                ->badge()
                ->label('Nama Project'),

            Tables\Columns\TextColumn::make('deal.nama_perusahaan')
                ->searchable()
                ->limit(30)
                ->label('Nama Klien'),

            Tables\Columns\TextColumn::make('deal.status')
                ->searchable()
                ->label('Status')
                ->badge()
                ->color(fn ($record) => ColorHelper::dealStatus($record->deal?->status ?? '')),
            Tables\Columns\TextColumn::make('timeline_mulai')
                ->label('Mulai Project')
                ->dateTime('d/m/Y'),

            Tables\Columns\TextColumn::make('timeline_selesai')
                ->label('Selesai Project')
                ->dateTime('d/m/Y'),

            Tables\Columns\TextColumn::make('timeline_selesai')
            ->label('Deadline')
            ->getStateUsing(function ($record) {
                $now = Carbon::now();
                $deadline = Carbon::parse($record->timeline_selesai);

                $diffInDays = $now->diffInDays($deadline, false);
                $diffInDays = round($diffInDays);
                // Jika sudah lewat deadline
                if ($diffInDays < 0) {
                    return abs($diffInDays) . ' hari terlambat';
                }

                return $diffInDays . ' hari lagi';
            })
            ->sortable()
            ->toggleable(isToggledHiddenByDefault: false),

             ])
             ->defaultSort('created_at', 'desc')
             ->filters([
                Tables\Filters\SelectFilter::make('deal.status')
                    ->label('Status')
                    ->options([
                        'Negosiasi' => 'Negosiasi',
                        'Menunggu Pembayaran' => 'Menunggu Pembayaran',
                        'Dalam Tahap Project' => 'Dalam Tahap Project',
                        'Pending' => 'Pending',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->hiddenLabel()->tooltip('Detail'),
                Tables\Actions\EditAction::make()->hiddenLabel()->tooltip('Edit'),
                Tables\Actions\Action::make('packingSlip')
                    ->label('Packing Slip')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('warning')
                    ->tooltip('Packing Slip')
                    ->url(fn (Project $record) => PackingSlip::getUrl([$record->id])),
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

    public static function getNavigationGroup(): ?string
    {
        return __("Project");
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProjects::route('/'),
            'create' => Pages\CreateProject::route('/create'),
            'edit' => Pages\EditProject::route('/{record}/edit'),
            'view' => Pages\ViewProject::route('/{record}'),
            'packing-slip' => Pages\PackingSlip::route('/{record}/packing-slip'),
        ];
    }

}
