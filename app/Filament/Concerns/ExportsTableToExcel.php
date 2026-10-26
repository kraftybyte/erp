<?php

namespace App\Filament\Concerns;

use Closure;
use Filament\Actions\Action;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Indicator;

/**
 * Tombol "Export Excel" di halaman daftar. Yang diekspor adalah baris yang
 * sedang tampil di tabel (filter, pencarian, urutan), bukan seluruh data.
 */
trait ExportsTableToExcel
{
    /**
     * @param  Closure(\Illuminate\Database\Eloquent\Builder, array<int, string>): string  $build
     *         membuat file dari query tabel dan keterangan filternya, mengembalikan path-nya
     */
    protected function excelExportAction(string $name, Closure $build): Action
    {
        return Action::make('export')
            ->label('Export Excel')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->action(fn () => response()
                ->download(
                    $build($this->getFilteredSortedTableQuery(), $this->activeFilterLabels()),
                    $name . ' ' . now()->format('Y-m-d') . '.xlsx'
                )
                ->deleteFileAfterSend());
    }

    /**
     * @return array<int, string> mis. ["Status: Dibayarkan", "Dari 1 Okt 2026", "Cari \"grab\""]
     */
    protected function activeFilterLabels(): array
    {
        $labels = collect($this->getTable()->getFilters())
            ->flatMap(fn (BaseFilter $filter) => $filter->getIndicators())
            ->map(fn ($indicator) => strip_tags((string) ($indicator instanceof Indicator ? $indicator->getLabel() : $indicator)));

        if (filled($search = $this->getTableSearch())) {
            $labels->push("Cari \"{$search}\"");
        }

        return $labels->values()->all();
    }
}
