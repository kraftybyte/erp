<?php

namespace App\Exports;

use App\Models\Leads;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Illuminate\Database\Eloquent\Builder;

class LeadExport implements FromQuery, WithHeadings, WithMapping
{
    public function query(): Builder
    {
        return Leads::query()
            ->with('product')
            ->orderBy('created_at', 'desc');
    }

    public function headings(): array
    {
        return [
            'ID',
            'Nama PIC',
            'Nama Perusahaan',
            'Email',
            'Telephone',
            'Source',
            'Status',
            'Produk',
            'Tanggal Dibuat',
        ];
    }

    public function map($lead): array
    {
        return [
            $lead->id,
            $lead->nama_pic,
            $lead->nama_perusahaan,
            $lead->email,
            $lead->telephone,
            $lead->source,
            $lead->status,
            $lead->product->pluck('nama_layanan')->implode(', '),
            $lead->created_at?->format('d M Y H:i'),
        ];
    }
}
