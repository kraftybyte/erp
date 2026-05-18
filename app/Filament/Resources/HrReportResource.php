<?php

namespace App\Filament\Resources;

use App\Filament\Resources\HrReportResource\Pages;
use App\Models\User;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HrReportResource extends Resource
{
    protected static ?string $model           = User::class;
    protected static ?string $navigationIcon  = 'heroicon-o-chart-bar';
    protected static ?string $navigationLabel = 'HR Performance';
    protected static ?string $navigationGroup = 'HR';
    protected static ?int    $navigationSort  = 10;
    protected static ?string $slug            = 'hr-report';
    protected static ?string $recordRouteKeyName = 'id';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'Director', 'HR']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(
                User::query()
                    ->with(['roles'])
                    ->withCount([
                        'tasks as total_tasks',
                        'tasks as done_tasks' => fn ($q) => $q->where('tasks.status', 'Done'),
                        'tasks as late_tasks' => fn ($q) => $q->where('tasks.status', '!=', 'Done')
                                                               ->whereDate('tasks.selesai_task', '<', now()),
                    ])
                    ->withAvg(
                        ['tasks as avg_days' => fn ($q) => $q->where('tasks.status', 'Done')
                                                              ->whereNotNull('tasks.selesai_task')],
                        DB::raw('DATEDIFF(tasks.updated_at, tasks.mulai_task)')
                    )
            )
            ->columns([
                SpatieMediaLibraryImageColumn::make('media')
                    ->label('')
                    ->collection('avatars')
                    ->circular()
                    ->size(40),

                Tables\Columns\TextColumn::make('fullname')
                    ->label('Karyawan')
                    ->getStateUsing(fn ($record) => $record->firstname . ' ' . $record->lastname)
                    ->description(fn ($record) => $record->roles->pluck('name')
                        ->map(fn ($name) => Str::headline($name))->join(', '))
                    ->searchable(query: function (Builder $query, string $search) {
                        $query->where('firstname', 'like', "%{$search}%")
                              ->orWhere('lastname',  'like', "%{$search}%")
                              ->orWhere('email',     'like', "%{$search}%");
                    })
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('total_tasks')
                    ->label('Total Task')
                    ->alignCenter()->badge()->color('gray'),

                Tables\Columns\TextColumn::make('done_tasks')
                    ->label('Selesai')
                    ->alignCenter()->badge()->color('success'),

                Tables\Columns\TextColumn::make('late_tasks')
                    ->label('Terlambat')
                    ->alignCenter()->badge()
                    ->color(fn ($record) => ($record->late_tasks ?? 0) > 0 ? 'danger' : 'gray'),

                Tables\Columns\TextColumn::make('avg_days')
                    ->label('Avg Selesai')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => $state ? round($state, 1) . ' hari' : '-'),

                Tables\Columns\TextColumn::make('done_rate')
                    ->label('Completion')
                    ->alignCenter()
                    ->getStateUsing(fn ($record) => ($record->total_tasks ?? 0) > 0
                        ? round($record->done_tasks / $record->total_tasks * 100) . '%' : '-'),

                Tables\Columns\TextColumn::make('kpi')
                    ->label('KPI')
                    ->alignCenter()
                    ->getStateUsing(function ($record) {
                        $total = $record->total_tasks ?? 0;
                        $avg   = $record->avg_days    ?? 99;
                        $rate  = $total > 0 ? ($record->done_tasks / $total * 100) : 0;
                        $late  = $record->late_tasks  ?? 0;

                        return match (true) {
                            $total == 0               => 'No Data',
                            $avg <= 3  && $rate >= 90 => 'Outstanding',
                            $avg <= 7  && $rate >= 75 => 'Good',
                            $avg <= 14 && $rate >= 60 => 'Average',
                            $late > 5                 => 'Needs Review',
                            default                   => 'Below Average',
                        };
                    })
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'Outstanding'  => 'success',
                        'Good'         => 'info',
                        'Average'      => 'warning',
                        'Needs Review' => 'danger',
                        default        => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('roles')
                    ->relationship('roles', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Model $record) => Str::headline($record->name))
                    ->label('Role'),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('Detail')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->url(fn ($record) => '/admin/hr-report/' . $record->id),
            ])
            ->defaultSort('done_tasks', 'desc');
    }

    public static function getRelations(): array { return []; }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHrReports::route('/'),
            'view'  => Pages\ViewHrReport::route('/{record}'),
        ];
    }
}
