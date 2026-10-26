<?php

namespace App\Filament\Resources\AdvanceResource\Pages;

use App\Filament\Concerns\ExportsTableToExcel;
use App\Filament\Resources\AdvanceResource;
use App\Services\ExpenseAdvanceExport;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListAdvances extends ListRecords
{
    use ExportsTableToExcel;

    protected static string $resource = AdvanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->excelExportAction('Advance', fn (Builder $query, array $filters) => app(ExpenseAdvanceExport::class)->advances($query, $filters)),
            Actions\CreateAction::make(),
        ];
    }




}
