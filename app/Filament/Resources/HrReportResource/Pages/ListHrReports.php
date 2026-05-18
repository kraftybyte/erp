<?php

namespace App\Filament\Resources\HrReportResource\Pages;

use App\Filament\Resources\HrReportResource;
use Filament\Resources\Pages\ListRecords;

class ListHrReports extends ListRecords
{
    protected static string $resource = HrReportResource::class;

    protected function getHeaderActions(): array { return []; }

    public function getTitle(): string { return 'HR Performance Report'; }
}
