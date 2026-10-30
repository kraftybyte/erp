<?php

namespace App\Filament\Resources\AdvanceResource\Pages;

use App\Filament\Resources\AdvanceResource;
use App\Helpers\ClientLabel;
use App\Models\Advance;
use App\Services\RealisasiService;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewAdvance extends ViewRecord
{
    protected static string $resource = AdvanceResource::class;

    protected static string $view = 'filament.resources.advance-resource.pages.view-advance';

    /** Sudah dicairkan dan belum ditutup: realisasinya masih bisa diubah. */
    private const OPEN_STATUSES = [Advance::STATUS_DIBAYARKAN, Advance::STATUS_REALIZING, Advance::STATUS_REALIZED_USER, Advance::STATUS_READY_TO_CLOSE];

    public function getTitle(): string
    {
        return 'Advance #' . $this->record->id;
    }

    public function getSubheading(): ?string
    {
        return collect([$this->record->keterangan_advance, $this->record->project ? ClientLabel::project($this->record->project) : null])
            ->filter()
            ->implode(' · ');
    }

    protected function getHeaderActions(): array
    {
        $open = fn () => in_array($this->record->status, self::OPEN_STATUSES, true);

        return [
            Actions\EditAction::make()->color('gray'),

            // Dulu tombolnya hanya muncul untuk "Dibayarkan" dan status lama
            // "Sudah di-Realisasi User", jadi advance yang sedang direalisasi
            // tidak punya jalan ke halaman realisasinya.
            Actions\Action::make('realisasi')
                ->label(fn () => $open() ? 'Kelola Realisasi' : 'Lihat Realisasi')
                ->icon('heroicon-o-receipt-percent')
                ->color(fn () => $open() ? 'primary' : 'gray')
                ->visible(fn () => $open() || $this->record->status === Advance::STATUS_SELESAI)
                ->url(fn () => AdvanceResource::getUrl('realisasi', ['record' => $this->record])),
        ];
    }

    protected function getViewData(): array
    {
        $record    = $this->record->loadMissing(['user', 'paidBy', 'paymentAccount', 'project.deal']);
        $service   = app(RealisasiService::class);
        $paid      = (float) $record->paid;
        $realisasi = $paid > 0 ? $service->getTotalRealisasi($record) : 0.0;

        return [
            'requester' => $record->user?->fullName ?? '-',
            'paid'      => $paid,
            'realisasi' => $realisasi,
            'remaining' => $paid - $realisasi,
            'progress'  => $paid > 0 ? $service->getProgressPercentage($record) : 0.0,
            'batches'   => $paid > 0 ? $service->batchesForDisplay($record) : [],
            'project'   => $record->project ? ClientLabel::project($record->project) : null,
            'emptyText' => match (true) {
                $paid <= 0                                   => 'Realisasi bisa dicatat setelah advance dicairkan.',
                $record->status === Advance::STATUS_SELESAI  => 'Advance ini diselesaikan tanpa batch realisasi.',
                default                                      => 'Belum ada pengeluaran yang dicatat. Catat lewat tombol Kelola Realisasi di atas.',
            },
        ];
    }
}
