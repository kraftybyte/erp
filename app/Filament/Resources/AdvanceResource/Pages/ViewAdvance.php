<?php

namespace App\Filament\Resources\AdvanceResource\Pages;

use App\Filament\Resources\AdvanceResource;
use App\Helpers\ClientLabel;
use App\Models\Advance;
use App\Services\AdvanceParallel;
use App\Services\RealisasiService;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewAdvance extends ViewRecord
{
    protected static string $resource = AdvanceResource::class;

    protected static string $view = 'filament.resources.advance-resource.pages.view-advance';

    public function getTitle(): string
    {
        return 'Advance #' . $this->record->id;
    }

    /**
     * Klien dan project saja; keterangan advance bisa sangat panjang (spesifikasi
     * lengkap pekerjaan), jadi tidak ditaruh di judul halaman.
     */
    public function getSubheading(): ?string
    {
        return $this->record->project ? ClientLabel::project($this->record->project) : 'Tanpa project';
    }

    protected function getHeaderActions(): array
    {
        // Sudah dicairkan (atau Disetujui untuk bayar paralel) dan belum ditutup.
        $open = fn () => AdvanceParallel::isOpen($this->record);

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
        $parallel  = $record->paysParallel();
        // Bayar paralel: realisasi boleh ada sebelum dibayar.
        $shown     = $paid > 0 || $parallel;
        $realisasi = $shown ? $service->getTotalRealisasi($record) : 0.0;

        return [
            'requester' => $record->user?->fullName ?? '-',
            'paid'      => $paid,
            'parallel'  => $parallel,
            'shown'     => $shown,
            'realisasi' => $realisasi,
            'remaining' => $paid - $realisasi,
            'progress'  => $shown ? $service->getProgressPercentage($record) : 0.0,
            'batches'   => $shown ? $service->batchesForDisplay($record) : [],
            'project'   => $record->project ? ClientLabel::project($record->project) : null,
            'emptyText' => match (true) {
                $record->status === Advance::STATUS_DISETUJUI && AdvanceParallel::allows($record) => 'Belum ada realisasi. Bayar paralel: struk pembelian bisa langsung dicatat lewat Kelola Realisasi, pembayarannya menyusul.',
                ! $shown                                     => 'Realisasi bisa dicatat setelah advance dicairkan.',
                $record->status === Advance::STATUS_SELESAI  => 'Advance ini diselesaikan tanpa batch realisasi.',
                default                                      => 'Belum ada pengeluaran yang dicatat. Catat lewat tombol Kelola Realisasi di atas.',
            },
        ];
    }
}
