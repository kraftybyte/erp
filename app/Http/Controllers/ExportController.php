<?php

namespace App\Http\Controllers;

use App\Exports\ExpenseExport;
use App\Exports\LeadExport;
use App\Exports\InvoiceExport;
use App\Exports\TaskExport;
use App\Exports\EmployeeExport;
use App\Exports\ProjectExport;
use Maatwebsite\Excel\Facades\Excel;

class ExportController extends Controller
{
    public function expenses(int $year = null, int $month = null)
    {
        $year = $year ?? now()->year;
        $month = $month ?? now()->month;

        return Excel::download(
            new ExpenseExport($year, $month),
            "expenses-{$year}-{$month}.xlsx"
        );
    }

    public function leads()
    {
        return Excel::download(
            new LeadExport(),
            'leads-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function invoices(int $year = null, int $month = null)
    {
        $year = $year ?? now()->year;
        $month = $month ?? now()->month;

        return Excel::download(
            new InvoiceExport($year, $month),
            "invoices-{$year}-{$month}.xlsx"
        );
    }

    public function tasks()
    {
        return Excel::download(
            new TaskExport(),
            'tasks-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function employees()
    {
        return Excel::download(
            new EmployeeExport(),
            'employees-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function projects()
    {
        return Excel::download(
            new ProjectExport(),
            'projects-' . now()->format('Y-m-d') . '.xlsx'
        );
    }
}
