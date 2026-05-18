<?php

namespace App\Filament\Resources\DealResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Components\DatePicker;
use Carbon\Carbon;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Components\Select;

class ProjectRelationManager extends RelationManager
{
    protected static string $relationship = 'project';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('id')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('deal_id')
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

            Tables\Columns\TextColumn::make('deal.product.nama_layanan')
                ->searchable()
                ->label('Jenis Layanan')
                ->badge('success'),
            Tables\Columns\TextColumn::make('deal.status')
                ->searchable()
                ->label('Status')
                ->badge()->color(fn (string $state): string => match ($state) {
                    'Negosiasi' => 'gray',
                    'Menunggu Pembayaran' => 'warning',
                    'Dalam Tahap Project' => 'success',
                    'Selesai' => 'danger',
                }),
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
