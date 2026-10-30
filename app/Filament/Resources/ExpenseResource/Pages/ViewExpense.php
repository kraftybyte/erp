<?php

namespace App\Filament\Resources\ExpenseResource\Pages;

use App\Filament\Resources\ExpenseResource;
use App\Helpers\ClientLabel;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewExpense extends ViewRecord
{
    protected static string $resource = ExpenseResource::class;

    protected static string $view = 'filament.resources.expense-resource.pages.view-expense';

    public function getTitle(): string
    {
        return 'Expense #' . $this->record->id;
    }

    public function getSubheading(): ?string
    {
        return $this->record->keterangan_expense;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->color('gray'),
        ];
    }

    protected function getViewData(): array
    {
        $record = $this->record->loadMissing(['user', 'paidBy', 'approvedBy', 'paymentAccount', 'project.deal', 'itemExpenses']);

        return [
            'project' => $record->project ? ClientLabel::project($record->project) : null,
        ];
    }
}
