<?php

namespace App\Exports;

use App\Models\User;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Illuminate\Database\Eloquent\Builder;

class EmployeeExport implements FromQuery, WithHeadings, WithMapping
{
    public function query(): Builder
    {
        return User::query()
            ->with('roles')
            ->orderBy('created_at', 'desc');
    }

    public function headings(): array
    {
        return [
            'ID',
            'NIK',
            'Nama',
            'Email',
            'Telephone',
            'Position',
            'Division',
            'Roles',
            'Email Verified',
            'Tanggal Bergabung',
        ];
    }

    public function map($user): array
    {
        return [
            $user->id,
            $user->nik ?? '-',
            $user->firstname . ' ' . $user->lastname,
            $user->email,
            $user->phone ?? '-',
            $user->position ?? '-',
            $user->division ?? '-',
            $user->roles->pluck('name')->map(function ($name) {
                return ucfirst($name);
            })->implode(', '),
            $user->email_verified_at ? 'Yes' : 'No',
            $user->created_at?->format('d M Y'),
        ];
    }
}
