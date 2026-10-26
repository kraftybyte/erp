<?php

namespace App\Filament\Resources\ExpenseResource\Pages;

use App\Filament\Concerns\ExportsTableToExcel;
use App\Filament\Resources\ExpenseResource;
use App\Services\ExpenseAdvanceExport;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use App\Models\Expense;

class ListExpenses extends ListRecords
{
    use ExportsTableToExcel;

    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->excelExportAction('Expense', fn (Builder $query, array $filters) => app(ExpenseAdvanceExport::class)->expenses($query, $filters)),
            Actions\CreateAction::make(),
        ];
    }


}
