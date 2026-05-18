<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PerformanceIndicatorResource\Pages;
use App\Models\Task;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Filament\Tables\Actions\Action;
use App\Models\PerformanceIndicator;
use Carbon\Carbon;

class PerformanceIndicatorResource extends Resource
{
    protected static ?string $model = Task::class;
    protected static ?string $navigationGroup = 'Project';
    protected static ?int $navigationSort = 0;
    protected static ?string $navigationLabel = 'Performance Indicator';
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static bool $shouldRegisterNavigation = true;

public static function canAccess(): bool
{
    return auth()->user()->hasRole(['Director','Head', 'super_admin']);
}

public static function shouldRegisterNavigation(): bool
{
    return auth()->user()->hasRole(['Director','Head',  'super_admin']);
}

    public static function getBreadcrumb(): string
    {
        return 'Performance Indicator';
    }

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                //
            ]);
    }

    public static function table(Tables\Table $table): Tables\Table
{
    return $table
        ->modifyQueryUsing(fn(Builder $query) => $query->with(['users']))
        ->columns([
            Tables\Columns\TextColumn::make('nama_task')
                ->label('Task')
                ->sortable()
                ->searchable(),

            Tables\Columns\TextColumn::make('proritas')
                ->label('Prioritas')
                ->sortable(),

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
                    return $record->users
                        ->where('id', '!=', $record->creator_id) // Hindari creator_id
                        ->map(function ($user) {
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
                        })
                        ->toArray();
                })
                ->alignCenter(),

                Tables\Columns\TextColumn::make('selesai_task')
                ->searchable()
                ->label('Task Selesai')
                ->sortable()
                ->formatStateUsing(function ($state, $record) {
                    $createdAt = Carbon::parse($record->created_at);
                    $updatedAt = Carbon::parse($record->updated_at);
                    $now = Carbon::now();

                    // Jika task sudah selesai (status 'Done')
                    if ($record->status === 'Done') {
                        $diffInDays = intval($createdAt->diffInDays($updatedAt)); // Bilangan bulat
                        return "Selesai ({$diffInDays} hari)";
                    }

                    // Jika task belum selesai, hitung selisih dari created_at ke sekarang
                    $diffInDays = intval($createdAt->diffInDays($now, false)); // Pastikan bilangan bulat

                    if ($diffInDays < 0) {
                        return abs($diffInDays) . ' hari terlambat';
                    } elseif ($diffInDays == 0) {
                        return 'Hari ini';
                    } else {
                        return $diffInDays . ' hari lagi';
                    }
                }),

                
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()->hidden(),

                Action::make('Tambah Nilai')
                ->icon('heroicon-o-plus-circle')
                ->modalHeading('Tambah Nilai Kinerja')
                ->modalButton('Simpan')
                ->form([
                    Forms\Components\Repeater::make('performance_indicators')
                        ->label('Daftar Nilai')
                        ->schema([
                            Forms\Components\TextInput::make('deskripsi_pekerjaan')
                                ->label('Deskripsi Pekerjaan')
                                ->required(),

                            Forms\Components\TextInput::make('nilai')
                                ->label('Nilai')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(100)
                                ->required(),
                        ])
                        ->minItems(1)
                        ->addActionLabel('Tambah Penilaian'),
                ])
                ->action(function ($data, $record) {
                    foreach ($data['performance_indicators'] as $item) {
                        PerformanceIndicator::create([
                            'task_id' => $record->id,
                            'user_id' => auth()->id(),
                            'nilai' => $item['nilai'],
                            'deskripsi_pekerjaan' => $item['deskripsi_pekerjaan'],
                        ]);
                    }
                })
                ->successNotificationTitle('Nilai berhasil ditambahkan!')
                ->hidden(fn ($record) => auth()->id() !== $record->creator_id),

                Tables\Actions\DeleteAction::make(),

            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function canCreate(): bool
    {
        return auth()->user()->tasks()
            ->where('status', 'Done')
            ->doesntHave('performanceIndicator')
            ->exists();
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPerformanceIndicators::route('/'),
            'create' => Pages\CreatePerformanceIndicator::route('/create'),
        ];
    }
}
