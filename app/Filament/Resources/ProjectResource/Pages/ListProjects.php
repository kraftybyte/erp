<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Resources\ProjectResource;
use App\Models\Project;
use App\Services\ProjectProfit;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;

    /** Laba semua project, dihitung sekali per request untuk kolom-kolom keuangan. */
    protected ?array $profits = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function profit(Project $project): ?array
    {
        $this->profits ??= app(ProjectProfit::class)->summaries();

        return $this->profits[$project->getKey()] ?? null;
    }
}
