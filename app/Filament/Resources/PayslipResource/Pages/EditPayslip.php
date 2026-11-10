<?php

namespace App\Filament\Resources\PayslipResource\Pages;

use App\Filament\Resources\PayslipResource;
use App\Models\Payslip;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPayslip extends EditRecord
{
    protected static string $resource = PayslipResource::class;

    public function getTitle(): string
    {
        return 'Slip gaji ' . $this->record->periodLabel();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('pdf')
                ->label('Unduh PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->url(fn () => route('payslip.pdf', $this->record))
                ->openUrlInNewTab(),
            PayslipResource::finalizeAction(Actions\Action::make('finalize'))
                ->after(fn () => $this->refreshFormData(['items', 'notes'])),
            PayslipResource::payAction(Actions\Action::make('pay')),
        ];
    }

    /** Slip yang sudah final hanya dilihat; tombol Simpan disembunyikan. */
    protected function getFormActions(): array
    {
        return $this->record->isDraft() ? parent::getFormActions() : [];
    }

    protected function afterSave(): void
    {
        /** @var Payslip $slip */
        $slip = $this->record;
        $slip->recalculate();
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->getResource()::getUrl('index');
    }
}
