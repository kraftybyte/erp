<?php

namespace App\Exports;

use App\Models\Expense;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Illuminate\Database\Eloquent\Builder;

class ExpenseExport implements FromQuery, WithHeadings, WithMapping
{
    protected int $year;
    protected int $month;

    public function __construct(int $year = null, int $month = null)
    {
        $this->year = $year ?? now()->year;
        $this->month = $month ?? now()->month;
    }

    public function query(): Builder
    {
        return Expense::query()
            ->with(['user', 'project', 'paidBy'])
            ->whereYear('created_at', $this->year)
            ->whereMonth('created_at', $this->month)
            ->orderBy('created_at', 'desc');
    }

    public function headings(): array
    {
        return [
            'ID',
            'User',
            'Project',
            'Tanggal Pengajuan',
            'Keterangan',
            'Nominal',
            'Status',
            'Paid By',
            'Paid Amount',
            'Reference',
            'Created At',
        ];
    }

    public function map($expense): array
    {
        return [
            $expense->id,
            $expense->user ? $expense->user->firstname . ' ' . $expense->user->lastname : '-',
            $expense->project ? $expense->project->nama_project : '-',
            $expense->submission_date?->format('d M Y'),
            $expense->keterangan_expense,
            'Rp ' . number_format($expense->amount, 0, ',', '.'),
            $expense->status,
            $expense->paidBy ? $expense->paidBy->firstname : '-',
            $expense->paid ? 'Rp ' . number_format($expense->paid, 0, ',', '.') : '-',
            $expense->reference ?? '-',
            $expense->created_at?->format('d M Y H:i'),
        ];
    }
}
