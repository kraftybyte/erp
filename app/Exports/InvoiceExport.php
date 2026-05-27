<?php

namespace App\Exports;

use App\Models\Invoice;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Illuminate\Database\Eloquent\Builder;

class InvoiceExport implements FromQuery, WithHeadings, WithMapping
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
        return Invoice::query()
            ->whereYear('created_at', $this->year)
            ->whereMonth('created_at', $this->month)
            ->orderBy('created_at', 'desc');
    }

    public function headings(): array
    {
        return [
            'ID',
            'UUID',
            'Nama Perusahaan',
            'Type',
            'Status',
            'Sub Total',
            'Total',
            'Paid',
            'Due Date',
            'Tanggal Dibuat',
        ];
    }

    public function map($invoice): array
    {
        return [
            $invoice->id,
            $invoice->uuid,
            $invoice->nama_perusahaan,
            $invoice->type,
            $invoice->status,
            'Rp ' . number_format($invoice->sub_total ?? 0, 0, ',', '.'),
            'Rp ' . number_format($invoice->total ?? 0, 0, ',', '.'),
            'Rp ' . number_format($invoice->paid ?? 0, 0, ',', '.'),
            $invoice->due_date?->format('d M Y'),
            $invoice->created_at?->format('d M Y H:i'),
        ];
    }
}
