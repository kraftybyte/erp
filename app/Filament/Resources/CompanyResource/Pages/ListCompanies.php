<?php

namespace App\Filament\Resources\CompanyResource\Pages;

use App\Filament\Resources\CompanyResource;
use App\Services\CompanyDirectory;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCompanies extends ListRecords
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function getSubheading(): ?string
    {
        $count = count(app(CompanyDirectory::class)->suspectedDuplicateIds());

        return $count
            ? "{$count} perusahaan namanya mirip perusahaan lain. Aktifkan filter \"Dugaan duplikat\" untuk memeriksa dan menggabungkannya."
            : null;
    }
}
