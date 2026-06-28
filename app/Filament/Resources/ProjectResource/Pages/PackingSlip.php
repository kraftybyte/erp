<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Resources\ProjectResource;
use App\Models\Project;
use App\Settings\GeneralSettings;
use Filament\Resources\Pages\Page;
use Filament\Actions\Action;
use Filament\Forms\Set;

class PackingSlip extends Page
{
    protected static string $resource = ProjectResource::class;

    protected static string $view = 'filament.resources.project-resource.pages.packing-slip';

    public int $projectId = 0;
    public ?Project $record = null;
    public string $qrUrl = '';

    public function mount(int | Project $record): void
    {
        // Handle both integer ID and Model object
        if ($record instanceof Project) {
            $this->projectId = $record->id;
            $this->record = $record->load('deal');
        } else {
            $this->projectId = (int) $record;
            $this->record = Project::with('deal')->findOrFail($this->projectId);
        }

        // Generate QR URL
        $settings = app(GeneralSettings::class);
        $baseUrl = $settings->packing_qr_url_prefix ?? config('app.url');
        $this->qrUrl = rtrim($baseUrl, '/') . '/admin/projects/' . $this->projectId;
    }

    public function getSenderProperty(): array
    {
        $settings = app(GeneralSettings::class);
        return [
            'name' => $this->record->sender_name ?? $settings->inv_company_name ?? '-',
            'address' => $this->record->sender_address ?? $settings->inv_company_address ?? '-',
            'phone' => $this->record->sender_phone ?? $settings->inv_company_phone ?? '-',
        ];
    }

    public function getRecipientProperty(): array
    {
        $deal = $this->record->deal;
        return [
            'name' => $this->record->recipient_name ?? ($deal->nama_perusahaan ?? '-'),
            'address' => $this->record->recipient_address ?? ($deal->alamat ?? '-'),
            'phone' => $this->record->recipient_phone ?? ($deal->telephone ?? $deal->nama_pic ?? '-'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('printThermal')
                ->label('Print Thermal')
                ->icon('heroicon-o-printer')
                ->color('success')
                ->action('printThermal'),
        ];
    }

    public function printThermal(): void
    {
        $this->dispatch('print-thermal');
    }
}
