<?php

namespace App\Filament\Resources\DealResource\RelationManagers;

use App\Helpers\ColorHelper;
use Filament\Forms;
use Carbon\Carbon;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\RichEditor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Filament\Tables;
use Filament\Tables\Table;
use App\Models\User;
use App\Models\Task;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\DateTimeColumn;
use Filament\Forms\Components\Tabs;
use App\Models\Project;
use Filament\Forms\Components\Select;
use App\Services\WablasService;

class TaskRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Tabs::make('Task Details')
                ->tabs([
                    // Tab 1: Informasi Umum
                    Tabs\Tab::make('Informasi Umum')
                        ->schema([
                            Forms\Components\Hidden::make('creator_id')
                                ->required()
                                ->default(fn() => auth()->id()),
                            Forms\Components\TextInput::make('nama_task')
                                ->label('Judul Task')
                                ->required()
                                ->helperText('Contoh: Editing Video atau Pembuatan Content Plan.')
                                ->disabled(fn ($record) => $record && !Gate::allows('edit-task', $record)), // Non-editable jika tidak memiliki akses edit

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
                                })
                                ->disabled(fn ($record) => $record && !Gate::allows('edit-task', $record)),

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
                                })
                                ->disabled(fn ($record) => $record && !Gate::allows('edit-task', $record)), // Non-editable jika tidak memiliki akses edit
                        ])
                        ->columnSpanFull(),

                    // Tab 2: Tim dan Status
                    Tabs\Tab::make('Tim & Status')
                        ->schema([
                            Forms\Components\Select::make('user_id')
                                ->label('Tambah Team')
                                ->multiple()
                                ->searchable()
                                ->options(User::all()->pluck('firstname', 'id'))
                                ->disabled(fn ($record) => $record && !Gate::allows('edit-task', $record))
                                ->afterStateHydrated(function ($state, callable $set, $record) {
                                    // Pastikan user_id langsung terisi ketika form pertama kali dimuat
                                    if (!$state && $record) {
                                        // Set nilai user_id pertama kali
                                        $set('user_id', $record->users->pluck('id')->toArray());
                                    }
                                })
                                ->saveRelationshipsUsing(function ($state, $record) {
                                    // Simpan user_id ke dalam database
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
                                ->required()
                                ->disabled(fn ($record) => $record && !Gate::allows('edit-task', $record)), // Non-editable jika tidak memiliki akses edit

                                Forms\Components\Select::make('proritas')
                                ->label('Proritas')
                                ->required()
                                ->options([
                                    'Urgent' => 'Urgent',
                                    'Normal' => 'Normal',
                                    'Low' => 'Low',
                                ])
                                ->default('Normal')
                                ->disabled(fn ($record) => $record && !Gate::allows('edit-task', $record)), // Non-editable jika tidak memiliki akses edit
                        ])
                        ->columnSpanFull(),

                    // Tab 3: Detail dan Jadwal
                    Tabs\Tab::make('Detail & Jadwal')
                        ->schema([
                            Forms\Components\RichEditor::make('detail_task')
                                ->label('Detail Task')
                                ->helperText('Tulis dengan detail.')
                                ->maxLength(1000)
                                ->required()
                                ->disabled(fn ($record) => $record && !Gate::allows('edit-task', $record)), // Non-editable jika tidak memiliki akses edit

                                Forms\Components\DatePicker::make('mulai_task')
                                ->label('Tanggal Mulai')
                                ->required()
                                ->format('Y-m-d')
                                ->disabled(fn ($record) => $record && !Gate::allows('edit-task', $record)), // Non-editable jika tidak memiliki akses edit

                                Forms\Components\DatePicker::make('selesai_task')
                                ->label('Tanggal Selesai')
                                ->required()
                                ->format('Y-m-d')
                                ->disabled(fn ($record) => $record && !Gate::allows('edit-task', $record)), // Non-editable jika tidak memiliki akses edit
                        ])
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('task_id')
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
                ->color(ColorHelper::status(...)),
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
                ->formatStateUsing(function ($state) {
                    return Carbon::parse($state)->locale('id')->isoFormat('dddd, D MMMM YYYY');
                }),
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
                        } else {
                            return 'Tanggal tidak valid';
                        }
                    }),
            
            Tables\Columns\TextColumn::make('users.firstname')
                ->label('List Team')
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\ImageColumn::make('users.avatar')
            ->label('Team')
            ->circular() // Membuat gambar berbentuk lingkaran
            ->stacked() // Menampilkan avatar secara bertumpuk
            ->limit(3) // Maksimal 3 avatar ditampilkan
            ->limitedRemainingText('+{remaining}') // Menampilkan jumlah avatar tersembunyi
            ->getStateUsing(function ($record) {
                return $record->users->map(function ($user) {
                    // Cek apakah user memiliki avatar
                    $avatarUrl = $user->getFirstMediaUrl('avatars');
                    
                    if ($avatarUrl) {
                        return $avatarUrl;
                    }

                    // Jika tidak ada avatar, buat inisial (huruf pertama & terakhir)
                    $name = trim($user->firstname . ' ' . $user->lastname);
                    $initials = strtoupper(substr($name, 0, 1) . substr($name, -1));

                    // Gunakan avatar teks dengan Tailwind (harus diimplementasikan di frontend)
                    return "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' width='100' height='100' viewBox='0 0 100 100'>
                        <rect width='100%' height='100%' fill='%23737474'/>
                        <text x='50%' y='50%' font-size='40' font-family='Arial' fill='white' text-anchor='middle' dominant-baseline='central'>$initials</text>
                    </svg>";
                })->toArray();
            })
            ->alignCenter(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
 
            ])
            ->actions([
            ])
            ->bulkActions([
            ]);
    }
}
