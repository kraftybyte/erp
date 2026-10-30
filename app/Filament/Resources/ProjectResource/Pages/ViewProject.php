<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Resources\ProjectResource;
use App\Services\ProjectProfit;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    protected static string $view = 'filament.resources.project-resource.pages.view-project';

    public function getTitle(): string
    {
        return $this->record->nama_perusahaan ?: ($this->record->deal?->nama_perusahaan ?: 'Project #' . $this->record->id);
    }

    public function getSubheading(): ?string
    {
        return $this->record->nama_project;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }

    protected function getViewData(): array
    {
        return [
            'tasks'   => $this->record->tasks()->with('users')->latest()->get(),
            'finance' => ProjectProfit::visibleTo(auth()->user()) ? app(ProjectProfit::class)->details($this->record) : null,
        ];
    }
}
