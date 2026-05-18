<?php

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Filament\Resources\EmployeeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        $user = Auth::user();

        if ($user->hasRole('super_admin')) {
            return [
                Actions\CreateAction::make(),
            ];
        }

        return [];
    }

    protected function getTableQuery(): ?\Illuminate\Database\Eloquent\Builder
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
