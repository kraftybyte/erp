<?php

namespace App\Filament\Filters;

use Filament\Forms;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Filter periode tanggal pengajuan (submission_date) untuk tabel Expense dan
 * Advance, sehingga export Excel bisa per bulan atau rentang tertentu.
 */
class SubmissionDateFilter
{
    public static function make(): Filter
    {
        return Filter::make('submission_date')
            ->label('Tanggal Pengajuan')
            ->form([
                Forms\Components\DatePicker::make('from')->label('Dari Tanggal'),
                Forms\Components\DatePicker::make('until')->label('Sampai Tanggal'),
            ])
            ->query(fn (Builder $query, array $data) => $query
                ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('submission_date', '>=', $date))
                ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('submission_date', '<=', $date)))
            ->indicateUsing(fn (array $data) => array_values(array_filter([
                filled($data['from'] ?? null) ? 'Dari ' . Carbon::parse($data['from'])->locale('id')->translatedFormat('j M Y') : null,
                filled($data['until'] ?? null) ? 'Sampai ' . Carbon::parse($data['until'])->locale('id')->translatedFormat('j M Y') : null,
            ])));
    }
}
