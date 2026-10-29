<?php

namespace App\Filament\Actions;

use App\Filament\Resources\ProjectResource;
use App\Helpers\ClientLabel;
use App\Models\Invoice;
use App\Services\ProjectFromInvoice;
use Filament\Actions\Action as PageAction;
use Filament\Actions\MountableAction;
use Filament\Forms;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;

/**
 * Tombol "Buat Project" di invoice (tabel dan halaman View): cukup pilih
 * Kategori Project, project langsung dibuat dari invoice. Bila project-nya
 * sudah ada, tombol yang sama membukanya.
 */
class ProjectFromInvoiceAction
{
    public static function table(): TableAction
    {
        return self::configure(TableAction::make('project'))->iconButton();
    }

    public static function page(): PageAction
    {
        return self::configure(PageAction::make('project'));
    }

    private static function configure(MountableAction $action): MountableAction
    {
        return $action
            ->label(fn (Invoice $record) => $record->projects->isNotEmpty() ? 'Lihat Project' : 'Buat Project')
            ->tooltip(fn (Invoice $record) => $record->projects->isNotEmpty() ? 'Lihat Project' : 'Buat Project')
            ->icon('heroicon-o-briefcase')
            ->color('primary')
            ->url(fn (Invoice $record) => ($project = $record->projects->first())
                ? ProjectResource::getUrl('view', ['record' => $project])
                : null)
            ->visible(fn (Invoice $record) => ($project = $record->projects->first())
                ? ProjectResource::canView($project)
                : $record->status !== 'cancelled' && ProjectResource::canCreate())
            ->modalHeading('Buat project dari invoice')
            ->modalDescription(fn (Invoice $record) => self::summary($record))
            ->modalSubmitActionLabel('Buat Project')
            ->form([
                Forms\Components\Select::make('nama_project')
                    ->label('Kategori Project')
                    ->options(fn () => ProjectResource::getProjectCategoryOptions())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (Invoice $record, array $data) {
                $project = ProjectFromInvoice::create($record, $data['nama_project']);
                $record->load('projects:id,invoice_id');

                Notification::make()
                    ->title('Project dibuat')
                    ->body(ClientLabel::project($project))
                    ->success()
                    ->actions([
                        NotificationAction::make('view')
                            ->label('Lihat Project')
                            ->url(ProjectResource::getUrl('view', ['record' => $project])),
                    ])
                    ->send();
            });
    }

    /**
     * Isi project yang akan dibuat, ditampilkan di modal.
     */
    private static function summary(Invoice $invoice): string
    {
        [$start, $end] = ProjectFromInvoice::timeline($invoice);

        $client = ProjectFromInvoice::deal($invoice)
            ? 'Client: ' . ClientLabel::invoice($invoice) . '.'
            : 'Client ' . ClientLabel::invoice($invoice) . ' belum ada di CRM dan akan dibuat otomatis.';

        return $client
            . ' Detail project diambil dari ' . $invoice->invoicesItems()->count() . ' item invoice, timeline '
            . $start->locale('id')->translatedFormat('j M Y') . ' – ' . $end->locale('id')->translatedFormat('j M Y')
            . ' (bisa diubah nanti di halaman project).';
    }
}
