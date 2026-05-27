<?php

namespace App\Exports;

use App\Models\Project;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Illuminate\Database\Eloquent\Builder;

class ProjectExport implements FromQuery, WithHeadings, WithMapping
{
    public function query(): Builder
    {
        return Project::query()
            ->with(['deal', 'product'])
            ->orderBy('created_at', 'desc');
    }

    public function headings(): array
    {
        return [
            'ID',
            'Nama Project',
            'Client',
            'Produk',
            'Timeline Mulai',
            'Timeline Selesai',
            'PIC',
            'Alamat',
            'Tanggal Dibuat',
        ];
    }

    public function map($project): array
    {
        return [
            $project->id,
            $project->nama_project,
            $project->deal ? $project->deal->nama_perusahaan : '-',
            $project->product ? $project->product->nama_layanan : '-',
            $project->timeline_mulai?->format('d M Y'),
            $project->timeline_selesai?->format('d M Y'),
            $project->nama_pic ?? '-',
            $project->alamat ?? '-',
            $project->created_at?->format('d M Y H:i'),
        ];
    }
}
