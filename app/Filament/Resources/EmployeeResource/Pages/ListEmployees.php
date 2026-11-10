<?php

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Filament\Concerns\ExportsTableToExcel;
use App\Filament\Resources\EmployeeResource;
use App\Services\EmployeeExport;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListEmployees extends ListRecords
{
    use ExportsTableToExcel;

    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Baris yang sedang tampil; kolom gaji hanya untuk yang boleh mengelola gaji.
            $this->excelExportAction('Data Karyawan', fn (Builder $query, array $filters) => app(EmployeeExport::class)->employees($query, $filters)),
            // Izin Shield create_user (UserPolicy), dulu hanya peran super_admin.
            Actions\CreateAction::make(),
        ];
    }

    protected function getTableQuery(): ?Builder
    {
        $query = parent::getTableQuery();
        $user = Auth::user();

        if (!$user->hasRole('super_admin')) {
            $query->whereDoesntHave('roles', function ($query) {
                $query->where('name', 'super_admin');
            });
        }

        return $query;
    }
}
