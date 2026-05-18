<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TaskResource\Pages;
use App\Helpers\ColorHelper;
use App\Models\Task;
use App\Models\User;
use App\Models\Project;
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class TaskResource extends Resource implements HasShieldPermissions
{
    protected static ?string $model = Task::class;
    protected static ?string $navigationGroup = 'Project';
    protected static ?string $navigationLabel = 'List Task';
    protected static bool $shouldRegisterNavigation = false;

    public static function getPermissionPrefixes(): array
    {
        return [
            'view',
            'view_any',
            'create',
            'update',
            'delete',
            'delete_any',
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Tabs::make('Task Details')
                ->tabs([
                    Tabs\Tab::make('Informasi Umum')
                        ->schema([
                            Forms\Components\Hidden::make('creator_id')
                                ->required()
                                ->default(fn() => auth()->id()),
                            Forms\Components\TextInput::make('nama_task')
                                ->label('Judul Task')
                                ->required()
                                ->helperText('Contoh: Editing Video atau Pembuatan Content Plan.'),
                            Forms\Components\Select::make('project_id')
                                ->label('Nama Klien')
                                ->searchable()
                                ->relationship('project', 'nama_perusahaan')
                                ->required()
                                ->reactive()
                                ->helperText(function ($state) {
                                    if (!$state) {
                                        return 'Klien belum ada';
                                    }
                                    $project = Project::find($state);
                                    return $project ? null : 'Klien belum ada';
                                })
                                ->afterStateUpdated(function ($state, callable $set) {
                                    if ($state) {
                                        $project = Project::find($state);
                                        $set('nama_layanan', $project ? $project->nama_project : '');
                                    } else {
                                        $set('nama_layanan', '');
                                    }
                                }),
                            Forms\Components\Select::make('nama_layanan')
                                ->label('Layanan')
                                ->required()
                                ->searchable()
                                ->reactive()
                                ->options(function (callable $get) {
                                    $projectId = $get('project_id');
                                    if ($projectId) {
                                        $project = Project::find($projectId);
                                        return $project ? [$project->nama_project => $project->nama_project] : [];
                                    }
                                    return [];
                                }),
                        ])
                        ->columnSpanFull(),

                    Tabs\Tab::make('Tim & Status')
                        ->schema([
                            Forms\Components\Select::make('user_id')
                                ->label('Tambah Team')
                                ->multiple()
                                ->searchable()
                                ->options(User::all()->pluck('firstname', 'id'))
                                ->disabled(fn ($record) => $record && !Gate::allows('update_task', $record))
                                ->afterStateHydrated(function ($state, callable $set, $record) {
                                    if (!$state && $record) {
                                        $set('user_id', $record->users->pluck('id')->toArray());
                                    }
                                })
                                ->saveRelationshipsUsing(function ($state, $record) {
                                    $record->users()->sync($state);
                                }),
                            Forms\Components\Select::make('status')
                                ->label('Status')
                                ->options([
                                    'Log' => 'Log',
                                    'Process' => 'Process',
                                    'Review' => 'Review',
                                    'Done' => 'Done',
                                    'Hold' => 'Hold',
                                ])
                                ->required(),
                            Forms\Components\Select::make('proritas')
                                ->label('Proritas')
                                ->required()
                                ->options([
                                    'Urgent' => 'Urgent',
                                    'Normal' => 'Normal',
                                    'Low' => 'Low',
                                ])
                                ->default('Normal'),
                        ])
                        ->columnSpanFull(),

                    Tabs\Tab::make('Detail & Jadwal')
                        ->schema([
                            Forms\Components\RichEditor::make('detail_task')
                                ->label('Detail Task')
                                ->helperText('Tulis dengan detail.')
                                ->maxLength(1000)
                                ->required(),
                            Forms\Components\DatePicker::make('mulai_task')
                                ->label('Tanggal Mulai')
                                ->required()
                                ->format('Y-m-d'),
                            Forms\Components\DatePicker::make('selesai_task')
                                ->label('Tanggal Selesai')
                                ->required()
                                ->format('Y-m-d'),
                        ])
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $user = auth()->user();
                if ($user->hasRole(['Director', 'Finance', 'super_admin'])) {
                    return $query->with(['project', 'users']);
                }
                return $query->whereHas('users', function (Builder $q) use ($user) {
                    $q->where('users.id', $user->id);
                })->with(['project', 'users']);
            })
            ->columns([
                Tables\Columns\TextColumn::make('nama_task')
                    ->searchable()
                    ->limit(30)
                    ->label('Judul Task'),
                Tables\Columns\TextColumn::make('project.nama_perusahaan')
                    ->searchable()
                    ->limit(30)
                    ->sortable()
                    ->label('Nama Klien'),
                Tables\Columns\TextColumn::make('nama_layanan')
                    ->searchable()
                    ->label('Jenis Layanan')
                    ->badge()
                    ->sortable()
                    ->color(fn (string $state): string => ColorHelper::status($state)),
                Tables\Columns\TextColumn::make('proritas')
                    ->searchable()
                    ->badge()
                    ->sortable()
                    ->color(fn (string $state): string => match ($state) {
                        'Urgent' => 'danger',
                        'Low' => 'gray',
                        'Normal' => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('status')
                    ->searchable()
                    ->label('Status Task')
                    ->badge(),
                Tables\Columns\TextColumn::make('mulai_task')
                    ->searchable()
                    ->label('Tanggal Dibuat')
                    ->sortable()
                    ->formatStateUsing(fn($state) => Carbon::parse($state)->locale('id')->isoFormat('dddd, D MMMM YYYY')),
                Tables\Columns\TextColumn::make('selesai_task')
                    ->searchable()
                    ->label('Task Selesai')
                    ->sortable()
                    ->formatStateUsing(function ($state, $record) {
                        $now = Carbon::now();
                        $deadline = Carbon::parse($state);

                        if ($record->status === 'Done') {
                            return 'Selesai';
                        }

                        if ($deadline->isValid()) {
                            $diffInDays = $now->diffInDays($deadline, false);
                            $roundedDiff = round($diffInDays);

                            if ($roundedDiff < 0) {
                                return abs($roundedDiff) . ' hari terlambat';
                            } elseif ($roundedDiff == 0) {
                                return 'Hari ini';
                            } else {
                                return $roundedDiff . ' hari lagi';
                            }
                        }
                        return 'Tanggal tidak valid';
                    }),
                Tables\Columns\ImageColumn::make('team_avatar')
                    ->label('Team')
                    ->circular()
                    ->stacked()
                    ->limit(3)
                    ->limitedRemainingText('+{remaining}')
                    ->getStateUsing(function ($record) {
                        return $record->users->map(function ($user) {
                            $avatarUrl = $user->getFirstMediaUrl('avatars');
                            if ($avatarUrl) {
                                return $avatarUrl;
                            }
                            $name = trim($user->firstname . ' ' . $user->lastname);
                            $initials = strtoupper(substr($name, 0, 1) . substr($name, -1));
                            return "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' width='100' height='100' viewBox='0 0 100 100'>
                                <rect width='100%' height='100%' fill='%23737474'/>
                                <text x='50%' y='50%' font-size='40' font-family='Arial' fill='white' text-anchor='middle' dominant-baseline='central'>$initials</text>
                            </svg>";
                        })->toArray();
                    })
                    ->alignCenter(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('user_division')
                    ->label('Divisi')
                    ->options(
                        User::whereNotNull('division')
                            ->pluck('division', 'division')
                            ->unique()
                            ->toArray()
                    ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->hiddenLabel()->tooltip('Detail'),
                Tables\Actions\EditAction::make()
                    ->hidden(fn ($record) => $record && !filament()->auth()->user()->can('update_task', $record)),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ])
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTasks::route('/'),
            'create' => Pages\CreateTask::route('/create'),
            'edit' => Pages\EditTask::route('/{record}/edit'),
        ];
    }
}
